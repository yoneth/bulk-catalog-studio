<?php
/**
 * Rutinitas install/upgrade skema DB untuk Bulk Catalog Studio.
 *
 * Membuat tabel BCS batch + sync via dbDelta:
 * - {prefix}bcs_jobs
 * - {prefix}bcs_plans
 * - {prefix}bcs_audit
 * - {prefix}bcs_connections
 * - {prefix}bcs_sync_runs
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Install
 */
class BCS_Install {

	/**
	 * Jalankan dbDelta + simpan default settings + versi skema.
	 * Aman dipanggil berulang (dbDelta idempotent untuk skema identik).
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		/*
		 * Tabel jobs — spec §3.2.
		 * Catatan dbDelta: setiap field pada baris sendiri, KEY (bukan INDEX),
		 * dua spasi setelah PRIMARY KEY.
		 */
		$jobs_table = $prefix . 'bcs_jobs';
		$sql_jobs   = "CREATE TABLE {$jobs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			source_type VARCHAR(20) NOT NULL DEFAULT '',
			source_name VARCHAR(200) NOT NULL DEFAULT '',
			source_hash CHAR(40) NOT NULL DEFAULT '',
			total_rows INT UNSIGNED NOT NULL DEFAULT 0,
			count_created INT UNSIGNED NOT NULL DEFAULT 0,
			count_updated INT UNSIGNED NOT NULL DEFAULT 0,
			count_variations INT UNSIGNED NOT NULL DEFAULT 0,
			count_skipped INT UNSIGNED NOT NULL DEFAULT 0,
			count_failed INT UNSIGNED NOT NULL DEFAULT 0,
			active_plan_id BIGINT UNSIGNED NULL DEFAULT NULL,
			mapping_json LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			heartbeat_at DATETIME NULL DEFAULT NULL,
			last_error TEXT NULL,
			PRIMARY KEY  (id),
			KEY status_hb (status, heartbeat_at),
			KEY source_hash (source_hash),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $sql_jobs );

		/*
		 * Tabel plans — spec §3.3.
		 * token_hash memakai VARCHAR(255) (bukan CHAR(60) pada draft spec) agar
		 * menampung semua format hash password WordPress: portable hash (34),
		 * bcrypt (60), maupun argon2id (±97 char) bila host meng-override
		 * pluggable wp_hash_password(). Nilainya selalu hasil hash, bukan
		 * plaintext token.
		 */
		$plans_table = $prefix . 'bcs_plans';
		$sql_plans   = "CREATE TABLE {$plans_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			version INT UNSIGNED NOT NULL DEFAULT 1,
			plan_json LONGTEXT NULL,
			plan_hash CHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'planned',
			token_hash VARCHAR(255) NULL DEFAULT NULL,
			token_expires_at DATETIME NULL DEFAULT NULL,
			token_used_at DATETIME NULL DEFAULT NULL,
			approved_by BIGINT UNSIGNED NULL DEFAULT NULL,
			approved_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY job_version (job_id, version),
			KEY plan_hash (plan_hash)
		) {$charset_collate};";
		dbDelta( $sql_plans );

		/*
		 * Tabel audit — spec §3.4.
		 * payload_digest dipakai juga sebagai penyimpan row_key untuk entri
		 * level 'row' (keduanya sha1 hex 40 char) sehingga query idempotency
		 * §5.4 — (job_id, row_key, status='ok') — dapat memakai index job_ts.
		 */
		$audit_table = $prefix . 'bcs_audit';
		$sql_audit   = "CREATE TABLE {$audit_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NULL DEFAULT NULL,
			plan_id BIGINT UNSIGNED NULL DEFAULT NULL,
			level ENUM('call','row') NOT NULL DEFAULT 'call',
			ability VARCHAR(60) NOT NULL DEFAULT '',
			action VARCHAR(60) NOT NULL DEFAULT '',
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor_via ENUM('mcp','wp_admin','wp_cli','system','sync','cron') NOT NULL DEFAULT 'system',
			source VARCHAR(10) NOT NULL DEFAULT 'batch',
			row_index INT UNSIGNED NULL DEFAULT NULL,
			sku VARCHAR(100) NULL DEFAULT NULL,
			product_id BIGINT UNSIGNED NULL DEFAULT NULL,
			status ENUM('ok','failed','rolled_back','skipped') NOT NULL DEFAULT 'ok',
			payload_digest CHAR(40) NULL DEFAULT NULL,
			message TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY job_ts (job_id, created_at),
			KEY product (product_id),
			KEY level_status (level, status)
		) {$charset_collate};";
		dbDelta( $sql_audit );

		/*
		 * Tabel connections — spec §2.1. JSON disimpan sebagai LONGTEXT agar
		 * kompatibel dengan MySQL/MariaDB yang dipakai WordPress.
		 */
		$connections_table = $prefix . 'bcs_connections';
		$sql_connections   = "CREATE TABLE {$connections_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL DEFAULT '',
			fetcher VARCHAR(20) NOT NULL DEFAULT 'csv_export',
			sheet_config LONGTEXT NULL,
			credentials LONGTEXT NULL,
			column_mapping LONGTEXT NULL,
			policy LONGTEXT NULL,
			thresholds LONGTEXT NULL,
			interval_minutes INT UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(12) NOT NULL DEFAULT 'paused',
			last_ok_run_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY last_ok_run_at (last_ok_run_at),
			KEY updated_at (updated_at)
		) {$charset_collate};";
		dbDelta( $sql_connections );

		/*
		 * Tabel sync runs — spec §2.2. heartbeat_at sengaja terpisah dari
		 * finished_at agar request yang masih applying dapat dilanjutkan aman.
		 */
		$runs_table = $prefix . 'bcs_sync_runs';
		$sql_runs   = "CREATE TABLE {$runs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id BIGINT UNSIGNED NOT NULL,
			run_trigger VARCHAR(16) NOT NULL DEFAULT 'manual',
			state VARCHAR(20) NOT NULL DEFAULT 'pending',
			diff_hash CHAR(40) NULL DEFAULT NULL,
			diff_summary LONGTEXT NULL,
			token_hash VARCHAR(255) NULL DEFAULT NULL,
			token_expires_at DATETIME NULL DEFAULT NULL,
			token_used_at DATETIME NULL DEFAULT NULL,
			approved_by BIGINT UNSIGNED NULL DEFAULT NULL,
			approved_at DATETIME NULL DEFAULT NULL,
			fetched_rows INT UNSIGNED NOT NULL DEFAULT 0,
			error_code VARCHAR(60) NULL DEFAULT NULL,
			error_note TEXT NULL,
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL DEFAULT NULL,
			heartbeat_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY connection_state (connection_id, state),
			KEY state_started (state, started_at),
			KEY diff_hash (diff_hash),
			KEY heartbeat_at (heartbeat_at)
		) {$charset_collate};";
		dbDelta( $sql_runs );

		// dbDelta tidak selalu menambahkan kolom baru pada tabel existing.
		self::maybe_upgrade_audit_source_column();
		self::maybe_upgrade_audit_actor_via_column();
		self::maybe_upgrade_sync_run_approval_columns();

		// Default settings hanya diisi bila belum ada (reactivation tidak menimpa).
		add_option( 'bcs_settings', BCS_Settings::defaults() );
		add_option( 'bcs_sync_settings', array() );

		update_option( 'bcs_db_version', BCS_DB_VERSION, false );
	}

	/**
	 * Pastikan kolom audit source ada pada instalasi lama.
	 *
	 * dbDelta tidak konsisten melakukan ALTER terhadap tabel existing pada
	 * semua versi MySQL/MariaDB, sehingga kolom ini diperiksa eksplisit.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_audit_source_column() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_audit';
		$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'source' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix terkontrol.
		if ( $column ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- nama tabel dari prefix terkontrol; schema upgrade.
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'batch' AFTER actor_via" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Tambahkan kolom token/approval run pada instalasi fase 1–4 bila belum ada.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_sync_run_approval_columns() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_sync_runs';
		$columns = array(
			'token_hash'       => 'VARCHAR(255) NULL DEFAULT NULL',
			'token_expires_at' => 'DATETIME NULL DEFAULT NULL',
			'token_used_at'    => 'DATETIME NULL DEFAULT NULL',
			'approved_by'      => 'BIGINT UNSIGNED NULL DEFAULT NULL',
			'approved_at'      => 'DATETIME NULL DEFAULT NULL',
		);
		foreach ( $columns as $name => $definition ) {
			$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
			if ( $column ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- controlled table/column names; schema upgrade.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Tambahkan nilai actor_via yang dipakai worker sync pada instalasi lama.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_audit_actor_via_column() {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_audit';
		$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'actor_via' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix terkontrol.
		if ( ! $column || empty( $column->Type ) || ( false !== strpos( (string) $column->Type, "'sync'" ) && false !== strpos( (string) $column->Type, "'cron'" ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- nama tabel dari prefix terkontrol; schema upgrade.
		$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN actor_via ENUM('mcp','wp_admin','wp_cli','system','sync','cron') NOT NULL DEFAULT 'system'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
