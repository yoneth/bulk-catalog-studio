<?php
/**
 * Rollback service untuk bcs/rollback-batch (spec §1.7, AC-6).
 *
 * Semantik (gate1 #5):
 * - Produk BARU hasil create → trash (soft, recoverable) beserta variasinya.
 * - Produk yang di-update → semua field tersentuh dikembalikan ke snapshot
 *   `before` dari plan.
 * - Entri audit level row `rolled_back` per baris.
 *
 * Idempotency: hanya operasi dengan entri audit (job_id, row_key, status ok)
 * yang di-rollback; job terminal rolled_back tidak bisa di-rollback lagi
 * (state machine menolak).
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Rollback_Service
 */
class BCS_Rollback_Service {

	/**
	 * Jalankan rollback untuk job berstatus done/partially_failed.
	 *
	 * @param int    $job_id ID job.
	 * @param bool   $confirm Harus true literal.
	 * @param string $scope   all | created_only | updated_only.
	 * @return array|WP_Error Hasil spec §1.7.
	 */
	public static function rollback( $job_id, $confirm, $scope = 'all' ) {
		$guard = BCS_Catalog_Writer::guard();
		if ( is_wp_error( $guard ) ) {
			BCS_Audit_Logger::log_call( 'bcs/rollback-batch', 'rollback_batch', 'failed', $guard->get_error_message(), $job_id );
			return $guard;
		}

		if ( true !== $confirm ) {
			return new WP_Error( 'bcs_confirm_required', __( 'The confirm parameter must be literal true to prevent accidental rollback.', 'bulk-catalog-studio' ) );
		}

		if ( ! in_array( $scope, array( 'all', 'created_only', 'updated_only' ), true ) ) {
			return new WP_Error( 'bcs_invalid_input', __( 'Invalid rollback scope.', 'bulk-catalog-studio' ) );
		}

		$job = BCS_Job_Manager::get( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'bcs_not_found', __( 'Job not found.', 'bulk-catalog-studio' ) );
		}

		if ( ! in_array( $job['status'], array( 'done', 'partially_failed' ), true ) ) {
			return new WP_Error(
				'bcs_invalid_state',
				sprintf(
					/* translators: %s: status job */
					__( 'Rollback only works on jobs with status done or partially_failed (current status: %s).', 'bulk-catalog-studio' ),
					$job['status']
				)
			);
		}

		// Plan aktif job.
		$plan_id = $job['active_plan_id'] ? (int) $job['active_plan_id'] : 0;
		$plan_row = $plan_id ? BCS_Plan_Builder::get_plan_row( $plan_id ) : null;

		if ( ! $plan_row ) {
			return new WP_Error( 'bcs_not_found', __( 'Active plan for this job not found.', 'bulk-catalog-studio' ) );
		}

		$plan = BCS_Plan_Builder::decode_plan( $plan_row );

		if ( empty( $plan['operations'] ) || ! is_array( $plan['operations'] ) ) {
			return new WP_Error( 'bcs_invalid_plan', __( 'Plan has no operations.', 'bulk-catalog-studio' ) );
		}

		$counts = array(
			'products_trashed'   => 0,
			'products_restored'  => 0,
			'variations_trashed' => 0,
			'failed'             => 0,
		);

		$notes = array();

