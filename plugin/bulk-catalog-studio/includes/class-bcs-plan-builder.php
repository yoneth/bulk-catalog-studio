<?php
/**
 * Plan builder untuk bcs/build-plan (spec §1.3, §3.3).
 *
 * Mengubah rows hasil parse + hasil duplicate-check menjadi plan eksekusi
 * materialized: daftar operasi create_product / update_product / skip_duplicate
 * lengkap dengan payload final DAN snapshot `before` untuk operasi update.
 *
 * Plan immutable: hash plan = dasar validasi approval (spec §5.5.7).
 *
 * Struktur plan_json:
 * {
 *   job_id, source_hash, on_duplicate, defaults, image_policy,
 *   summary, operations: [ { row_index, row_key, op, product_type,
 *   target_product_id, payload, before, duplicate_check } ]
 * }
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Plan_Builder
 */
class BCS_Plan_Builder {

	/**
	 * Default defaults/image_policy (spec §1.3).
	 *
	 * @return array
	 */
	public static function default_options() {
		return array(
			'defaults' => array(
				'status'                       => 'draft',
				'catalog_visibility'           => 'visible',
				'manage_stock'                 => true,
				'tax_class'                    => null,
				'attribute_scope'              => 'global_pa',
				'seo_provider'                 => 'auto',
				'max_variations_per_product'   => 100,
			),
			'image_policy' => array(
				'mode'         => 'attachment_id_only',
				'set_alt_text' => true,
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Plan staging (versi 0) — hasil parse disimpan di sini (spec §1.1).
	 * ------------------------------------------------------------------- */

	/**
	 * Simpan hasil parse ke plan staging v0 untuk job.
	 *
	 * @param int   $job_id ID job.
	 * @param array $parse  Hasil parse (rows, warnings, dst).
	 * @return true|WP_Error
	 */
	public static function store_staging( $job_id, array $parse ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_plans';
		$now   = BCS_Settings::now_mysql();

		$payload = array(
			'parse_meta' => array(
				'detected_columns' => isset( $parse['detected_columns'] ) ? $parse['detected_columns'] : array(),
				'unmapped_columns' => isset( $parse['unmapped_columns'] ) ? $parse['unmapped_columns'] : array(),
				'warnings'         => isset( $parse['warnings'] ) ? $parse['warnings'] : array(),
				'errors'           => isset( $parse['errors'] ) ? $parse['errors'] : array(),
			),
			'rows'            => isset( $parse['rows'] ) ? $parse['rows'] : array(),
			'duplicate_check' => null,
		);

		$existing = self::get_staging_row( $job_id );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
			$wpdb->update(
				$table,
				array( 'plan_json' => wp_json_encode( $payload ) ),
				array( 'id' => (int) $existing->id ),
				array( '%s' ),
				array( '%d' )
			);
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$inserted = $wpdb->insert(
			$table,
			array(
				'job_id'     => absint( $job_id ),
				'version'    => 0,
				'plan_json'  => wp_json_encode( $payload ),
				'plan_hash'  => '',
				'status'     => 'staging',
				'created_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? true : new WP_Error( 'bcs_db_error', __( 'Failed to store the staging plan.', 'bulk-catalog-studio' ) );
	}

	/**
	 * Ambil baris plan staging v0 (mentah).
	 *
	 * @param int $job_id ID job.
	 * @return object|null
	 */
	public static function get_staging_row( $job_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_plans';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d AND version = 0 LIMIT 1", absint( $job_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
		);
	}

	/**
	 * Ambil rows hasil parse dari plan staging.
	 *
	 * @param int $job_id ID job.
	 * @return array|WP_Error
	 */
	public static function get_staging_rows( $job_id ) {
		$staging = self::get_staging_row( $job_id );
		if ( ! $staging ) {
			return new WP_Error( 'bcs_not_parsed', __( 'The job has no parse results yet (staging plan v0 missing).', 'bulk-catalog-studio' ) );
		}

		$data = json_decode( (string) $staging->plan_json, true );
		if ( ! is_array( $data ) || ! isset( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
			return new WP_Error( 'bcs_not_parsed', __( 'The staging plan contains no valid rows.', 'bulk-catalog-studio' ) );
		}

		return $data['rows'];
	}

	/**
	 * Simpan hasil duplicate-check ke staging.
	 *
	 * @param int   $job_id ID job.
	 * @param array $result Hasil BCS_Duplicate_Checker::check().
	 * @return true
	 */
	public static function store_duplicate_check( $job_id, array $result ) {
		global $wpdb;

		$staging = self::get_staging_row( $job_id );
		if ( ! $staging ) {
			return true;
		}

		$data = json_decode( (string) $staging->plan_json, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data['duplicate_check'] = array(
			'on_duplicate_effective' => isset( $result['on_duplicate_effective'] ) ? $result['on_duplicate_effective'] : 'flag_only',
			'result'                 => $result,
			'stored_at'              => BCS_Settings::now_mysql(),
		);

		$table = $wpdb->prefix . 'bcs_plans';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$wpdb->update(
			$table,
			array( 'plan_json' => wp_json_encode( $data ) ),
			array( 'id' => (int) $staging->id ),
			array( '%s' ),
			array( '%d' )
		);

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Build plan.
	 * ------------------------------------------------------------------- */

	/**
	 * Bangun plan materialized untuk job berstatus parsed.
	 *
	 * @param int    $job_id       ID job.
	 * @param string $on_duplicate skip | update (flag_only ditolak).
	 * @param array  $defaults     Defaults produk (opsional).
	 * @param array  $image_policy Kebijakan gambar (opsional).
	 * @param array  $row_overrides Daftar { row_index, op: skip|update|create }.
	 * @return array|WP_Error Hasil spec §1.3.
	 */
	public static function build( $job_id, $on_duplicate, array $defaults = array(), array $image_policy = array(), array $row_overrides = array() ) {
		if ( ! in_array( $on_duplicate, array( 'skip', 'update' ), true ) ) {
			return new WP_Error( 'bcs_invalid_input', __( "on_duplicate must be 'skip' or 'update' at the build-plan stage; 'flag_only' is rejected.", 'bulk-catalog-studio' ) );
		}

		$job = BCS_Job_Manager::get( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'bcs_not_found', __( 'Job not found.', 'bulk-catalog-studio' ) );
		}

		// MINOR-2 (retur QA-1): rebuild plan diizinkan pada job 'planned' —
		// belum ada produk tertulis; plan baru versi N+1 menggugurkan plan lama.
		if ( ! in_array( $job['status'], array( 'parsed', 'planned' ), true ) ) {
			return new WP_Error(
				'bcs_invalid_state',
				sprintf(
					/* translators: %s: status job */
					__( 'build-plan only works on jobs with status parsed or planned (current status: %s).', 'bulk-catalog-studio' ),
					$job['status']
				)
			);
		}

		$options  = self::default_options();
		$defaults = self::sanitize_defaults( array_merge( $options['defaults'], is_array( $defaults ) ? $defaults : array() ) );
		$policy   = array_merge( $options['image_policy'], is_array( $image_policy ) ? $image_policy : array() );

		// Gate1 #4: V1 hanya attachment_id_only.
		if ( 'attachment_id_only' !== $policy['mode'] ) {
			return new WP_Error( 'bcs_unsupported', __( 'image_policy.mode "sideload_url" is not available in V1 (gate1 decision #4).', 'bulk-catalog-studio' ) );
		}

		$rows = self::get_staging_rows( $job_id );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		// Hasil duplicate-check terakhir (bila ada).
		$staging = self::get_staging_row( $job_id );
		$staging_data = $staging ? json_decode( (string) $staging->plan_json, true ) : array();
		$dup_result   = ( is_array( $staging_data ) && isset( $staging_data['duplicate_check']['result'] ) )
			? $staging_data['duplicate_check']['result']
			: null;

		// Peta existing match: row_index → product_id.
		$existing_map = array();
		if ( $dup_result && ! empty( $dup_result['existing_matches'] ) ) {
			foreach ( (array) $dup_result['existing_matches'] as $match ) {
				$existing_map[ (int) $match['row_index'] ] = (int) $match['existing_product_id'];
			}
		}

		// Override per baris (spec §4 catatan implementasi).
		$overrides = array();
		foreach ( (array) $row_overrides as $ov ) {
			if ( ! is_array( $ov ) || ! isset( $ov['row_index'], $ov['op'] ) ) {
				continue;
			}
			if ( ! in_array( $ov['op'], array( 'skip', 'update', 'create' ), true ) ) {
				continue;
			}
			$overrides[ absint( $ov['row_index'] ) ] = sanitize_key( $ov['op'] );
		}

		$source_hash = (string) $job['source_hash'];
		$operations  = array();
		$summary     = array(
			'create_simple'    => 0,
			'create_variable'  => 0,
			'create_variations' => 0,
			'update_products'  => 0,
			'skip_duplicates'  => 0,
			'total_operations' => 0,
		);

		foreach ( $rows as $row ) {
			$row_index = (int) $row['row_index'];
			$row_key   = BCS_CSV_Parser::row_key( $source_hash, $row_index, $row['sku'] );
			$is_dup    = isset( $existing_map[ $row_index ] );
			$is_intra  = false;

			if ( $dup_result && ! empty( $dup_result['intra_batch_duplicates'] ) ) {
				foreach ( (array) $dup_result['intra_batch_duplicates'] as $dup ) {
					if ( in_array( $row_index, array_map( 'intval', (array) $dup['row_indices'] ), true ) ) {
						$is_intra = true;
						break;
					}
				}
			}

			// Tentukan op: override > duplikat > create.
			if ( isset( $overrides[ $row_index ] ) ) {
				$op = $overrides[ $row_index ];

				// NIT-4 (retur QA-1): override 'update' hanya valid untuk baris
				// yang benar-benar cocok produk existing; selain itu tolak saat
				// build agar tidak jatuh diam-diam ke create.
				if ( 'update' === $op && ! $is_dup ) {
					return new WP_Error(
						'bcs_invalid_override',
						sprintf(
							/* translators: 1: nomor baris */
							__( 'row_overrides op=update for row %1$d rejected: the row does not match an existing product.', 'bulk-catalog-studio' ),
							$row_index
						)
					);
				}
			} elseif ( $is_dup || $is_intra ) {
				$op = 'update' === $on_duplicate && $is_dup ? 'update' : 'skip';
			} else {
				$op = 'create';
			}

			if ( 'skip' === $op ) {
				$operations[] = array(
					'row_index'         => $row_index,
					'row_key'           => $row_key,
					'op'                => 'skip_duplicate',
					'product_type'      => null,
					'target_product_id' => $is_dup ? $existing_map[ $row_index ] : null,
					'payload'           => null,
					'before'            => null,
					'duplicate_check'   => array(
						'status'            => 'duplicate',
						'matched_product_id' => $is_dup ? $existing_map[ $row_index ] : null,
					),
				);
				$summary['skip_duplicates']++;
				continue;
			}

			if ( 'update' === $op && $is_dup ) {
				$target   = $existing_map[ $row_index ];
				$before   = self::snapshot_before( $target );
				if ( is_wp_error( $before ) ) {
					return $before;
				}

				$operations[] = array(
					'row_index'         => $row_index,
					'row_key'           => $row_key,
					'op'                => 'update_product',
					'product_type'      => null,
					'target_product_id' => $target,
					'payload'           => self::build_payload( $row, $defaults, $policy, false, true ),
					'before'            => $before,
					'duplicate_check'   => array(
						'status'             => 'duplicate',
						'matched_product_id' => $target,
					),
				);
				$summary['update_products']++;
				continue;
			}

			// create.
			$is_variable = ! empty( $row['variations'] );
			$payload     = self::build_payload( $row, $defaults, $policy, $is_variable );

			$operations[] = array(
				'row_index'         => $row_index,
				'row_key'           => $row_key,
				'op'                => 'create_product',
				'product_type'      => $is_variable ? 'variable' : 'simple',
				'target_product_id' => null,
				'payload'           => $payload,
				'before'            => null,
				'duplicate_check'   => array(
					'status'             => 'unique',
					'matched_product_id' => null,
				),
			);

			if ( $is_variable ) {
				$summary['create_variable']++;
				$summary['create_variations'] += count( $payload['variations'] );
			} else {
				$summary['create_simple']++;
			}
		}

		$summary['total_operations'] = count( $operations );

		$plan_data = array(
			'job_id'       => (int) $job_id,
			'source_hash'  => $source_hash,
			'on_duplicate' => $on_duplicate,
			'defaults'     => $defaults,
			'image_policy' => $policy,
			'summary'      => $summary,
			'operations'   => $operations,
		);

		// MINOR-2 (retur QA-1): gugurkan plan planned lama milik job ini
		// (superseded) SEBELUM plan baru dibuat — agar token/approve lama
		// tidak bisa dipakai lagi dan plan baru tidak ikut tergugurkan.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$wpdb->update(
			$wpdb->prefix . 'bcs_plans',
			array( 'status' => 'superseded' ),
			array(
				'job_id' => absint( $job_id ),
				'status' => 'planned',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		// Persist.
		$plan_id = self::persist_plan( $job_id, $plan_data );
		if ( is_wp_error( $plan_id ) ) {
			return $plan_id;
		}

		$plan_hash = self::hash_plan( $plan_data, $plan_id );

		// Simpan hash + versi.
		global $wpdb;
		$table = $wpdb->prefix . 'bcs_plans';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$wpdb->update(
			$table,
			array( 'plan_hash' => $plan_hash ),
			array( 'id' => $plan_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( 'parsed' === $job['status'] ) {
			// Transisi normal parsed → planned.
			$transition = BCS_Job_Manager::transition( $job_id, 'planned', 'bcs/build-plan', array( 'active_plan_id' => $plan_id ) );
			if ( is_wp_error( $transition ) ) {
				return $transition;
			}
		} else {
			// Rebuild pada job planned: cukup perbarui plan aktif.
			BCS_Job_Manager::update_fields( $job_id, array( 'active_plan_id' => $plan_id ) );
		}

		return array(
			'job_id'             => (int) $job_id,
			'plan_id'            => $plan_id,
			'plan_hash'          => $plan_hash,
			'summary'            => $summary,
			'diff_preview_url'   => admin_url( 'admin.php?page=bcs-jobs&view=plan&plan=' . $plan_id ),
			'approval_token_hint' => __( 'The approval token is only displayed on the WP admin screen (diff page). Ask the human.', 'bulk-catalog-studio' ),
		);
	}

	/**
	 * Sanitasi defaults dari input.
	 *
	 * @param array $defaults Nilai mentah.
	 * @return array
	 */
	private static function sanitize_defaults( array $defaults ) {
		$clean = self::default_options()['defaults'];

		if ( isset( $defaults['status'] ) && in_array( $defaults['status'], array( 'publish', 'draft' ), true ) ) {
			$clean['status'] = $defaults['status'];
		}
		if ( isset( $defaults['catalog_visibility'] ) && in_array( $defaults['catalog_visibility'], array( 'visible', 'hidden' ), true ) ) {
			$clean['catalog_visibility'] = $defaults['catalog_visibility'];
		}
		if ( isset( $defaults['manage_stock'] ) ) {
			$clean['manage_stock'] = (bool) $defaults['manage_stock'];
		}
		if ( array_key_exists( 'tax_class', $defaults ) ) {
			$clean['tax_class'] = null === $defaults['tax_class'] ? null : sanitize_text_field( (string) $defaults['tax_class'] );
		}
		if ( isset( $defaults['attribute_scope'] ) && in_array( $defaults['attribute_scope'], array( 'global_pa', 'custom' ), true ) ) {
			$clean['attribute_scope'] = $defaults['attribute_scope'];
		}
		if ( isset( $defaults['seo_provider'] ) && in_array( $defaults['seo_provider'], array( 'auto', 'yoast', 'rank_math', 'custom_fields' ), true ) ) {
			$clean['seo_provider'] = $defaults['seo_provider'];
		}
		if ( isset( $defaults['max_variations_per_product'] ) ) {
			$clean['max_variations_per_product'] = min( 100, max( 1, absint( $defaults['max_variations_per_product'] ) ) );
		}

		return $clean;
	}

	/**
	 * Bangun payload produk dari baris normalisasi (kontrak spec §3.3).
	 *
	 * BLOCKER-1 (retur QA-1): untuk operasi update, payload TIDAK membawa
	 * `status` (defaults.status adalah "status produk BARU" — spec §1.3) dan
	 * TIDAK membawa `stock_quantity` bila kolom stok kosong di baris (parser
	 * men-default-nya 0 hanya untuk konteks create). Field yang tidak
	 * dikirim sumber tidak boleh tersentuh.
	 *
	 * @param array $row         Baris kanonik.
	 * @param array $defaults    Defaults.
	 * @param array $policy      Image policy.
	 * @param bool  $is_variable Variable?
	 * @param bool  $for_update  True bila payload untuk op update_product.
	 * @return array
	 */
	private static function build_payload( array $row, array $defaults, array $policy, $is_variable, $for_update = false ) {
		$stock_explicit = ! empty( $row['stock_provided'] );

		$payload = array(
			'name'              => sanitize_text_field( $row['name'] ),
			'description'       => self::sanitize_html( $row['description'] ),
			'short_description' => self::sanitize_html( $row['short_description'] ),
			'sku'               => sanitize_text_field( $row['sku'] ),
			'regular_price'     => null !== $row['regular_price'] ? (string) $row['regular_price'] : null,
			'sale_price'        => null !== $row['sale_price'] ? (string) $row['sale_price'] : null,
			'catalog_visibility' => $defaults['catalog_visibility'],
			'manage_stock'      => (bool) $defaults['manage_stock'],
			'tax_class'         => $defaults['tax_class'],
			'weight'            => null !== $row['weight'] ? (string) $row['weight'] : null,
			'dimensions'        => array(
				'length' => null !== $row['dimensions']['length'] ? (string) $row['dimensions']['length'] : '',
				'width'  => null !== $row['dimensions']['width'] ? (string) $row['dimensions']['width'] : '',
				'height' => null !== $row['dimensions']['height'] ? (string) $row['dimensions']['height'] : '',
			),
			'categories'        => array_map( 'sanitize_text_field', (array) $row['categories'] ),
			'tags'              => array_map( 'sanitize_text_field', (array) $row['tags'] ),
			'attributes'        => array(),
			'variations'        => array(),
			'image'             => null,
			'seo'               => null,
		);

		// Status hanya relevan untuk produk BARU (spec §1.3: defaults.status
		// = "Status produk baru"); update tidak pernah mengubah status.
		if ( ! $for_update ) {
			$payload['status'] = $defaults['status'];
		}

		// Stok: bila kolom stok tidak dikirim di baris, jangan bawa nilai
		// default 0 ke produk existing (parser men-default 0 hanya utk create).
		if ( ! $for_update || $stock_explicit ) {
			$payload['stock_quantity'] = (int) $row['stock_quantity'];
		}

		// Atribut + variasi hanya untuk variable.
		if ( $is_variable ) {
			foreach ( (array) $row['attributes'] as $attr ) {
				$payload['attributes'][] = array(
					// attribute_taxonomy() idempotent + mempertahankan format
					// pa_ukuran (underscore) — sanitize_title akan merusaknya
					// menjadi pa-ukuran (dash) dan memutus pairing variasi.
					'name'      => BCS_CSV_Parser::attribute_taxonomy( $attr['name'] ),
					'label'     => sanitize_text_field( isset( $attr['label'] ) ? $attr['label'] : '' ),
					'options'   => array_map( 'sanitize_text_field', (array) $attr['options'] ),
					'variation' => true,
					'visible'   => true,
					'scope'     => $defaults['attribute_scope'],
				);
			}

			$max_variations = (int) $defaults['max_variations_per_product'];
			$variations     = array_slice( (array) $row['variations'], 0, $max_variations );

			foreach ( $variations as $var ) {
				$var_attributes = array();
				foreach ( (array) $var['attributes'] as $tax => $value ) {
					$var_attributes[ BCS_CSV_Parser::attribute_taxonomy( $tax ) ] = sanitize_text_field( $value );
				}

				$payload['variations'][] = array(
					'sku'            => sanitize_text_field( $var['sku'] ),
					'attributes'     => $var_attributes,
					'regular_price'  => null !== $var['regular_price'] ? (string) $var['regular_price'] : (string) ( $row['regular_price'] ?? '' ),
					'sale_price'     => null !== $var['sale_price'] ? (string) $var['sale_price'] : null,
					'stock_quantity' => (int) $var['stock_quantity'],
					'weight'         => null !== $var['weight'] ? (string) $var['weight'] : null,
					'dimensions'     => array(
						'length' => '',
						'width'  => '',
						'height' => '',
					),
				);
			}
		}

		// Gambar: attachment_id_only (gate1 #4).
		if ( ! empty( $row['image_id'] ) ) {
			$payload['image'] = array(
				'attachment_id' => absint( $row['image_id'] ),
				'alt_text'      => $policy['set_alt_text'] ? sanitize_text_field( $row['image_alt'] ) : '',
			);
		}

		// SEO meta.
		if ( '' !== trim( (string) $row['seo_title'] ) || '' !== trim( (string) $row['seo_description'] ) ) {
			$payload['seo'] = array(
				'provider'   => $defaults['seo_provider'],
				'title'       => sanitize_text_field( $row['seo_title'] ),
				'description' => sanitize_text_field( $row['seo_description'] ),
			);
		}

		return $payload;
	}

	/**
	 * Snapshot `before` untuk operasi update — bahan rollback (spec §3.3).
	 *
	 * @param int $product_id Product ID.
	 * @return array|WP_Error
	 */
	private static function snapshot_before( $product_id ) {
		if ( ! class_exists( 'WC_Product' ) || ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'bcs_missing_woocommerce', __( 'WooCommerce is not active — cannot create the before snapshot.', 'bulk-catalog-studio' ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'bcs_not_found',
				sprintf(
					/* translators: %d: product ID */
					__( 'Existing product #%d could not be loaded for the before snapshot.', 'bulk-catalog-studio' ),
					(int) $product_id
				)
			);
		}

		$image_id = $product->get_image_id( 'edit' );

		$before = array(
			'name'               => $product->get_name( 'edit' ),
			'slug'               => $product->get_slug( 'edit' ),
			'status'             => $product->get_status( 'edit' ),
			'description'        => $product->get_description( 'edit' ),
			'short_description'  => $product->get_short_description( 'edit' ),
			'sku'                => $product->get_sku( 'edit' ),
			'regular_price'      => $product->get_regular_price( 'edit' ),
			'sale_price'         => $product->get_sale_price( 'edit' ),
			'manage_stock'       => $product->get_manage_stock( 'edit' ),
			'stock_quantity'     => $product->get_stock_quantity( 'edit' ),
			'weight'             => $product->get_weight( 'edit' ),
			'dimensions'         => array(
				'length' => $product->get_length( 'edit' ),
				'width'  => $product->get_width( 'edit' ),
				'height' => $product->get_height( 'edit' ),
			),
			'catalog_visibility' => $product->get_catalog_visibility( 'edit' ),
			'tax_class'          => $product->get_tax_class( 'edit' ),
			'category_ids'       => array_map( 'intval', (array) $product->get_category_ids( 'edit' ) ),
			'tag_ids'            => array_map( 'intval', (array) $product->get_tag_ids( 'edit' ) ),
			'image_id'           => $image_id ? (int) $image_id : null,
			'image_alt'          => $image_id ? (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '',
			'seo'                => BCS_Catalog_Writer::read_seo_meta( $product ),
		);

		return $before;
	}

	/**
	 * Sanitasi HTML konten (wp_kses_post).
	 *
	 * @param string $html HTML mentah.
	 * @return string
	 */
	private static function sanitize_html( $html ) {
		return wp_kses_post( (string) $html );
	}

	/**
	 * Persist plan baru (versi = versi terakhir + 1) berstatus planned.
	 *
	 * @param int   $job_id    ID job.
	 * @param array $plan_data Data plan.
	 * @return int|WP_Error ID plan.
	 */
	private static function persist_plan( $job_id, array $plan_data ) {
		global $wpdb;

		$table   = $wpdb->prefix . 'bcs_plans';
		$job_id  = absint( $job_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$last_version = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(MAX(version), 0) FROM {$table} WHERE job_id = %d", $job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$inserted = $wpdb->insert(
			$table,
			array(
				'job_id'     => $job_id,
				'version'    => $last_version + 1,
				'plan_json'  => wp_json_encode( $plan_data ),
				'plan_hash'  => '',
				'status'     => 'planned',
				'created_at' => BCS_Settings::now_mysql(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'bcs_db_error', __( 'Failed to save the plan.', 'bulk-catalog-studio' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Hash plan (sha1 dari plan_json + plan_id agar unik dan stabil).
	 *
	 * @param array $plan_data Data plan.
	 * @param int   $plan_id   ID plan.
	 * @return string sha1 40 char.
	 */
	public static function hash_plan( array $plan_data, $plan_id ) {
		// plan_id disertakan agar hash terikat pada baris plan (approval tidak
		// bisa dipindah antar plan). Isi operasional tetap bagian utama hash.
		return sha1( absint( $plan_id ) . '|' . (string) wp_json_encode( $plan_data ) );
	}

	/* ---------------------------------------------------------------------
	 * Helper plan (dipakai executor, admin, rollback).
	 * ------------------------------------------------------------------- */

	/**
	 * Ambil baris plan mentah (publik — dipakai executor/job manager).
	 *
	 * @param int $plan_id ID plan.
	 * @return object|null
	 */
	public static function get_plan_row( $plan_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_plans';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $plan_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
	}

	/**
	 * Ambil plan ter-decode untuk job (versi terakhir).
	 *
	 * @param int $job_id ID job.
	 * @return array|null { row, data }
	 */
	public static function get_latest_plan( $job_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_plans';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d AND version > 0 ORDER BY version DESC LIMIT 1", absint( $job_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'row'  => $row,
			'data' => self::decode_plan( $row ),
		);
	}

	/**
	 * Decode plan_json dari baris plan.
	 *
	 * @param object $row Baris plan.
	 * @return array
	 */
	public static function decode_plan( $row ) {
		$data = json_decode( (string) $row->plan_json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Update status plan.
	 *
	 * @param int    $plan_id ID plan.
	 * @param string $status  planned|approved|executed|executed_with_errors|rolled_back.
	 * @return true|WP_Error
	 */
	public static function set_status( $plan_id, $status ) {
		global $wpdb;

		$allowed = array( 'planned', 'approved', 'executed', 'executed_with_errors', 'rolled_back' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'bcs_invalid_input', __( 'Invalid plan status.', 'bulk-catalog-studio' ) );
		}

		$table = $wpdb->prefix . 'bcs_plans';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$updated = $wpdb->update( $table, array( 'status' => $status ), array( 'id' => absint( $plan_id ) ), array( '%s' ), array( '%d' ) );

		return false === $updated ? new WP_Error( 'bcs_db_error', __( 'Failed to update the plan status.', 'bulk-catalog-studio' ) ) : true;
	}
}
