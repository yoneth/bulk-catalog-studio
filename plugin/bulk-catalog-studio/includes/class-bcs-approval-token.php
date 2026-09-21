<?php
/**
 * Approval token one-time (spec §1.4, gate1 keputusan #2).
 *
 * - Token acak format BCS-XXXXX (pattern ^[A-Z0-9-]+$, 6–12 char).
 * - Disimpan sebagai HASH (wp_hash_password), bukan plaintext — hanya ditampilkan
 *   SATU KALI di layar admin saat di-generate.
 * - Terikat plan_hash: plan berubah → token lama otomatis gugur.
 * - Kedaluwarsa 24 jam sejak dibuat, single-use.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Approval_Token
 */
class BCS_Approval_Token {

	const TTL_HOURS = 24;

	/**
	 * Charset token — tanpa karakter mudah tertukar (0/O, 1/I/L).
	 *
	 * @return string
	 */
	private static function charset() {
		return 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	}

	/**
	 * Buat token baru untuk plan. Token plaintext dikembalikan SEKALI untuk
	 * ditampilkan di layar admin; hanya hash yang tersimpan.
	 *
	 * @param int    $plan_id   ID plan.
	 * @param string $plan_hash plan_hash aktif.
	 * @return string|WP_Error Token plaintext.
	 */
	public static function generate( $plan_id, $plan_hash ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'bcs_plans';
		$plan   = self::get_plan( $plan_id );
		if ( ! $plan ) {
			return new WP_Error( 'bcs_not_found', __( 'Plan tidak ditemukan.', 'bulk-catalog-studio' ) );
		}

		if ( hash_equals( (string) $plan->plan_hash, (string) $plan_hash ) === false ) {
			return new WP_Error( 'bcs_hash_mismatch', __( 'plan_hash tidak cocok dengan plan tersimpan.', 'bulk-catalog-studio' ) );
		}

		// MINOR-1 (retur QA-1): 8 karakter (±39 bit entropy, charset 31) —
		// total 12 karakter, masih dalam maxLength 12 schema input approve.
		$chars = self::charset();
		$suffix = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$suffix .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
		}
		$token = 'BCS-' . $suffix;

		$now = BCS_Settings::now_mysql();
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + ( self::TTL_HOURS * HOUR_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$updated = $wpdb->update(
			$table,
			array(
				'token_hash'       => wp_hash_password( $token ),
				'token_expires_at' => $expires,
				'token_used_at'    => null,
			),
			array( 'id' => absint( $plan_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'bcs_db_error', __( 'Gagal menyimpan token approval.', 'bulk-catalog-studio' ) );
		}

		return $token;
	}

	/**
	 * Verifikasi token terhadap plan (spec §1.4).
	 *
	 * @param int    $plan_id            ID plan.
	 * @param string $plan_hash          plan_hash dari pemanggil.
	 * @param string $confirmation_token Token one-time.
	 * @return true|WP_Error
	 */
	public static function verify( $plan_id, $plan_hash, $confirmation_token ) {
		global $wpdb;

		$plan = self::get_plan( $plan_id );
		if ( ! $plan ) {
			return new WP_Error( 'bcs_not_found', __( 'Plan tidak ditemukan.', 'bulk-catalog-studio' ) );
		}

		// Token terikat plan_hash (spec §5.5.7): plan yang berubah diam-diam gugur.
		if ( hash_equals( (string) $plan->plan_hash, (string) $plan_hash ) === false ) {
			return new WP_Error( 'bcs_hash_mismatch', __( 'plan_hash tidak cocok — plan telah dibangun ulang. Minta diff preview terbaru.', 'bulk-catalog-studio' ) );
		}

		if ( empty( $plan->token_hash ) ) {
			return new WP_Error( 'bcs_token_missing', __( 'Belum ada token approval untuk plan ini. Generate token di layar admin (halaman diff).', 'bulk-catalog-studio' ) );
		}

		if ( ! empty( $plan->token_used_at ) ) {
			return new WP_Error( 'bcs_token_used', __( 'Token sudah pernah dipakai (single-use). Generate token baru di layar admin.', 'bulk-catalog-studio' ) );
		}

		if ( ! empty( $plan->token_expires_at ) ) {
			$expires = strtotime( $plan->token_expires_at . ' UTC' );
			if ( $expires < time() ) {
				return new WP_Error( 'bcs_token_expired', __( 'Token kedaluwarsa (24 jam). Generate token baru di layar admin.', 'bulk-catalog-studio' ) );
			}
		}

		$token = strtoupper( trim( (string) $confirmation_token ) );

		if ( ! preg_match( '/^[A-Z0-9-]{6,12}$/', $token ) ) {
			return new WP_Error( 'bcs_token_invalid', __( 'Format token tidak valid (harus 6–12 karakter A-Z0-9-).', 'bulk-catalog-studio' ) );
		}

		if ( ! wp_check_password( $token, (string) $plan->token_hash, 0 ) ) {
			return new WP_Error( 'bcs_token_invalid', __( 'Token salah. Token hanya tampil di layar admin WP (halaman diff).', 'bulk-catalog-studio' ) );
		}

		return true;
	}

	/**
	 * Tandai token terpakai + catat approver (spec §1.4, single-use).
	 *
	 * MAJOR-2 (retur QA-1): konsumsi ATOMIK — update hanya berjalan bila
	 * `token_used_at IS NULL`; `affected_rows === 1` menjamin tepat satu
	 * pemanggil yang menang (dua approve bersamaan tidak bisa membakar token
	 * dua kali). Dipanggil SETELAH transisi state job sukses agar token tidak
	 * terbakar tanpa approval bila transisi gagal.
	 *
	 * @param int $plan_id    ID plan.
	 * @param int $approved_by User ID approver.
	 * @return true|WP_Error
	 */
	public static function consume( $plan_id, $approved_by ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_plans';
		$now   = BCS_Settings::now_mysql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom, guard atomik single-use.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET token_used_at = %s, approved_by = %d, approved_at = %s WHERE id = %d AND token_used_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
				$now,
				absint( $approved_by ),
				$now,
				absint( $plan_id )
			)
		);

		if ( 1 !== (int) $affected ) {
			return new WP_Error( 'bcs_token_used', __( 'Token sudah dikonsumsi oleh proses lain (single-use atomik).', 'bulk-catalog-studio' ) );
		}

		return true;
	}

	/**
	 * Info token untuk layar admin (tanpa pernah membocorkan token plaintext).
	 *
	 * @param int $plan_id ID plan.
	 * @return array { has_token, used, expires_at, expired }
	 */
	public static function status( $plan_id ) {
		$plan = self::get_plan( $plan_id );
		if ( ! $plan ) {
			return array(
				'has_token'  => false,
				'used'       => false,
				'expires_at' => null,
				'expired'    => false,
			);
		}

		$has_token = ! empty( $plan->token_hash );
		$used      = ! empty( $plan->token_used_at );
		$expires   = $plan->token_expires_at ? strtotime( $plan->token_expires_at . ' UTC' ) : null;

		return array(
			'has_token'  => $has_token,
			'used'       => $used,
			'expires_at' => $expires ? gmdate( 'Y-m-d H:i', $expires ) . ' UTC' : null,
			'expired'    => ( null !== $expires && $expires < time() ),
		);
	}

	/**
	 * Ambil baris plan mentah.
	 *
	 * @param int $plan_id ID plan.
	 * @return object|null
	 */
	private static function get_plan( $plan_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_plans';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $plan_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
	}
}