		foreach ( (array) $plan['operations'] as $op ) {
			$row_index = isset( $op['row_index'] ) ? (int) $op['row_index'] : 0;
			$row_key   = isset( $op['row_key'] ) ? (string) $op['row_key'] : '';
			$action    = isset( $op['op'] ) ? (string) $op['op'] : '';
			$sku       = isset( $op['payload']['sku'] ) ? (string) $op['payload']['sku'] : null;

			if ( 'skip_duplicate' === $action ) {
				continue;
			}

			// Hanya baris yang benar-benar sukses dieksekusi yang di-rollback.
			if ( '' === $row_key || ! BCS_Audit_Logger::has_row_success( $job_id, $row_key ) ) {
				continue;
			}

			if ( 'create_product' === $action && in_array( $scope, array( 'all', 'created_only' ), true ) ) {
				// MAJOR-3 (retur QA-1): product_id diambil dari AUDIT job ini
				// (job-scoped), bukan lookup meta row_key yang bisa kollide
				// lintas job (row_key sengaja tanpa job_id — spec §5.4).
				$product_id = self::find_created_product_id( $job_id, $row_key );

				if ( ! $product_id ) {
					continue;
				}

				$result = BCS_Catalog_Writer::trash_product( $product_id );

				if ( is_wp_error( $result ) ) {
					$counts['failed']++;
					$notes[] = sprintf(
						/* translators: 1: nomor baris, 2: pesan error */
						__( 'Row %1$d failed to trash: %2$s', 'bulk-catalog-studio' ),
						$row_index,
						$result->get_error_message()
					);
					BCS_Audit_Logger::log_row(
						'bcs/rollback-batch', 'trash_product', 'failed', $row_index, $sku, $product_id,
						$result->get_error_message(), $row_key, $job_id, $plan_id
					);
					continue;
				}

				$counts['products_trashed']++;
				$counts['variations_trashed'] += (int) ( $result['variations_trashed'] ?? 0 );

				BCS_Audit_Logger::log_row(
					'bcs/rollback-batch', 'trash_product', 'rolled_back', $row_index, $sku, $product_id,
					sprintf(
						/* translators: 1: id produk, 2: jumlah variasi */
						__( 'New product #%1$d moved to trash along with %2$d variations.', 'bulk-catalog-studio' ),
						(int) $product_id,
						(int) ( $result['variations_trashed'] ?? 0 )
					),
					$row_key, $job_id, $plan_id
				);
			}

			if ( 'update_product' === $action && in_array( $scope, array( 'all', 'updated_only' ), true ) ) {
				$target     = isset( $op['target_product_id'] ) ? (int) $op['target_product_id'] : 0;
				$before     = isset( $op['before'] ) && is_array( $op['before'] ) ? $op['before'] : null;

				if ( ! $target || ! $before ) {
					$counts['failed']++;
					$notes[] = sprintf(
						/* translators: 1: nomor baris */
						__( 'Row %1$d: before snapshot unavailable.', 'bulk-catalog-studio' ),
						$row_index
					);
					BCS_Audit_Logger::log_row(
						'bcs/rollback-batch', 'restore_snapshot', 'failed', $row_index, $sku, $target,
						__( 'Before snapshot unavailable.', 'bulk-catalog-studio' ), $row_key, $job_id, $plan_id
					);
					continue;
				}

				$restored = BCS_Catalog_Writer::restore_from_snapshot( $target, $before );

				if ( is_wp_error( $restored ) ) {
					$counts['failed']++;
					$notes[] = sprintf(
						/* translators: 1: nomor baris, 2: pesan error */
						__( 'Row %1$d failed to restore: %2$s', 'bulk-catalog-studio' ),
						$row_index,
						$restored->get_error_message()
					);
					BCS_Audit_Logger::log_row(
						'bcs/rollback-batch', 'restore_snapshot', 'failed', $row_index, $sku, $target,
						$restored->get_error_message(), $row_key, $job_id, $plan_id
					);
					continue;
				}

				$counts['products_restored']++;

				BCS_Audit_Logger::log_row(
					'bcs/rollback-batch', 'restore_snapshot', 'rolled_back', $row_index, $sku, $target,
					__( 'Product restored to the before snapshot.', 'bulk-catalog-studio' ),
					$row_key, $job_id, $plan_id
				);
			}
		}

		// Transisi job → rolled_back (state machine: done|partially_failed → rolled_back).
		$transition = BCS_Job_Manager::transition( $job_id, 'rolled_back', 'bcs/rollback-batch' );
		if ( is_wp_error( $transition ) ) {
			return $transition;
		}

		BCS_Plan_Builder::set_status( $plan_id, 'rolled_back' );

		// Output schema menetapkan note sebagai string; gunakan string kosong saat
		// rollback bersih agar validasi Abilities API tidak menolak hasil sukses.
		$note = implode( ' | ', $notes );

		BCS_Audit_Logger::log_call(
			'bcs/rollback-batch', 'rollback_batch', 'ok',
			sprintf(
				/* translators: 1: trashed, 2: restored, 3: variasi, 4: gagal */
				__( 'Rollback completed: trashed %1$d, restored %2$d, variations trashed %3$d, failed %4$d.', 'bulk-catalog-studio' ),
				$counts['products_trashed'],
				$counts['products_restored'],
				$counts['variations_trashed'],
				$counts['failed']
			),
			$job_id, $plan_id
		);

		return array(
			'job_id' => (int) $job_id,
			'status' => 'rolled_back',
			'counts' => $counts,
			'note'   => $note,
		);
	}

	/**
	 * Temukan product ID hasil create untuk (job, row_key).
	 *
	 * Sumber primer: entri audit create_product ok milik job INI (job-scoped —
	 * MAJOR-3 retur QA-1). Fallback: meta `_bcs_row_key` + verifikasi
	 * `_bcs_job_id` = job ini (meta row_key tanpa verifikasi job bisa
	 * menunjuk produk milik job lain).
	 *
	 * @param int    $job_id  ID job.
	 * @param string $row_key row_key operasi.
	 * @return int|null
	 */
	private static function find_created_product_id( $job_id, $row_key ) {
		// 1) Sumber primer: audit job ini.
		$from_audit = BCS_Audit_Logger::find_row_product_id( $job_id, $row_key, 'create_product' );
		if ( $from_audit ) {
			return $from_audit;
		}

		// 2) Fallback: meta row_key + verifikasi kepemilikan job.
		if ( '' === (string) $row_key ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- lookup presisi per row_key + job_id, maks 1 hasil.
					'relation' => 'AND',
					array(
						'key'   => '_bcs_row_key',
						'value' => (string) $row_key,
					),
					array(
						'key'   => '_bcs_job_id',
						'value' => absint( $job_id ),
					),
				),
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}
}
