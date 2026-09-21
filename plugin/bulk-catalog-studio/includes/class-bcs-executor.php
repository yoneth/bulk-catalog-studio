<?php
/**
 * Executor untuk bcs/execute-batch (spec §1.5, §5.3, §5.4).
 *
 * - Eksekusi per-row via BCS_Catalog_Writer dalam chunk 10 operasi, dengan
 *   heartbeat per chunk + guard waktu (sisanya dilanjutkan via resume).
 * - Idempotent: baris dengan (job_id, row_key, status ok) di audit dilewati.
 * - dry_run: validasi penuh tanpa efek samping, tanpa perubahan state.
 * - Status akhir: done | partially_failed | failed.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Executor
 */
class BCS_Executor {

	const CHUNK_SIZE = 10;

	/**
	 * Ambil baris plan mentah (alias publik untuk job manager).
	 *
	 * @param int $plan_id ID plan.
	 * @return object|null
	 */
	public static function get_plan_raw( $plan_id ) {
		return BCS_Plan_Builder::get_plan_row( $plan_id );
	}

	/**
	 * Jalankan plan.
	 *
	 * @param int  $plan_id ID plan.
	 * @param bool $dry_run Simulasi tanpa tulis.
	 * @param bool $resume  Lanjutkan job executing (stale/deferred).
	 * @return array|WP_Error Hasil spec §1.5.
	 */
	public static function execute( $plan_id, $dry_run = false, $resume = false ) {
		$guard = BCS_Catalog_Writer::guard();
		if ( is_wp_error( $guard ) ) {
			BCS_Audit_Logger::log_call( 'bcs/execute-batch', 'execute_batch', 'failed', $guard->get_error_message() );
			return $guard;
		}

		$plan_row = self::get_plan_raw( $plan_id );
		if ( ! $plan_row ) {
			return new WP_Error( 'bcs_not_found', __( 'Plan not found.', 'bulk-catalog-studio' ) );
		}

		$plan  = BCS_Plan_Builder::decode_plan( $plan_row );
		$job_id = absint( $plan_row->job_id );

		if ( empty( $plan['operations'] ) || ! is_array( $plan['operations'] ) ) {
			return new WP_Error( 'bcs_invalid_plan', __( 'Plan has no valid operations.', 'bulk-catalog-studio' ) );
		}

		/* ----- Dry run: validasi tanpa efek samping, tanpa state change ----- */
		if ( $dry_run ) {
			return self::dry_run( $plan, $job_id, $plan_id );
		}

		/* ----- State gate ----- */
		// begin_execution menangani dua jalur:
		// - job approved  → transisi normal ke executing;
		// - job executing → hanya lolos bila $resume dan (stale ATAU deferred).
		//   Catatan: $resume=false pada job executing akan ditolak begin_execution.
		$begin = BCS_Job_Manager::begin_execution( $plan_id, $resume );

		if ( is_wp_error( $begin ) ) {
			BCS_Audit_Logger::log_call( 'bcs/execute-batch', 'execute_batch', 'failed', $begin->get_error_message(), $job_id, $plan_id );
			return $begin;
		}

		$job     = $begin['job'];
		$resumed = (bool) $begin['resumed'];

		/* ----- Eksekusi chunked ----- */
		$started_at = microtime( true );

		// Coba perpanjang batas waktu PHP (host bisa menolak — abaikan bila gagal).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$budget = (float) BCS_Settings::get( 'execution_time_budget', 20.0 );

		$counts = array(
			'products_created'   => (int) $job->count_created,
			'products_updated'   => (int) $job->count_updated,
			'variations_created' => (int) $job->count_variations,
			'skipped'            => (int) $job->count_skipped,
			'failed'             => 0,
			'total_rows'         => count( $plan['operations'] ),
		);

		$failures = array();
		$any_ok   = false;
		$deferred = false;

		foreach ( array_values( $plan['operations'] ) as $i => $op ) {
			// Heartbeat + guard waktu per chunk.
			if ( 0 === $i % self::CHUNK_SIZE && $i > 0 ) {
				BCS_Job_Manager::heartbeat( $job_id );
			}

			if ( $i > 0 && 0 === $i % self::CHUNK_SIZE && ( microtime( true ) - $started_at ) > $budget ) {
				$deferred = true;
				break;
			}

			$row_index = isset( $op['row_index'] ) ? (int) $op['row_index'] : 0;
			$row_key   = isset( $op['row_key'] ) ? (string) $op['row_key'] : '';
			$sku       = isset( $op['payload']['sku'] ) ? (string) $op['payload']['sku'] : null;
			$action    = isset( $op['op'] ) ? (string) $op['op'] : '';

			// Idempotency resume (§5.4): baris yang sudah punya entri ok/skipped
			// dilewati TANPA menambah counter skipped (MINOR-4 retur QA-1 —
			// counter job sudah dipersist per chunk; skipped berarti
			// skip-duplikat, bukan "sudah diproses run sebelumnya"). Entri
			// failed tetap di-retry.
			if ( $resumed && '' !== $row_key && BCS_Audit_Logger::has_row_entry( $job_id, $row_key ) ) {
				continue;
			}

			if ( 'skip_duplicate' === $action ) {
				BCS_Audit_Logger::log_row(
					'bcs/execute-batch', 'skip_duplicate', 'skipped', $row_index,
					$sku, isset( $op['target_product_id'] ) ? (int) $op['target_product_id'] : null,
					__( 'Row skipped per plan (duplicate policy).', 'bulk-catalog-studio' ),
					$row_key, $job_id, $plan_id
				);
				$counts['skipped']++;
				continue;
			}

			// Validasi ringan + eksekusi.
			$validation = BCS_Catalog_Writer::validate_operation( $op );
			if ( is_wp_error( $validation ) ) {
				$failures[] = array(
					'row_index' => $row_index,
					'sku'       => $sku,
					'reason'    => $validation->get_error_message(),
				);
				BCS_Audit_Logger::log_row(
					'bcs/execute-batch', $action, 'failed', $row_index, $sku, null,
					$validation->get_error_message(), $row_key, $job_id, $plan_id
				);
				$counts['failed']++;
				continue;
			}

			$result = BCS_Catalog_Writer::execute_operation( $op, $job_id, $plan_id );

			if ( is_wp_error( $result ) ) {
				$failures[] = array(
					'row_index' => $row_index,
					'sku'       => $sku,
					'reason'    => $result->get_error_message(),
				);
				BCS_Audit_Logger::log_row(
					'bcs/execute-batch', $action, 'failed', $row_index, $sku, null,
					$result->get_error_message(), $row_key, $job_id, $plan_id
				);
				$counts['failed']++;
				continue;
			}

			$any_ok   = true;
			$warnings = array();
			if ( ! empty( $result['image_warning'] ) ) {
				$warnings[] = (string) $result['image_warning'];
			}

			if ( 'create_product' === $action ) {
				$counts['products_created']++;
				$counts['variations_created'] += (int) ( $result['variations_created'] ?? 0 );

				BCS_Audit_Logger::log_row(
					'bcs/execute-batch', 'create_product', 'ok', $row_index, $sku, (int) $result['product_id'],
					0 === (int) ( $result['variations_created'] ?? 0 )
						? __( 'Simple product created.', 'bulk-catalog-studio' )
						: sprintf(
							/* translators: 1: jumlah variasi, 2: id produk */
							__( 'Variable product created with %1$d variations (product #%2$d).', 'bulk-catalog-studio' ),
							(int) $result['variations_created'],
							(int) $result['product_id']
						) . ( ! empty( $warnings ) ? ' ' . implode( ' ', $warnings ) : '' ),
					$row_key, $job_id, $plan_id
				);

				// Entri row per variasi (action update_variation-style trace).
				if ( ! empty( $result['variations_created'] ) && ! empty( $op['payload']['variations'] ) ) {
					BCS_Audit_Logger::log_row(
						'bcs/execute-batch', 'create_variations', 'ok', $row_index, $sku, (int) $result['product_id'],
						sprintf(
							/* translators: 1: jumlah variasi */
							__( '%d variations saved for the product.', 'bulk-catalog-studio' ),
							(int) $result['variations_created']
						),
						$row_key, $job_id, $plan_id
					);
				}
			} elseif ( 'update_product' === $action ) {
				$counts['products_updated']++;

				BCS_Audit_Logger::log_row(
					'bcs/execute-batch', 'update_product', 'ok', $row_index, $sku, (int) $result['product_id'],
					__( 'Product updated per payload.', 'bulk-catalog-studio' ) . ( ! empty( $warnings ) ? ' ' . implode( ' ', $warnings ) : '' ),
					$row_key, $job_id, $plan_id
				);
			}

			// Simpan progress counter ke job tiap chunk.
			if ( 0 === ( $i + 1 ) % self::CHUNK_SIZE ) {
				BCS_Job_Manager::update_fields(
					$job_id,
					array(
						'count_created'    => $counts['products_created'],
						'count_updated'    => $counts['products_updated'],
						'count_variations' => $counts['variations_created'],
						'count_skipped'    => $counts['skipped'],
						'count_failed'     => self::count_failed_so_far( $job_id ),
					)
				);
			}
		}

		$duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		/* ----- Waktu habis: defer, biarkan job executing untuk resume ----- */
		if ( $deferred ) {
			BCS_Job_Manager::update_fields(
				$job_id,
				array(
					'count_created'    => $counts['products_created'],
					'count_updated'    => $counts['products_updated'],
					'count_variations' => $counts['variations_created'],
					'count_skipped'    => $counts['skipped'],
					'last_error'       => 'bcs_deferred: time budget reached; continue with resume=true',
				)
			);
			BCS_Job_Manager::heartbeat( $job_id );

			$failures[] = array(
				'row_index' => 0,
				'sku'       => null,
				'reason'    => __( 'Request time budget reached — some operations were deferred. Call bcs-execute-batch again with resume=true (idempotent).', 'bulk-catalog-studio' ),
			);

			BCS_Audit_Logger::log_call(
				'bcs/execute-batch', 'execute_batch', 'failed',
				__( 'Execution deferred due to the time budget; the job remains executing for resume.', 'bulk-catalog-studio' ),
				$job_id, $plan_id
			);

			return array(
				'job_id'      => $job_id,
				'plan_id'     => (int) $plan_id,
				'status'      => 'partially_failed',
				'counts'      => $counts,
				'failures'    => $failures,
				'duration_ms' => $duration_ms,
			);
		}

		/* ----- Finalisasi status ----- */
		$failed_so_far = self::count_failed_so_far( $job_id );
		$counts['failed'] = $failed_so_far;

		if ( $failed_so_far > 0 && $any_ok ) {
			$final_status = 'partially_failed';
		} elseif ( $failed_so_far > 0 && ! $any_ok && $counts['skipped'] < $counts['total_rows'] ) {
			$final_status = 'failed';
		} elseif ( ! $any_ok && 0 === $counts['skipped'] && $failed_so_far > 0 ) {
			$final_status = 'failed';
		} else {
			$final_status = 'done';
		}

		// Semua op gagal tanpa satu pun ok dan tanpa skip → failed.
		$effective_rows = $counts['total_rows'];
		if ( 0 === $counts['products_created'] && 0 === $counts['products_updated'] && $failed_so_far >= $effective_rows ) {
			$final_status = 'failed';
		}

		$extra = array(
			'count_created'    => $counts['products_created'],
			'count_updated'    => $counts['products_updated'],
			'count_variations' => $counts['variations_created'],
			'count_skipped'    => $counts['skipped'],
			'count_failed'     => $failed_so_far,
			'heartbeat_at'     => null,
			'last_error'       => null,
		);

		$transition = BCS_Job_Manager::transition( $job_id, $final_status, 'bcs/execute-batch', $extra );
		if ( is_wp_error( $transition ) ) {
			return $transition;
		}

		$plan_status = 'done' === $final_status ? 'executed' : 'executed_with_errors';
		BCS_Plan_Builder::set_status( $plan_id, $plan_status );

		BCS_Audit_Logger::log_call(
			'bcs/execute-batch', 'execute_batch', 'ok',
			sprintf(
				/* translators: 1: status, 2: dibuat, 3: diupdate, 4: variasi, 5: gagal */
				__( 'Execution finished: %1$s (created %2$d, updated %3$d, variations %4$d, failed %5$d).', 'bulk-catalog-studio' ),
				$final_status,
				$counts['products_created'],
				$counts['products_updated'],
				$counts['variations_created'],
				$failed_so_far
			),
			$job_id, $plan_id
		);

		return array(
			'job_id'      => $job_id,
			'plan_id'     => (int) $plan_id,
			'status'      => $final_status,
			'counts'      => $counts,
			'failures'    => $failures,
			'duration_ms' => $duration_ms,
		);
	}

