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
	 * Sanitize a settings form submission.
	 *
	 * Kept here rather than in the admin screen so it can be unit tested without
	 * WordPress: these values decide whether the site will fetch a remote URL
	 * and how much history it will delete, so they are worth testing directly.
	 *
	 * Unknown keys are dropped and every value is clamped, so a crafted POST
	 * cannot widen the sideload whitelist to something the validator would then
	 * trust, or set a batch size large enough to lock the log tables.
	 *
	 * @param array $raw Raw input, typically $_POST.
	 * @return array Clean settings ready to merge over the defaults.
	 */
	public static function sanitize_input( array $raw ) {
		$clean = array();

		$clean['allow_sideload'] = ! empty( $raw['allow_sideload'] );

		$hosts = array();
		$whitelist_raw = isset( $raw['sideload_domain_whitelist'] ) ? (string) $raw['sideload_domain_whitelist'] : '';
		foreach ( preg_split( '/[\r\n,]+/', $whitelist_raw ) as $line ) {
			$host = strtolower( trim( (string) $line ) );
			if ( '' === $host ) {
				continue;
			}
			// Accept a bare host only. A scheme, path, port or wildcard would
			// never match validate_sideload_url()'s host comparison anyway, so
			// letting one be saved would just look like it worked.
			$host = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $host );
			$host = explode( '/', $host )[0];
			$host = explode( ':', $host )[0];
			if ( '' === $host ) {
				continue;
			}
			// A literal IP can never pass the URL validator, so it is not a
			// useful whitelist entry; dropping it here avoids a false sense of
			// having allowed something.
			if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host ) ) {
				continue;
			}
			$hosts[ $host ] = true;
		}
		$clean['sideload_domain_whitelist'] = array_keys( $hosts );

		$clean['sideload_timeout'] = self::clamp_int( isset( $raw['sideload_timeout'] ) ? $raw['sideload_timeout'] : 15, 1, 120, 15 );

		// Retention of 0 means "never purge this table", so zero is allowed.
		$clean['audit_retention_days']     = self::clamp_int( isset( $raw['audit_retention_days'] ) ? $raw['audit_retention_days'] : 90, 0, 3650, 90 );
		$clean['sync_runs_retention_days'] = self::clamp_int( isset( $raw['sync_runs_retention_days'] ) ? $raw['sync_runs_retention_days'] : 90, 0, 3650, 90 );
		$clean['purge_batch_size']         = self::clamp_int( isset( $raw['purge_batch_size'] ) ? $raw['purge_batch_size'] : 200, 1, 1000, 200 );

		return $clean;
	}

	/**
	 * Clamp a submitted value into a usable integer range.
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $min     Lower bound.
	 * @param int   $max     Upper bound.
	 * @param int   $default Value used when the input is not numeric.
	 * @return int
	 */
	private static function clamp_int( $value, $min, $max, $default ) {
		if ( ! is_numeric( $value ) ) {
			return (int) $default;
		}
		return (int) max( $min, min( $max, (int) $value ) );
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
