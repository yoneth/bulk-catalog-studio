<?php
/**
 * Job manager — CRUD tabel bcs_jobs + state machine spec §2.
 *
 * State machine dipaksa di kode: transisi invalid mengembalikan WP_Error dan
 * mencatat entri audit. Tidak ada API yang menerima parameter `status` dari
 * input eksternal (spec §2.2).
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Job_Manager
 */
class BCS_Job_Manager {

	/**
	 * Status yang valid (spec §2.1).
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'draft',
			'parsed',
			'planned',
			'approved',
			'executing',
			'done',
			'partially_failed',
			'failed',
			'rolled_back',
		);
	}

	/**
	 * Peta transisi valid (spec §2.2).
	 *
	 * executing → executing hanya lewat resume dengan guard staleness
	 * (dicek terpisah, bukan lewat fungsi ini).
	 *
	 * @return array
	 */
	public static function allowed_transitions() {
		return array(
			'draft'            => array( 'parsed' ),
			'parsed'           => array( 'planned' ),
			'planned'          => array( 'approved' ),
			'approved'         => array( 'executing' ),
			'executing'        => array( 'done', 'partially_failed', 'failed' ),
			'done'             => array( 'rolled_back' ),
			'partially_failed' => array( 'rolled_back' ),
			'failed'           => array(), // Terminal: tidak ada produk tertulis.
			'rolled_back'      => array(), // Terminal.
		);
	}

	/**
	 * Ambil satu job sebagai array mentah (objek stdClass) atau null.
	 *
	 * @param int $job_id ID job.
	 * @return object|null
	 */
	public static function get_raw( $job_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $job_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
	}

	/**
	 * Ambil job sebagai array terkuras.
	 *
	 * @param int $job_id ID job.
	 * @return array|null
	 */
	public static function get( $job_id ) {
		$raw = self::get_raw( $job_id );
		if ( ! $raw ) {
			return null;
		}

		$mapping = json_decode( (string) $raw->mapping_json, true );
		if ( ! is_array( $mapping ) ) {
			$mapping = array();
		}

		return array(
			'id'             => (int) $raw->id,
			'status'         => (string) $raw->status,
			'source_type'    => (string) $raw->source_type,
			'source_name'    => (string) $raw->source_name,
			'source_hash'    => (string) $raw->source_hash,
			'total_rows'     => (int) $raw->total_rows,
			'counts'         => array(
				'created'    => (int) $raw->count_created,
				'updated'    => (int) $raw->count_updated,
				'variations' => (int) $raw->count_variations,
				'skipped'    => (int) $raw->count_skipped,
				'failed'     => (int) $raw->count_failed,
			),
			'active_plan_id' => $raw->active_plan_id ? (int) $raw->active_plan_id : null,
			'mapping'        => $mapping,
			'created_by'     => (int) $raw->created_by,
			'created_at'     => $raw->created_at,
			'updated_at'     => $raw->updated_at,
			'heartbeat_at'   => $raw->heartbeat_at,
			'last_error'     => $raw->last_error,
		);
	}

