<?php
/**
 * Duplicate checker untuk bcs/check-duplicates (spec §1.2, AC-10).
 *
 * (a) Intra-batch: antar-baris dalam job (SKU + bila strategy sku+title juga nama).
 * (b) Existing: baris vs katalog — SKU via wc_get_product_id_by_sku
 *     (+ fallback case-insensitive), nama via pencarian LIKE + normalisasi
 *     (lowercase, tanpa tanda baca).
 *
 * Read-only: tidak mengubah produk. Hasil disimpan di plan staging (v0).
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Duplicate_Checker
 */
class BCS_Duplicate_Checker {

	/**
	 * Jalankan pengecekan duplikasi untuk job berstatus parsed.
	 *
	 * @param int    $job_id       ID job.
	 * @param string $strategy     sku | sku+title.
	 * @param string $on_duplicate flag_only | skip | update.
	 * @return array|WP_Error Hasil spec §1.2.
	 */
	public static function check( $job_id, $strategy = 'sku+title', $on_duplicate = 'flag_only' ) {
		global $wpdb;

		$job = BCS_Job_Manager::get( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'bcs_not_found', __( 'Job tidak ditemukan.', 'bulk-catalog-studio' ) );
		}

		if ( 'parsed' !== $job['status'] ) {
			return new WP_Error(
				'bcs_invalid_state',
				sprintf(
					/* translators: %s: status job */
					__( 'check-duplicates hanya boleh pada job berstatus parsed (status saat ini: %s).', 'bulk-catalog-studio' ),
					$job['status']
				)
			);
		}

		$rows = BCS_Plan_Builder::get_staging_rows( $job_id );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$by_sku = array();
		foreach ( $rows as $row ) {
			$sku = strtolower( trim( (string) $row['sku'] ) );
			if ( '' !== $sku ) {
				$by_sku[ $sku ][] = (int) $row['row_index'];
			}
		}

		// --- (a) Intra-batch ---
		$intra = array();
		foreach ( $by_sku as $sku => $indices ) {
			if ( count( $indices ) > 1 ) {
				$intra[] = array(
					'row_indices' => array_map( 'intval', $indices ),
					'sku'         => strtoupper( $sku ),
				);
			}
		}

		if ( 'sku+title' === $strategy ) {
			$by_title = array();
			foreach ( $rows as $row ) {
				$title = self::normalize_title( $row['name'] );
				if ( '' !== $title ) {
					$by_title[ $title ][] = (int) $row['row_index'];
				}
			}
			foreach ( $by_title as $title => $indices ) {
				if ( count( $indices ) > 1 ) {
					$intra[] = array(
						'row_indices' => array_map( 'intval', $indices ),
						'sku'         => null,
					);
				}
			}
		}

		// --- (b) Existing katalog ---
		$existing   = array();
		$seen_rows  = array();
		$checkable  = array();

		foreach ( $rows as $row ) {
			$checkable[ (int) $row['row_index'] ] = $row;
		}

		foreach ( $checkable as $row_index => $row ) {
			// SKU exact (case-insensitive, trim) — spec §1.2.
			$match_id = self::find_by_sku( $row['sku'] );
			if ( $match_id ) {
				$existing[] = array(
					'row_index'           => (int) $row_index,
					'sku'                 => $row['sku'],
					'existing_product_id' => (int) $match_id,
					'match_on'            => 'sku',
				);
				$seen_rows[] = (int) $row_index;
				continue;
			}

			// Nama normalized (hanya strategy sku+title, dan hanya baris tanpa SKU-match).
			if ( 'sku+title' === $strategy && ! in_array( (int) $row_index, $seen_rows, true ) ) {
				$match_id = self::find_by_title( $row['name'] );
				if ( $match_id ) {
					$existing[] = array(
						'row_index'           => (int) $row_index,
						'sku'                 => $row['sku'],
						'existing_product_id' => (int) $match_id,
						'match_on'            => 'title',
					);
					$seen_rows[] = (int) $row_index;
				}
			}
		}

		$duplicate_count = count( $intra ) + count( $existing );

		return array(
			'job_id'                   => (int) $job_id,
			'intra_batch_duplicates'   => $intra,
			'existing_matches'         => $existing,
			'duplicate_count'          => $duplicate_count,
			'on_duplicate_effective'   => in_array( $on_duplicate, array( 'flag_only', 'skip', 'update' ), true ) ? $on_duplicate : 'flag_only',
		);
	}

	/**
	 * Cari produk existing via SKU (wc_get_product_id_by_sku + fallback
	 * case-insensitive via query postmeta read-only).
	 *
	 * @param string $sku SKU.
	 * @return int|null Product ID atau null.
	 */
	public static function find_by_sku( $sku ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return null;
		}

		$found = wc_get_product_id_by_sku( $sku );
		if ( $found ) {
			return (int) $found;
		}

		// Fallback case-insensitive: SKU di katalog bisa beda kapital.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lookup meta read-only.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} AS p
				 INNER JOIN {$wpdb->postmeta} AS pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
				 WHERE LOWER(pm.meta_value) = %s
				   AND p.post_type = 'product'
				   AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				 LIMIT 1",
				strtolower( $sku )
			)
		);

		return $found ? (int) $found : null;
	}

	/**
	 * Cari produk existing via nama normalized (lowercase, tanpa tanda baca).
	 * Strategi: kandidat via LIKE lalu normalisasi + bandingkan di PHP.
	 *
	 * @param string $title Nama.
	 * @return int|null
	 */
	public static function find_by_title( $title ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			return null;
		}

		global $wpdb;

		$needle = $wpdb->esc_like( $title );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- pencarian read-only.
		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				 WHERE post_type = 'product'
				   AND post_status NOT IN ( 'trash', 'auto-draft' )
				   AND post_title LIKE %s
				 LIMIT 20",
				'%' . $needle . '%'
			)
		);

		$norm = self::normalize_title( $title );
		foreach ( (array) $candidates as $id ) {
			// get_col dengan SELECT dua kolom mengembalikan kolom pertama (ID);
			// ambil judul dari cache post.
			$existing_title = get_the_title( (int) $id );
			if ( $norm === self::normalize_title( $existing_title ) ) {
				return (int) $id;
			}
		}

		return null;
	}

	/**
	 * Normalisasi judul: lowercase, tanpa tanda baca, spasi tunggal.
	 *
	 * @param string $title Judul.
	 * @return string
	 */
	public static function normalize_title( $title ) {
		$title = strtolower( trim( (string) $title ) );
		$title = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $title );

		return trim( (string) $title );
	}
}
