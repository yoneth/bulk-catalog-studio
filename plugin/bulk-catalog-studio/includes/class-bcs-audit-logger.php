<?php
/**
 * Audit logger — append-only ke tabel {prefix}bcs_audit (spec §3.4).
 *
 * Dua level entri:
 * - call: satu entri per pemanggilan ability.
 * - row : entri per baris operasi execute/rollback.
 *
 * Untuk entri level row, kolom payload_digest diisi row_key (sha1 hex 40 char,
 * spec §5.4) sehingga query idempotency resume dapat mencari
 * (job_id, payload_digest, status='ok').
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Audit_Logger
 */
class BCS_Audit_Logger {

	/**
	 * Tulis entri level 'call'.
	 *
	 * @param string $ability  Nama ability (bcs/...).
	 * @param string $action   Aksi ringkas (approve_plan, execute_batch, ...).
	 * @param string $status   ok|failed|rolled_back|skipped.
	 * @param string $message  Pesan manusia.
	 * @param int    $job_id   Job terkait (0 = null).
	 * @param int    $plan_id  Plan terkait (0 = null).
	 * @param mixed       $payload    Payload opsional — hanya disimpan digest sha1-nya.
	 * @param string      $source     batch|sync.
	 * @param string|null $actor_via  Override konteks aktor (sync|cron|mcp, dst.).
	 * @return int|false ID entri atau false bila gagal.
	 */
	public static function log_call( $ability, $action, $status, $message = '', $job_id = 0, $plan_id = 0, $payload = null, $source = 'batch', $actor_via = null ) {
		return self::insert(
			array(
				'level'          => 'call',
				'ability'        => $ability,
				'action'         => $action,
				'status'         => $status,
				'message'        => $message,
				'job_id'         => $job_id,
				'plan_id'        => $plan_id,
				'payload_digest' => null !== $payload ? sha1( (string) wp_json_encode( $payload ) ) : null,
				'source'         => $source,
				'actor_via'      => $actor_via,
			)
		);
	}

	/**
	 * Tulis entri level 'row'.
	 *
	 * @param string      $ability    Nama ability.
	 * @param string      $action     create_product, update_variation, trash_product, rollback_row, dst.
	 * @param string      $status     ok|failed|rolled_back|skipped.
	 * @param int         $row_index  Index baris sumber (1-based).
	 * @param string|null $sku        SKU terkait.
	 * @param int|null    $product_id Produk terkait.
	 * @param string      $message    Pesan manusia.
	 * @param string|null $row_key    row_key (sha1) untuk idempotency — disimpan di payload_digest.
	 * @param int         $job_id     Job terkait.
	 * @param int         $plan_id    Plan terkait.
	 * @param string      $source     batch|sync.
	 * @param string|null $actor_via  Override konteks aktor (sync|cron|mcp, dst.).
	 * @return int|false ID entri atau false bila gagal.
	 */
	public static function log_row( $ability, $action, $status, $row_index, $sku = null, $product_id = null, $message = '', $row_key = null, $job_id = 0, $plan_id = 0, $source = 'batch', $actor_via = null ) {
		return self::insert(
			array(
				'level'          => 'row',
				'ability'        => $ability,
				'action'         => $action,
				'status'         => $status,
				'message'        => $message,
				'row_index'      => $row_index,
				'sku'            => $sku,
				'product_id'     => $product_id,
				'payload_digest' => $row_key,
				'job_id'         => $job_id,
				'plan_id'        => $plan_id,
				'source'         => $source,
				'actor_via'      => $actor_via,
			)
		);
	}

