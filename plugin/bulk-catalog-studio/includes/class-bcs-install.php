<?php
/**
 * Rutinitas install/upgrade skema DB untuk Bulk Catalog Studio.
 *
 * Membuat 3 tabel persis spec §3.2–§3.4 via dbDelta:
 * - {prefix}bcs_jobs
 * - {prefix}bcs_plans
 * - {prefix}bcs_audit
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
			actor_via ENUM('mcp','wp_admin','wp_cli','system') NOT NULL DEFAULT 'system',
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

		// Default settings hanya diisi bila belum ada (reactivation tidak menimpa).
		add_option( 'bcs_settings', BCS_Settings::defaults() );

		update_option( 'bcs_db_version', BCS_DB_VERSION, false );
	}
}