	/**
	 * Dry run: validasi seluruh operasi tanpa efek samping.
	 * Tidak mengubah state job/plan dan TIDAK menulis audit row
	 * (mencegah polusi idempotency resume).
	 *
	 * @param array $plan    Plan ter-decode.
	 * @param int   $job_id  ID job.
	 * @param int   $plan_id ID plan.
	 * @return array|WP_Error
	 */
	private static function dry_run( array $plan, $job_id, $plan_id ) {
		$started_at = microtime( true );

		$counts = array(
			'products_created'   => 0,
			'products_updated'   => 0,
			'variations_created' => 0,
			'skipped'            => 0,
			'failed'             => 0,
			'total_rows'         => count( $plan['operations'] ),
		);

		$failures = array();

		foreach ( (array) $plan['operations'] as $op ) {
			$row_index = isset( $op['row_index'] ) ? (int) $op['row_index'] : 0;
			$sku       = isset( $op['payload']['sku'] ) ? (string) $op['payload']['sku'] : null;
			$action    = isset( $op['op'] ) ? (string) $op['op'] : '';

			if ( 'skip_duplicate' === $action ) {
				$counts['skipped']++;
				continue;
			}

			$validation = BCS_Catalog_Writer::validate_operation( $op );
			if ( is_wp_error( $validation ) ) {
				$failures[] = array(
					'row_index' => $row_index,
					'sku'       => $sku,
					'reason'    => $validation->get_error_message(),
				);
				$counts['failed']++;
				continue;
			}

			if ( 'create_product' === $action ) {
				$counts['products_created']++;
				$counts['variations_created'] += count( (array) ( $op['payload']['variations'] ?? array() ) );
			} elseif ( 'update_product' === $action ) {
				$counts['products_updated']++;
			}
		}

		$duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		BCS_Audit_Logger::log_call(
			'bcs/execute-batch', 'execute_batch_dry_run', 'ok',
			sprintf(
				/* translators: 1: dibuat, 2: diupdate, 3: gagal */
				__( 'Dry run finished (simulation): created %1$d, updated %2$d, failed %3$d. No state was changed.', 'bulk-catalog-studio' ),
				$counts['products_created'],
				$counts['products_updated'],
				$counts['failed']
			),
			$job_id, $plan_id
		);

		return array(
			'job_id'      => $job_id,
			'plan_id'     => (int) $plan_id,
			'status'      => $counts['failed'] > 0 ? 'partially_failed' : 'done',
			'counts'      => $counts,
			'failures'    => $failures,
			'duration_ms' => $duration_ms,
		);
	}

	/**
	 * Hitung entri row failed untuk job (counter akurat lintas resume).
	 *
	 * @param int $job_id ID job.
	 * @return int
	 */
	private static function count_failed_so_far( $job_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bcs_audit';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$failed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM {$table} WHERE job_id = %d AND level = 'row' AND status = 'failed'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
				absint( $job_id )
			)
		);

		return $failed;
	}
}