	/**
	 * Insert baris audit via $wpdb->insert (nilai sudah terkuras).
	 *
	 * @param array $data Kolom terkuras.
	 * @return int|false
	 */
	private static function insert( array $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_audit';

		$actor_via = isset( $data['actor_via'] ) && is_string( $data['actor_via'] ) ? sanitize_key( $data['actor_via'] ) : self::detect_via();
		if ( ! in_array( $actor_via, array( 'mcp', 'wp_admin', 'wp_cli', 'system', 'sync', 'cron' ), true ) ) {
			$actor_via = self::detect_via();
		}

		$row = array(
			'job_id'         => ! empty( $data['job_id'] ) ? absint( $data['job_id'] ) : null,
			'plan_id'        => ! empty( $data['plan_id'] ) ? absint( $data['plan_id'] ) : null,
			'level'          => ( 'row' === $data['level'] ) ? 'row' : 'call',
			'ability'        => substr( sanitize_text_field( (string) $data['ability'] ), 0, 60 ),
			'action'         => substr( sanitize_text_field( (string) $data['action'] ), 0, 60 ),
			'actor_user_id'  => get_current_user_id(),
			'actor_via'      => $actor_via,
			'source'         => ( isset( $data['source'] ) && 'sync' === sanitize_key( $data['source'] ) ) ? 'sync' : 'batch',
			'row_index'      => isset( $data['row_index'] ) && null !== $data['row_index'] ? absint( $data['row_index'] ) : null,
			'sku'            => ! empty( $data['sku'] ) ? substr( sanitize_text_field( (string) $data['sku'] ), 0, 100 ) : null,
			'product_id'     => ! empty( $data['product_id'] ) ? absint( $data['product_id'] ) : null,
			'status'         => self::sanitize_status( $data['status'] ),
			'payload_digest' => ! empty( $data['payload_digest'] ) ? substr( preg_replace( '/[^a-f0-9]/i', '', (string) $data['payload_digest'] ), 0, 40 ) : null,
			'message'        => null !== $data['message'] ? sanitize_textarea_field( (string) $data['message'] ) : null,
			'created_at'     => BCS_Settings::now_mysql(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom append-only, tanpa cache; $wpdb->insert sudah meng-prepare nilai.
		$inserted = $wpdb->insert( $table, $row );
		if ( ! $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Sanitasi nilai status ke enum yang valid.
	 *
	 * @param string $status Nilai mentah.
	 * @return string
	 */
	private static function sanitize_status( $status ) {
		$allowed = array( 'ok', 'failed', 'rolled_back', 'skipped' );
		return in_array( $status, $allowed, true ) ? $status : 'ok';
	}

	/**
	 * Deteksi konteks aktor (spec §1, kolom actor_via).
	 *
	 * - mcp      : dipanggil lewat REST (MCP Adapter berjalan di atas REST).
	 * - wp_admin : dipanggil dari halaman admin plugin.
	 * - wp_cli   : dipanggil via WP-CLI.
	 * - system   : fallback.
	 *
	 * @return string
	 */
	public static function detect_via() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp_cli';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'mcp';
		}
		if ( is_admin() ) {
			return 'wp_admin';
		}
		return 'system';
	}

	/**
	 * Query entri audit untuk satu job (spec §1.8).
	 *
	 * @param int    $job_id ID job.
	 * @param string $level  call|row|all.
	 * @param int    $limit  Maks 200.
	 * @param int    $offset Minimum 0.
	 * @param string $source Optional source filter: batch|sync.
	 * @return array { total: int, entries: array }
	 */
	public static function query( $job_id, $level = 'all', $limit = 50, $offset = 0, $source = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_audit';
		$job_id = absint( $job_id );
		$limit  = min( 200, max( 1, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );

		$where    = 'WHERE job_id = %d';
		$where_ar = array( $job_id );

		if ( 'call' === $level || 'row' === $level ) {
			$where    .= ' AND level = %s';
			$where_ar[] = $level;
		}
		if ( in_array( $source, array( 'batch', 'sync' ), true ) ) {
			$where    .= ' AND source = %s';
			$where_ar[] = $source;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- tabel kustom; seluruh nilai via prepare.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$table} {$where}", $where_ar ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragment where statis + prepare.

		$sql = "SELECT * FROM {$table} {$where} ORDER BY id ASC LIMIT %d OFFSET %d";
		array_push( $where_ar, $limit, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- tabel kustom.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $where_ar ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragment where statis + prepare.

		$entries = array();
		foreach ( (array) $rows as $r ) {
			$entries[] = array(
				'id'            => (int) $r['id'],
				'ts'            => mysql2date( 'c', $r['created_at'] ),
				'level'         => $r['level'],
				'ability'       => (string) $r['ability'],
				'actor_user_id' => (int) $r['actor_user_id'],
				'actor_via'     => $r['actor_via'],
				'source'        => isset( $r['source'] ) ? (string) $r['source'] : 'batch',
				'action'        => (string) $r['action'],
				'row_index'     => null === $r['row_index'] ? null : (int) $r['row_index'],
				'sku'           => null === $r['sku'] ? null : (string) $r['sku'],
				'product_id'    => null === $r['product_id'] ? null : (int) $r['product_id'],
				'status'        => $r['status'],
				'message'       => (string) $r['message'],
			);
		}

		return array(
			'total'   => $total,
			'entries' => $entries,
		);
	}

	/**
	 * Ambil product_id dari entri audit row sukses (job-scoped).
	 *
	 * MAJOR-3 (retur QA-1): sumber kebenaran rollback untuk "produk apa yang
	 * dibuat oleh job INI pada row_key INI" adalah audit job itu sendiri —
	 * bukan lookup meta row_key yang sengaja tanpa job_id (bisa kollide
	 * lintas job bila CSV identik diproses ulang).
	 *
	 * @param int    $job_id  ID job.
	 * @param string $row_key row_key sha1.
	 * @param string $action  Aksi row (create_product).
	 * @param string $source  Optional source filter.
	 * @return int|null
	 */
	public static function find_row_product_id( $job_id, $row_key, $action = 'create_product', $source = '' ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'bcs_audit';
		$digest = substr( preg_replace( '/[^a-f0-9]/i', '', (string) $row_key ), 0, 40 );

		$where = "job_id = %d AND level = 'row' AND payload_digest = %s AND action = %s AND status = 'ok' AND product_id IS NOT NULL";
		$args  = array( absint( $job_id ), $digest, sanitize_text_field( $action ) );
		if ( in_array( $source, array( 'batch', 'sync' ), true ) ) {
			$where .= ' AND source = %s';
			$args[] = $source;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT product_id FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
				$args
			)
		);

		return $found ? (int) $found : null;
	}

	/**
	 * Cek apakah ada entri row (status sukses/skip) untuk (job_id, row_key).
	 * MINOR-4 (retur QA-1): dipakai resume agar baris yang sudah punya entri
	 * (ok ATAU skipped) tidak dieksekusi/dihitung ulang; entri failed tetap
	 * di-retry.
	 *
	 * @param int    $job_id  ID job.
	 * @param string $row_key row_key sha1.
	 * @return bool
	 */
	public static function has_row_entry( $job_id, $row_key ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'bcs_audit';
		$digest = substr( preg_replace( '/[^a-f0-9]/i', '', (string) $row_key ), 0, 40 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE job_id = %d AND level = 'row' AND payload_digest = %s AND status IN ( 'ok', 'skipped' ) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
				absint( $job_id ),
				$digest
			)
		);

		return ! empty( $found );
	}

	/**
	 * Cek apakah ada entri row sukses untuk (job_id, row_key) — idempotency §5.4.
	 *
	 * @param int    $job_id  ID job.
	 * @param string $row_key row_key sha1.
	 * @param string $source  Optional source filter.
	 * @return bool
	 */
	public static function has_row_success( $job_id, $row_key, $source = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_audit';
		$digest = substr( preg_replace( '/[^a-f0-9]/i', '', (string) $row_key ), 0, 40 );

		$where = "job_id = %d AND level = 'row' AND payload_digest = %s AND status = 'ok'";
		$args  = array( absint( $job_id ), $digest );
		if ( in_array( $source, array( 'batch', 'sync' ), true ) ) {
			$where .= ' AND source = %s';
			$args[] = $source;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE {$where} LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
				$args
			)
		);

		return ! empty( $found );
	}
}
