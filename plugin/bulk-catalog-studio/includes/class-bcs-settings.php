<?php
/**
 * Settings helper untuk Bulk Catalog Studio.
 *
 * Menyimpan konfigurasi plugin pada option `bcs_settings` (spec §3.5).
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Settings
 */
class BCS_Settings {

	/**
	 * Nilai default settings (spec §3.5 + §5.3).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'max_rows_per_job'      => 100,     // Batas lunak per job; maksimum absolut 500.
			'max_rows_hard_limit'   => 500,     // Batas keras (AC-12: 600 baris ditolak).
			'max_inline_bytes'      => 2000000, // 2 MB (spec §5.3).
			'max_attachment_bytes'  => 10485760, // 10 MB (spec §5.3).
			'rate_limit_per_minute' => 60,      // Panggilan ability per user per menit (§5.3).
			'allow_sideload'        => false,   // Gate1 #4 dibuka untuk V1.1: sideload hanya bila dinyalakan DAN host masuk whitelist.
			'sideload_domain_whitelist' => array( 'cf.djavaweb.com', 'img.lazcdn.com' ), // Host yang boleh disideload; kosong = tolak semua.
			'sideload_timeout'      => 15,      // Detik per unduhan gambar.
			'audit_retention_days'      => 90,  // Baris audit lebih tua dari ini boleh dipangkas.
			'sync_runs_retention_days'  => 90,  // Run sync lebih tua dari ini boleh dipangkas.
			'purge_batch_size'          => 200, // Baris per iterasi purge; kecil supaya tidak mengunci tabel.
			'allow_url_source'      => true,    // URL export CSV publik Google Sheets (gate1 #1).
			'execution_time_budget' => 20.0,    // Detik; sisanya dilanjutkan via resume.
		);
	}

	/**
	 * Ambil seluruh settings (merged dengan default).
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( 'bcs_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Ambil satu nilai setting.
	 *
	 * @param string $key     Kunci setting.
	 * @param mixed  $default Fallback bila kunci tidak ada.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Waktu sekarang format MySQL (UTC) untuk konsistensi kolom DATETIME.
	 *
	 * @return string
	 */
	public static function now_mysql() {
		return current_time( 'mysql', true );
	}
}