	/**
	 * List N job terbaru.
	 *
	 * @param int $limit Jumlah job.
	 * @return array
	 */
	public static function list_jobs( $limit = 20 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_jobs';
		$limit = max( 1, min( 100, absint( $limit ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.

		$jobs = array();
		foreach ( (array) $rows as $raw ) {
			$job            = self::get( (int) $raw->id );
			$job['stale']   = self::is_stale( $raw );
			$job['heartbeat_at_iso'] = $raw->heartbeat_at ? mysql2date( 'c', $raw->heartbeat_at ) : null;
			$job['created_at_iso']   = $raw->created_at ? mysql2date( 'c', $raw->created_at ) : null;
			$job['updated_at_iso']   = $raw->updated_at ? mysql2date( 'c', $raw->updated_at ) : null;
			$jobs[]         = $job;
		}

		return $jobs;
	}

	/**
	 * Buat job baru berstatus draft.
	 *
	 * @param array $args { source_type, source_name, source_hash, total_rows, mapping }.
	 * @return int|WP_Error ID job.
	 */
	public static function create( array $args ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_jobs';
		$now   = BCS_Settings::now_mysql();

		$source_type = isset( $args['source_type'] ) ? sanitize_key( $args['source_type'] ) : '';
		if ( ! in_array( $source_type, array( 'inline_csv', 'attachment', 'url' ), true ) ) {
			return new WP_Error( 'bcs_invalid_input', __( 'Invalid source_type.', 'bulk-catalog-studio' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$inserted = $wpdb->insert(
			$table,
			array(
				'status'      => 'draft',
				'source_type' => $source_type,
				'source_name' => substr( sanitize_text_field( (string) ( $args['source_name'] ?? '' ) ), 0, 200 ),
				'source_hash' => isset( $args['source_hash'] ) ? substr( preg_replace( '/[^a-f0-9]/i', '', (string) $args['source_hash'] ), 0, 40 ) : '',
				'total_rows'  => absint( $args['total_rows'] ?? 0 ),
				'mapping_json' => wp_json_encode( is_array( $args['mapping'] ?? null ) ? $args['mapping'] : array() ),
				'created_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		if ( ! $inserted ) {
			return new WP_Error( 'bcs_db_error', __( 'Failed to save the job.', 'bulk-catalog-studio' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update kolom bebas pada job (mapping_json, counters, active_plan_id, last_error).
	 * Status TIDAK boleh diubah lewat sini — wajib lewat transition().
	 *
	 * @param int   $job_id ID job.
	 * @param array $fields Kolom => nilai.
	 * @return true|WP_Error
	 */
	public static function update_fields( $job_id, array $fields ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_jobs';
		unset( $fields['status'], $fields['id'] );

		if ( empty( $fields ) ) {
			return true;
		}

		$fields['updated_at'] = BCS_Settings::now_mysql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$updated = $wpdb->update( $table, $fields, array( 'id' => absint( $job_id ) ) );
		if ( false === $updated ) {
			return new WP_Error( 'bcs_db_error', __( 'Failed to update the job.', 'bulk-catalog-studio' ) );
		}

		return true;
	}

	/**
	 * Transisi status state machine (spec §2.2).
	 *
	 * Transisi dilakukan atomik dengan guard `WHERE status = <expected>` agar
	 * dua proses paralel tidak bisa menembus. Transisi invalid → WP_Error +
	 * entri audit.
	 *
	 * @param int    $job_id      ID job.
	 * @param string $to_status   Status tujuan.
	 * @param string $context     Ability/context untuk audit.
	 * @param array  $extra_update Kolom tambahan yang diupdate bersamaan (opsional).
	 * @return true|WP_Error
	 */
	public static function transition( $job_id, $to_status, $context = '', array $extra_update = array() ) {
		global $wpdb;

		$job = self::get_raw( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'bcs_not_found', __( 'Job not found.', 'bulk-catalog-studio' ) );
		}

		$from_status = (string) $job->status;
		$allowed     = self::allowed_transitions();

		if ( ! isset( $allowed[ $from_status ] ) || ! in_array( $to_status, $allowed[ $from_status ], true ) ) {
			$message = sprintf(
				/* translators: 1: status asal, 2: status tujuan */
				__( 'Status transition rejected: %1$s → %2$s is not valid per the state machine.', 'bulk-catalog-studio' ),
				$from_status,
				$to_status
			);

			BCS_Audit_Logger::log_call(
				$context,
				'state_transition',
				'failed',
				$message,
				absint( $job_id ),
				! empty( $extra_update['active_plan_id'] ) ? absint( $extra_update['active_plan_id'] ) : (int) $job->active_plan_id
			);

			return new WP_Error( 'bcs_invalid_state', $message );
		}

		$table = $wpdb->prefix . 'bcs_jobs';

		$data   = array_merge(
			array(
				'status'     => $to_status,
				'updated_at' => BCS_Settings::now_mysql(),
			),
			$extra_update
		);
		$format = self::formats_for( $data );

		// Update atomik: hanya berhasil bila status masih sama dengan yang dibaca.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom, guard konkurensi.
		$result = $wpdb->update( $table, $data, array( 'id' => absint( $job_id ), 'status' => $from_status ), $format, array( '%d', '%s' ) );

		if ( 1 !== (int) $result ) {
			$message = __( 'Status transition failed: the job was changed by another process.', 'bulk-catalog-studio' );

			BCS_Audit_Logger::log_call( $context, 'state_transition', 'failed', $message, absint( $job_id ) );

			return new WP_Error( 'bcs_state_conflict', $message );
		}

		return true;
	}

	/**
	 * Transisi executing untuk job yang sudah approved (atomik, guard 1 job
	 * executing per site, spec §2.2).
	 *
	 * @param int  $plan_id ID plan.
	 * @param bool $resume  True = jalur resume (job executing + stale/deferred).
	 * @return array|WP_Error array(job, plan) bila sukses.
	 */
	public static function begin_execution( $plan_id, $resume = false ) {
		global $wpdb;

		$plan = BCS_Executor::get_plan_raw( $plan_id );
		if ( ! $plan ) {
			return new WP_Error( 'bcs_not_found', __( 'Plan not found.', 'bulk-catalog-studio' ) );
		}

		$job = self::get_raw( $plan->job_id );
		if ( ! $job ) {
			return new WP_Error( 'bcs_not_found', __( 'Job not found.', 'bulk-catalog-studio' ) );
		}

		$now   = BCS_Settings::now_mysql();
		$table = $wpdb->prefix . 'bcs_jobs';

		// Resume: job harus 'executing' + param resume=true + (stale ATAU marker deferred).
		if ( 'executing' === $job->status ) {
			$deferred = ! empty( $job->last_error ) && false !== strpos( (string) $job->last_error, 'bcs_deferred' );
			if ( ! $resume ) {
				return new WP_Error(
					'bcs_execution_in_progress',
					__( 'The job is still executing. Use resume=true if the heartbeat is stale (>5 minutes) or the previous execution was deferred.', 'bulk-catalog-studio' )
				);
			}
			if ( ! self::is_stale( $job ) && ! $deferred ) {
				return new WP_Error(
					'bcs_execution_in_progress',
					__( 'The job is still executing and its heartbeat is fresh. Wait until it goes stale (>5 minutes) before resuming.', 'bulk-catalog-studio' )
				);
			}

			// Guard: hanya job ini yang boleh executing; refresh heartbeat secara atomik.
			// NIT-8 (retur QA-1): marker deferred di last_error DIPERTAHANKAN
			// sampai finalisasi — bila request resume mati sebelum chunk
			// selesai, resume berikutnya masih bisa masuk tanpa menunggu stale.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET heartbeat_at = %s, updated_at = %s WHERE id = %d AND status = 'executing'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
					$now,
					$now,
					(int) $job->id
				)
			);

			return array(
				'job'     => self::get_raw( $job->id ),
				'plan'    => $plan,
				'resumed' => true,
			);
		}

		// Normal: approved → executing.
		if ( 'approved' !== $job->status || 'approved' !== (string) $plan->status ) {
			return new WP_Error(
				'bcs_invalid_state',
				sprintf(
					/* translators: 1: status job, 2: status plan */
					__( 'The plan can only be executed when the job is approved and the plan is approved (current status: job=%1$s, plan=%2$s).', 'bulk-catalog-studio' ),
					(string) $job->status,
					(string) $plan->status
				)
			);
		}

		// Maksimum 1 job executing per site: tolak bila ada job executing lain yang tidak stale.
		$other = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
			$wpdb->prepare(
				"SELECT id, heartbeat_at, last_error FROM {$table} WHERE status = 'executing' AND id != %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
				(int) $job->id
			)
		);
		if ( $other && ! self::is_stale( $other ) && false === strpos( (string) $other->last_error, 'bcs_deferred' ) ) {
			return new WP_Error(
				'bcs_execution_in_progress',
				sprintf(
					/* translators: %d: ID job yang sedang berjalan */
					__( 'Another job is executing (job #%d). Only 1 executing job per site is allowed.', 'bulk-catalog-studio' ),
					(int) $other->id
				)
			);
		}

		// Transisi atomik approved → executing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom, guard konkurensi.
		$result = $wpdb->update(
			$table,
			array(
				'status'        => 'executing',
				'heartbeat_at'  => $now,
				'updated_at'    => $now,
				'last_error'    => null,
				'active_plan_id' => (int) $plan->id,
			),
			array(
				'id'     => (int) $job->id,
				'status' => 'approved',
			),
			array( '%s', '%s', '%s', '%s', '%d' ),
			array( '%d', '%s' )
		);

		if ( 1 !== (int) $result ) {
			return new WP_Error( 'bcs_state_conflict', __( 'approved → executing transition failed (the job was changed by another process).', 'bulk-catalog-studio' ) );
		}

		return array(
			'job'     => self::get_raw( $job->id ),
			'plan'    => $plan,
			'resumed' => false,
		);
	}

	/**
	 * Update heartbeat job (dipanggil tiap chunk saat executing).
	 *
	 * @param int $job_id ID job.
	 * @return void
	 */
	public static function heartbeat( $job_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_jobs';
		$now   = BCS_Settings::now_mysql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET heartbeat_at = %s, updated_at = %s WHERE id = %d AND status = 'executing'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
				$now,
				$now,
				absint( $job_id )
			)
		);
	}

	/**
	 * Apakah job (atau baris mentah) executing-stale (> 5 menit tanpa heartbeat)?
	 *
	 * @param object $raw Baris mentah tabel jobs.
	 * @return bool
	 */
	public static function is_stale( $raw ) {
		if ( 'executing' !== (string) $raw->status || empty( $raw->heartbeat_at ) ) {
			return false;
		}

		$hb      = strtotime( $raw->heartbeat_at . ' UTC' );
		$threshold = time() - ( 5 * MINUTE_IN_SECONDS );

		return $hb < $threshold;
	}

	/**
	 * Format array untuk $wpdb->update sesuai tipe nilai.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private static function formats_for( array $data ) {
		$format = array();
		foreach ( $data as $value ) {
			if ( is_int( $value ) || is_bool( $value ) ) {
				$format[] = is_int( $value ) ? '%d' : '%s';
			} elseif ( null === $value ) {
				$format[] = null; // $wpdb menangani null.
			} else {
				$format[] = '%s';
			}
		}

		return $format;
	}
}
