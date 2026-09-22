<?php
/**
 * Catalog writer — SATU-SATUNYA service class yang menulis produk (spec §5.5).
 *
 * Aturan keras:
 * 1. Hanya memakai WC_Product / WC_Product_Variable / WC_Product_Variation
 *    CRUD + wc_get_product. TANPA wp_insert_post / update_post_meta langsung.
 * 2. Meta yang di-whitelist:
 *    - meta plugin: _bcs_row_key, _bcs_job_id, _bcs_plan_id, _bcs_sync_hash,
 *      _bcs_synced_at, _bcs_connection_id, _bcs_conflict_flag
 *    - meta SEO: _yoast_wpseo_title, _yoast_wpseo_metadesc,
 *      rank_math_title, rank_math_description (hanya provider yang relevan)
 *    - meta attachment core: _wp_attachment_image_alt (untuk alt text, gate1 #4)
 *    Semua lewat API WC_Product (update_meta_data) atau update_post_meta HANYA
 *    untuk attachment alt.
 * 3. Whitelist post type hasil tulis: product, product_variation.
 *    Whitelist taxonomies: product_cat, product_tag, pa_*.
 * 4. Guard WooCommerce aktif: class_exists( 'WC_Product' ).
 * 5. SKU unik diverifikasi ulang saat eksekusi (wc_get_product_id_by_sku) —
 *    perlindungan race antar approval (spec §5.4).
 * 6. Setelah menulis variasi wajib WC_Product_Variable::sync().
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Catalog_Writer
 */
class BCS_Catalog_Writer {

	/**
	 * Meta plugin (spec §5.5.2).
	 */
	const BCS_META_WHITELIST = array( '_bcs_row_key', '_bcs_job_id', '_bcs_plan_id' );

	/**
	 * Meta SEO per provider (whitelist, spec §5.5.2).
	 *
	 * @return array
	 */
	public static function seo_meta_map() {
		return array(
			'yoast'      => array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ),
			'rank_math'  => array( 'rank_math_title', 'rank_math_description' ),
		);
	}

	/**
	 * Meta attachment core yang boleh ditulis (alt text).
	 */
	const ATTACHMENT_META_WHITELIST = array( '_wp_attachment_image_alt' );

	/**
	 * Guard WooCommerce aktif.
	 *
	 * @return true|WP_Error
	 */
	public static function guard() {
		if ( ! class_exists( 'WC_Product' ) || ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'bcs_missing_woocommerce', __( 'WooCommerce is not active — catalog write abilities are rejected (class_exists WC_Product guard).', 'bulk-catalog-studio' ) );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Validasi (dipakai execute-batch untuk dry_run).
	 * ------------------------------------------------------------------- */

	/**
	 * Validasi satu operasi tanpa menulis.
	 *
	 * @param array $op Operasi plan.
	 * @return true|WP_Error
	 */
	public static function validate_operation( array $op ) {
		if ( empty( $op['op'] ) ) {
			return new WP_Error( 'bcs_invalid_plan', __( 'Plan operation is missing the op field.', 'bulk-catalog-studio' ) );
		}

		if ( 'skip_duplicate' === $op['op'] ) {
			return true;
		}

		if ( 'create_product' === $op['op'] ) {
			$payload = is_array( $op['payload'] ?? null ) ? $op['payload'] : array();

			if ( '' === trim( (string) ( $payload['name'] ?? '' ) ) ) {
				return new WP_Error( 'bcs_validation', __( 'Create payload has no name.', 'bulk-catalog-studio' ) );
			}
			if ( '' === trim( (string) ( $payload['sku'] ?? '' ) ) ) {
				return new WP_Error( 'bcs_validation', __( 'Create payload has no SKU.', 'bulk-catalog-studio' ) );
			}

			// SKU harus bebas (verifikasi ulang saat eksekusi juga berjalan).
			$existing = BCS_Duplicate_Checker::find_by_sku( $payload['sku'] );
			if ( $existing ) {
				return new WP_Error(
					'bcs_duplicate_sku',
					sprintf(
						/* translators: 1: SKU, 2: product ID */
						__( 'SKU %1$s is already used by product #%2$d.', 'bulk-catalog-studio' ),
						$payload['sku'],
						(int) $existing
					)
				);
			}

			// SKU variasi harus bebas + unik intra-payload.
			$seen_variation_skus = array();
			foreach ( (array) ( $payload['variations'] ?? array() ) as $var ) {
				$vsku = trim( (string) ( $var['sku'] ?? '' ) );
				if ( '' === $vsku ) {
					return new WP_Error( 'bcs_validation', __( 'A variation is missing a SKU.', 'bulk-catalog-studio' ) );
				}
				$key = strtolower( $vsku );
				if ( isset( $seen_variation_skus[ $key ] ) ) {
					return new WP_Error(
						'bcs_duplicate_sku',
						sprintf(
							/* translators: %s: SKU variasi */
							__( 'Variation SKU %s is duplicated within the payload.', 'bulk-catalog-studio' ),
							$vsku
						)
					);
				}
				$seen_variation_skus[ $key ] = true;

				$existing_var = BCS_Duplicate_Checker::find_by_sku( $vsku );
				if ( $existing_var ) {
					return new WP_Error(
						'bcs_duplicate_sku',
						sprintf(
							/* translators: 1: SKU variasi, 2: product ID */
							__( 'Variation SKU %1$s is already used by product #%2$d.', 'bulk-catalog-studio' ),
							$vsku,
							(int) $existing_var
						)
					);
				}
			}

			// Attachment valid?
			$image_error = self::validate_attachment( $payload['image'] ?? null );
			if ( is_wp_error( $image_error ) ) {
				return $image_error;
			}

			return true;
		}

		if ( 'update_product' === $op['op'] ) {
			$target = absint( $op['target_product_id'] ?? 0 );
			if ( $target < 1 ) {
				return new WP_Error( 'bcs_validation', __( 'Update operation has no target_product_id.', 'bulk-catalog-studio' ) );
			}

			$product = wc_get_product( $target );
			if ( ! $product instanceof WC_Product ) {
				return new WP_Error(
					'bcs_validation',
					sprintf(
						/* translators: %d: product ID */
						__( 'Target product #%d not found.', 'bulk-catalog-studio' ),
						$target
					)
				);
			}

			return true;
		}

		return new WP_Error(
			'bcs_invalid_plan',
			sprintf(
				/* translators: %s: nama op */
				__( 'Unknown plan operation type: %s', 'bulk-catalog-studio' ),
				sanitize_text_field( (string) $op['op'] )
			)
		);
	}

	/**
	 * Validasi attachment (post_type=attachment).
	 *
	 * @param array|null $image Definisi gambar payload.
	 * @return true|WP_Error
	 */
	private static function validate_attachment( $image ) {
		if ( empty( $image ) || empty( $image['attachment_id'] ) ) {
			return true; // Gambar opsional.
		}

		$attachment = get_post( absint( $image['attachment_id'] ) );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'bcs_validation',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Attachment #%d is invalid (not post_type attachment).', 'bulk-catalog-studio' ),
					absint( $image['attachment_id'] )
				)
			);
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Eksekusi operasi.
	 * ------------------------------------------------------------------- */

	/**
	 * Eksekusi satu operasi plan (create / update / skip).
	 *
	 * @param array $op       Operasi plan.
	 * @param int   $job_id   ID job (untuk meta _bcs_*).
	 * @param int   $plan_id  ID plan (untuk meta _bcs_*).
	 * @return array|WP_Error { product_id, variations_created }
	 */
	public static function execute_operation( array $op, $job_id, $plan_id ) {
		$guard = self::guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		switch ( $op['op'] ) {
			case 'skip_duplicate':
				return array(
					'product_id'        => isset( $op['target_product_id'] ) ? (int) $op['target_product_id'] : null,
					'variations_created' => 0,
				);

			case 'create_product':
				return self::create_product( $op, $job_id, $plan_id );

			case 'update_product':
				return self::update_product( $op, $job_id, $plan_id );
		}

		return new WP_Error(
			'bcs_invalid_plan',
			sprintf(
				/* translators: %s: nama op */
				__( 'Unknown plan operation type: %s', 'bulk-catalog-studio' ),
				sanitize_text_field( (string) $op['op'] )
			)
		);
	}

	/**
	 * Update only scalar fields on an existing variation.
	 *
	 * Attributes and variation structure are intentionally immutable here.
	 *
	 * @param int    $product_id     Parent product ID.
	 * @param string $variation_sku  Existing variation SKU key.
	 * @param array  $payload        Scalar fields from sync.
	 * @param array  $options        Sync metadata/options.
	 * @return array|WP_Error { product_id, variation_id, before }
	 */
	public static function update_variation( $product_id, $variation_sku, array $payload, array $options = array() ) {
		$guard = self::guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$parent = wc_get_product( absint( $product_id ) );
		if ( ! $parent instanceof WC_Product ) {
			return new WP_Error( 'bcs_not_found', __( 'Parent product not found for variation update.', 'bulk-catalog-studio' ) );
		}

		$wanted_sku = trim( (string) $variation_sku );
		$variation = null;
		$children = method_exists( $parent, 'get_children' ) ? (array) $parent->get_children( 'edit' ) : array();
		foreach ( $children as $child_id ) {
			$child = wc_get_product( absint( $child_id ) );
			if ( $child instanceof WC_Product_Variation && strtolower( trim( (string) $child->get_sku( 'edit' ) ) ) === strtolower( $wanted_sku ) ) {
				$variation = $child;
				break;
			}
		}

		if ( ! $variation instanceof WC_Product_Variation ) {
			return new WP_Error(
				'variation_not_found',
				sprintf(
					/* translators: 1: variation SKU, 2: product ID */
					__( 'Variation SKU %1$s was not found under product #%2$d.', 'bulk-catalog-studio' ),
					$wanted_sku,
					absint( $product_id )
				)
			);
		}

		$before = self::variation_snapshot( $variation );
		$current_sku = trim( (string) $variation->get_sku( 'edit' ) );
		if ( isset( $payload['sku'] ) && '' !== trim( (string) $payload['sku'] ) && strtolower( trim( (string) $payload['sku'] ) ) !== strtolower( $current_sku ) ) {
			// The SKU is the idempotency key used to locate this variation. A
			// key change must be modeled as an explicit create/delete operation,
			// never guessed inside update_variation.
			if ( empty( $options['allow_sku_change'] ) ) {
				return new WP_Error( 'bcs_variation_sku_change', __( 'Changing a variation SKU during an update is not allowed; use an explicit variation replacement.', 'bulk-catalog-studio' ) );
			}
			$existing = BCS_Duplicate_Checker::find_by_sku( (string) $payload['sku'] );
			if ( $existing && (int) $existing !== (int) $variation->get_id() ) {
				return new WP_Error( 'bcs_duplicate_sku', __( 'The new variation SKU is already used by another product.', 'bulk-catalog-studio' ) );
			}
			$variation->set_sku( sanitize_text_field( (string) $payload['sku'] ) );
		}
		if ( array_key_exists( 'regular_price', $payload ) && null !== $payload['regular_price'] ) {
			$variation->set_regular_price( '' === (string) $payload['regular_price'] ? '' : self::decimal( $payload['regular_price'] ) );
		}
		if ( array_key_exists( 'sale_price', $payload ) && null !== $payload['sale_price'] ) {
			$variation->set_sale_price( '' === (string) $payload['sale_price'] ? '' : self::decimal( $payload['sale_price'] ) );
		}
		if ( array_key_exists( 'stock_quantity', $payload ) && null !== $payload['stock_quantity'] ) {
			$quantity = (int) $payload['stock_quantity'];
			// Same reason as apply_scalar_fields(): a quantity is ignored while
			// manage_stock is off, and the variation create path already turns it on.
			if ( ! array_key_exists( 'manage_stock', $payload ) ) {
				$variation->set_manage_stock( true );
			} else {
				$variation->set_manage_stock( (bool) $payload['manage_stock'] );
			}
			$variation->set_stock_quantity( $quantity );
			$variation->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
		}

		$variation_id = $variation->save();
		if ( ! $variation_id ) {
			return new WP_Error( 'bcs_write_failed', __( 'Failed to save the variation update.', 'bulk-catalog-studio' ) );
		}
		if ( $parent instanceof WC_Product_Variable ) {
			if ( method_exists( 'WC_Product_Variable', 'sync' ) ) {
				WC_Product_Variable::sync( $parent->get_id() );
			}
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $parent->get_id() );
			}
		}

		if ( isset( $options['sync_hash'] ) && '' !== (string) $options['sync_hash'] ) {
			$sync_state = self::set_sync_state( $variation->get_id(), (string) $options['sync_hash'], isset( $options['connection_id'] ) ? $options['connection_id'] : 0, isset( $options['synced_at'] ) ? $options['synced_at'] : null );
			if ( is_wp_error( $sync_state ) ) {
				return $sync_state;
			}
		}

		return array(
			'product_id'   => absint( $product_id ),
			'variation_id' => (int) $variation->get_id(),
			'before'       => $before,
		);
	}

	/**
	 * Soft-delete one existing variation.
	 *
	 * @param int    $product_id    Parent product ID.
	 * @param string $variation_sku Variation SKU.
	 * @return true|WP_Error
	 */
	public static function soft_delete_variation( $product_id, $variation_sku ) {
		$guard = self::guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$parent = wc_get_product( absint( $product_id ) );
		if ( ! $parent instanceof WC_Product ) {
			return new WP_Error( 'bcs_not_found', __( 'Parent product not found for variation delete.', 'bulk-catalog-studio' ) );
		}
		foreach ( (array) $parent->get_children( 'edit' ) as $child_id ) {
			$variation = wc_get_product( absint( $child_id ) );
			if ( $variation instanceof WC_Product_Variation && strtolower( trim( (string) $variation->get_sku( 'edit' ) ) ) === strtolower( trim( (string) $variation_sku ) ) ) {
				$variation->delete( false );
				return true;
			}
		}
		return new WP_Error( 'variation_not_found', __( 'Variation was not found for soft delete.', 'bulk-catalog-studio' ) );
	}

	/**
	 * Store per-record sync state through the WooCommerce CRUD object.
	 *
	 * @param int         $object_id    Product or variation ID.
	 * @param string      $sync_hash    Canonical source hash.
	 * @param int         $connection_id Connection ID.
	 * @param string|null $synced_at    MySQL UTC timestamp.
	 * @return true|WP_Error
	 */
	public static function set_sync_state( $object_id, $sync_hash, $connection_id, $synced_at = null ) {
		$object = wc_get_product( absint( $object_id ) );
		if ( ! $object instanceof WC_Product ) {
			return new WP_Error( 'bcs_not_found', __( 'Product not found while saving sync state.', 'bulk-catalog-studio' ) );
		}
		if ( null === $synced_at || '' === (string) $synced_at ) {
			$synced_at = class_exists( 'BCS_Settings' ) ? BCS_Settings::now_mysql() : gmdate( 'Y-m-d H:i:s' );
		}
		$object->update_meta_data( '_bcs_sync_hash', substr( preg_replace( '/[^a-f0-9]/i', '', (string) $sync_hash ), 0, 40 ) );
		$object->update_meta_data( '_bcs_synced_at', (string) $synced_at );
		$object->update_meta_data( '_bcs_connection_id', absint( $connection_id ) );
		$object->update_meta_data( '_bcs_conflict_flag', '' );
		$object->save();
		self::snapshot_post_modified( $object->get_id() );
		return true;
	}

	/**
	 * Record the post_modified_gmt a sync write left behind.
	 *
	 * Conflict detection used to compare post_modified against _bcs_synced_at,
	 * but the save() that stores _bcs_synced_at bumps post_modified itself, and
	 * for a variable product WooCommerce bumps the parent again when it syncs
	 * the variations. Every synced product therefore looked manually edited on
	 * the next run. Snapshotting the timestamp the write actually produced
	 * removes that false positive without loosening detection of real human
	 * edits: anything that touches the product afterwards still moves
	 * post_modified past the snapshot. A time-based grace period would not be
	 * deterministic, so it is deliberately not used.
	 *
	 * The meta is written with update_post_meta() rather than through the CRUD
	 * object on purpose — a raw meta write does not touch post_modified, so the
	 * snapshot cannot invalidate itself.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return void
	 */
	public static function snapshot_post_modified( $object_id ) {
		$object_id = absint( $object_id );
		if ( $object_id < 1 || ! function_exists( 'get_post_field' ) || ! function_exists( 'update_post_meta' ) ) {
			return;
		}
		$modified = get_post_field( 'post_modified_gmt', $object_id );
		if ( ! is_string( $modified ) || '' === $modified ) {
			return;
		}
		update_post_meta( $object_id, '_bcs_sync_post_modified', $modified );
	}

	/**
	 * Flag a record as conflicted without touching owned catalog fields.
	 *
	 * @param int         $object_id Product/variation ID.
	 * @param string|null $timestamp Conflict timestamp.
	 * @return true|WP_Error
	 */
	public static function flag_sync_conflict( $object_id, $timestamp = null ) {
		$object = wc_get_product( absint( $object_id ) );
		if ( ! $object instanceof WC_Product ) {
			return new WP_Error( 'bcs_not_found', __( 'Product not found while flagging the sync conflict.', 'bulk-catalog-studio' ) );
		}
		if ( null === $timestamp || '' === (string) $timestamp ) {
			$timestamp = class_exists( 'BCS_Settings' ) ? BCS_Settings::now_mysql() : gmdate( 'Y-m-d H:i:s' );
		}
		$object->update_meta_data( '_bcs_conflict_flag', (string) $timestamp );
		$object->save();
		return true;
	}

	/**
	 * Clear a conflict flag without changing any source-owned catalog field.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return true|WP_Error
	 */
	public static function clear_sync_conflict( $object_id ) {
		$object = wc_get_product( absint( $object_id ) );
		if ( ! $object instanceof WC_Product ) {
			return new WP_Error( 'bcs_not_found', __( 'Product not found while clearing the sync conflict.', 'bulk-catalog-studio' ) );
		}
		$object->update_meta_data( '_bcs_conflict_flag', '' );
		$object->save();
		return true;
	}

	/**
	 * Read a variation scalar snapshot for rollback/audit.
	 *
	 * @param WC_Product_Variation $variation Variation object.
	 * @return array
	 */
	private static function variation_snapshot( $variation ) {
		return array(
			'id'             => (int) $variation->get_id(),
			'parent_id'      => method_exists( $variation, 'get_parent_id' ) ? (int) $variation->get_parent_id() : 0,
			'sku'            => (string) $variation->get_sku( 'edit' ),
			'regular_price'  => (string) $variation->get_regular_price( 'edit' ),
			'sale_price'     => (string) $variation->get_sale_price( 'edit' ),
			'stock_quantity' => $variation->get_stock_quantity( 'edit' ),
			'manage_stock'   => $variation->get_manage_stock( 'edit' ),
			'stock_status'   => $variation->get_stock_status( 'edit' ),
		);
	}

	/**
	 * Buat produk (simple atau variable).
	 *
	 * @param array $op      Operasi.
	 * @param int   $job_id  Job ID.
	 * @param int   $plan_id Plan ID.
	 * @return array|WP_Error
	 */
	private static function create_product( array $op, $job_id, $plan_id ) {
		$payload = is_array( $op['payload'] ?? null ) ? $op['payload'] : array();
		$row_key = (string) ( $op['row_key'] ?? '' );

		// Verifikasi ulang SKU saat eksekusi (race antar approval, spec §5.4).
		$existing = BCS_Duplicate_Checker::find_by_sku( (string) ( $payload['sku'] ?? '' ) );
		if ( $existing ) {
			return new WP_Error(
				'bcs_duplicate_sku',
				sprintf(
					/* translators: 1: SKU, 2: product ID */
					__( 'SKU %1$s is already used by product #%2$d (detected during execution).', 'bulk-catalog-studio' ),
					(string) $payload['sku'],
					(int) $existing
				)
			);
		}

		$is_variable = 'variable' === ( $op['product_type'] ?? 'simple' );

		if ( $is_variable ) {
			$product = new WC_Product_Variable();
		} else {
			$product = new WC_Product();
		}

		self::apply_scalar_fields( $product, $payload );

		// Atribut (variable) / tanpa atribut (simple di V1).
		if ( $is_variable && ! empty( $payload['attributes'] ) ) {
			$attributes = self::build_attributes( $payload['attributes'], (string) ( $payload['sku'] ?? '' ) );
			if ( is_wp_error( $attributes ) ) {
				return $attributes;
			}
			$product->set_attributes( $attributes );
		}

		$product_id = $product->save();

		if ( ! $product_id ) {
			return new WP_Error( 'bcs_write_failed', __( 'Failed to save the new product.', 'bulk-catalog-studio' ) );
		}

		// Meta plugin idempotency (whitelist §5.5.2).
		self::tag_bcs_meta( $product, $row_key, $job_id, $plan_id );

		// Gambar.
		$image_result = self::apply_image( $product, $payload['image'] ?? null );
		// MINOR-5 (retur QA-1): warning gambar gagal tidak dibuang —
		// diteruskan ke pemanggil untuk masuk jejak audit.
		$image_warning = is_wp_error( $image_result ) ? $image_result->get_error_message() : null;

		// Kategori & tag (setelah save pertama agar term ids siap).
		self::apply_terms( $product, $payload );

		$product->save();

		// Variasi.
		$variations_created = 0;
		if ( $is_variable && ! empty( $payload['variations'] ) ) {
			foreach ( (array) $payload['variations'] as $var_payload ) {
				$result = self::create_variation( $product_id, $var_payload, $job_id, $plan_id, $row_key );
				if ( is_wp_error( $result ) ) {
					// Satu variasi gagal tidak menggagalkan produk; sisanya lanjut.
					continue;
				}
				$variations_created++;
			}

			// Wajib setelah menulis variasi (riset rudrastyh — spec §0).
			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
		}

		return array(
			'product_id'         => (int) $product_id,
			'variations_created' => $variations_created,
			'image_warning'      => $image_warning,
		);
	}

	/**
	 * Buat satu variasi.
	 *
	 * @param int    $parent_id  Parent product ID.
	 * @param array  $var        Payload variasi.
	 * @param int    $job_id     Job ID.
	 * @param int    $plan_id    Plan ID.
	 * @param string $parent_key row_key parent.
	 * @return int|WP_Error Variation ID.
	 */
	private static function create_variation( $parent_id, array $var, $job_id, $plan_id, $parent_key ) {
		$sku = trim( (string) ( $var['sku'] ?? '' ) );

		if ( '' !== $sku ) {
			$existing = BCS_Duplicate_Checker::find_by_sku( $sku );
			if ( $existing ) {
				return new WP_Error(
					'bcs_duplicate_sku',
					sprintf(
						/* translators: 1: SKU variasi, 2: product ID */
						__( 'Variation SKU %1$s is already used by product #%2$d.', 'bulk-catalog-studio' ),
						$sku,
						(int) $existing
					)
				);
			}
		}

		// Resolusi nilai atribut variasi → slug term (taxonomy attributes).
		$attributes = array();
		foreach ( (array) ( $var['attributes'] ?? array() ) as $taxonomy => $value ) {
			$taxonomy = BCS_CSV_Parser::attribute_taxonomy( $taxonomy );
			$resolved = self::ensure_term( $taxonomy, (string) $value );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$attributes[ $taxonomy ] = $resolved['slug'];
		}

		if ( empty( $attributes ) ) {
			return new WP_Error( 'bcs_validation', __( 'Variation has no attributes.', 'bulk-catalog-studio' ) );
		}

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( absint( $parent_id ) );
		$variation->set_attributes( $attributes );

		if ( '' !== $sku ) {
			$variation->set_sku( $sku );
		}

		if ( isset( $var['regular_price'] ) && '' !== (string) $var['regular_price'] ) {
			$variation->set_regular_price( self::decimal( $var['regular_price'] ) );
		}
		if ( ! empty( $var['sale_price'] ) ) {
			$variation->set_sale_price( self::decimal( $var['sale_price'] ) );
		}

		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( absint( $var['stock_quantity'] ?? 0 ) );
		$variation->set_stock_status( absint( $var['stock_quantity'] ?? 0 ) > 0 ? 'instock' : 'outofstock' );

		if ( ! empty( $var['weight'] ) ) {
			$variation->set_weight( self::decimal( $var['weight'] ) );
		}

		$variation_id = $variation->save();
		if ( ! $variation_id ) {
			return new WP_Error( 'bcs_write_failed', __( 'Failed to save the variation.', 'bulk-catalog-studio' ) );
		}

		// Meta plugin pada variasi juga (untuk rollback & pelacakan).
		$variation->update_meta_data( '_bcs_row_key', $parent_key );
		$variation->update_meta_data( '_bcs_job_id', absint( $job_id ) );
		$variation->update_meta_data( '_bcs_plan_id', absint( $plan_id ) );
		$variation->save();

		return (int) $variation_id;
	}

	/**
	 * Update produk existing (field tersentuh dari payload; variasi tidak
	 * disentuh di V1 — lihat catatan rollback di self-check).
	 *
	 * @param array $op      Operasi.
	 * @param int   $job_id  Job ID.
	 * @param int   $plan_id Plan ID.
	 * @return array|WP_Error
	 */
	private static function update_product( array $op, $job_id, $plan_id ) {
		$target  = absint( $op['target_product_id'] ?? 0 );
		$product = wc_get_product( $target );

		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'bcs_not_found',
				sprintf(
					/* translators: %d: product ID */
					__( 'Target product #%d not found during execution.', 'bulk-catalog-studio' ),
					$target
				)
			);
		}

		// Whitelist post type (spec §5.5.3).
		if ( ! in_array( $product->get_type(), array( 'simple', 'variable', 'external', 'grouped' ), true ) ) {
			return new WP_Error( 'bcs_invalid_target', __( 'Target is not a valid WooCommerce product.', 'bulk-catalog-studio' ) );
		}

		$payload = is_array( $op['payload'] ?? null ) ? $op['payload'] : array();

		// SKU berubah? Verifikasi ulang keunikan.
		if ( ! empty( $payload['sku'] ) && strtolower( trim( (string) $payload['sku'] ) ) !== strtolower( (string) $product->get_sku( 'edit' ) ) ) {
			$existing = BCS_Duplicate_Checker::find_by_sku( (string) $payload['sku'] );
			if ( $existing && (int) $existing !== $target ) {
				return new WP_Error(
					'bcs_duplicate_sku',
					sprintf(
						/* translators: 1: SKU, 2: product ID */
						__( 'SKU %1$s is already used by product #%2$d.', 'bulk-catalog-studio' ),
						(string) $payload['sku'],
						(int) $existing
					)
				);
			}
		}

		self::apply_scalar_fields( $product, $payload );
		self::apply_terms( $product, $payload );

		$image_result = self::apply_image( $product, $payload['image'] ?? null );

		self::apply_seo_meta( $product, $payload['seo'] ?? null );
		self::tag_bcs_meta( $product, (string) ( $op['row_key'] ?? '' ), $job_id, $plan_id );

		$product->save();

		if ( $product instanceof WC_Product_Variable ) {
			WC_Product_Variable::sync( $product->get_id() );
			wc_delete_product_transients( $product->get_id() );
		}

		return array(
			'product_id'         => $product->get_id(),
			'variations_created' => 0,
			'image_warning'      => is_wp_error( $image_result ) ? $image_result->get_error_message() : null,
		);
	}

	/* ---------------------------------------------------------------------
	 * Helper penerapan field.
	 * ------------------------------------------------------------------- */

	/**
	 * Terapkan field skalar payload ke objek produk.
	 *
	 * @param WC_Product $product Produk.
	 * @param array      $payload Payload.
	 * @return void
	 */
	private static function apply_scalar_fields( $product, array $payload ) {
		if ( isset( $payload['name'] ) && '' !== (string) $payload['name'] ) {
			$product->set_name( sanitize_text_field( (string) $payload['name'] ) );
		}
		if ( isset( $payload['description'] ) ) {
			$product->set_description( wp_kses_post( (string) $payload['description'] ) );
		}
		if ( isset( $payload['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( (string) $payload['short_description'] ) );
		}
		if ( ! empty( $payload['sku'] ) ) {
			$product->set_sku( sanitize_text_field( (string) $payload['sku'] ) );
		}
		if ( isset( $payload['regular_price'] ) && null !== $payload['regular_price'] && '' !== (string) $payload['regular_price'] ) {
			$product->set_regular_price( self::decimal( $payload['regular_price'] ) );
		}
		if ( isset( $payload['sale_price'] ) && null !== $payload['sale_price'] && '' !== (string) $payload['sale_price'] ) {
			$product->set_sale_price( self::decimal( $payload['sale_price'] ) );
		}
		if ( ! empty( $payload['status'] ) && in_array( $payload['status'], array( 'publish', 'draft' ), true ) ) {
			$product->set_status( $payload['status'] );
		}
		if ( ! empty( $payload['catalog_visibility'] ) && in_array( $payload['catalog_visibility'], array( 'visible', 'hidden' ), true ) ) {
			$product->set_catalog_visibility( $payload['catalog_visibility'] );
		}
		if ( isset( $payload['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $payload['manage_stock'] );
		}
		if ( isset( $payload['stock_quantity'] ) ) {
			/*
			 * WooCommerce drops a stock quantity while manage_stock is off, so
			 * without this the value never lands: _stock stays empty and every
			 * later sync run compares the source quantity against an empty WP
			 * value, reports field_changed and proposes the very same update
			 * again — the sync can never converge. A payload that carries a
			 * quantity therefore implies stock management, unless the payload
			 * said otherwise explicitly. The variation writer already does this.
			 */
			if ( ! isset( $payload['manage_stock'] ) ) {
				$product->set_manage_stock( true );
			}
			$product->set_stock_quantity( absint( $payload['stock_quantity'] ) );
			$product->set_stock_status( absint( $payload['stock_quantity'] ) > 0 ? 'instock' : 'outofstock' );
		}
		if ( isset( $payload['tax_class'] ) && null !== $payload['tax_class'] && '' !== (string) $payload['tax_class'] ) {
			$product->set_tax_class( sanitize_text_field( (string) $payload['tax_class'] ) );
		}
		if ( isset( $payload['weight'] ) && null !== $payload['weight'] && '' !== (string) $payload['weight'] ) {
			$product->set_weight( self::decimal( $payload['weight'] ) );
		}
		if ( ! empty( $payload['dimensions'] ) && is_array( $payload['dimensions'] ) ) {
			if ( isset( $payload['dimensions']['length'] ) && '' !== (string) $payload['dimensions']['length'] ) {
				$product->set_length( self::decimal( $payload['dimensions']['length'] ) );
			}
			if ( isset( $payload['dimensions']['width'] ) && '' !== (string) $payload['dimensions']['width'] ) {
				$product->set_width( self::decimal( $payload['dimensions']['width'] ) );
			}
			if ( isset( $payload['dimensions']['height'] ) && '' !== (string) $payload['dimensions']['height'] ) {
				$product->set_height( self::decimal( $payload['dimensions']['height'] ) );
			}
		}
	}

	/**
	 * Terapkan kategori & tag (whitelist taxonomy product_cat/product_tag).
	 *
	 * @param WC_Product $product Produk.
	 * @param array      $payload Payload.
	 * @return void
	 */
	private static function apply_terms( $product, array $payload ) {
		// Kategori: nama bisa hierarki "Induk > Anak".
		if ( ! empty( $payload['categories'] ) && is_array( $payload['categories'] ) ) {
			$cat_ids = array();
			foreach ( (array) $payload['categories'] as $chain ) {
				$ids = self::ensure_category_chain( (string) $chain );
				$cat_ids = array_merge( $cat_ids, $ids );
			}
			if ( ! empty( $cat_ids ) ) {
				$product->set_category_ids( array_values( array_unique( array_map( 'intval', $cat_ids ) ) ) );
			}
		}

		// Tag.
		if ( ! empty( $payload['tags'] ) && is_array( $payload['tags'] ) ) {
			$tag_ids = array();
			foreach ( (array) $payload['tags'] as $tag_name ) {
				$term = self::ensure_term( 'product_tag', (string) $tag_name );
				if ( ! is_wp_error( $term ) ) {
					$tag_ids[] = (int) $term['term_id'];
				}
			}
			if ( ! empty( $tag_ids ) ) {
				$product->set_tag_ids( array_values( array_unique( $tag_ids ) ) );
			}
		}
	}

	/**
	 * Pastikan chain kategori "Induk > Anak" ada; kembalikan semua term id chain.
	 *
	 * @param string $chain Chain kategori.
	 * @return array Term IDs (urutan hierarki).
	 */
	private static function ensure_category_chain( $chain ) {
		$parts = array_map( 'trim', explode( '>', (string) $chain ) );
		$parts = array_values( array_filter( $parts, array( __CLASS__, 'string_not_empty' ) ) );

		$ids    = array();
		$parent = 0;

		foreach ( $parts as $name ) {
			$existing = get_term_by( 'name', $name, 'product_cat' );

			if ( $existing instanceof WP_Term ) {
				// Cocokkan parent bila ada banyak term senama.
				if ( 0 !== $parent ) {
					$with_parent = get_terms(
						array(
							'taxonomy' => 'product_cat',
							'name'     => $name,
							'parent'   => $parent,
							'hide_empty' => false,
							'number'   => 1,
							'fields'   => 'ids',
						)
					);
					$term_id = ! is_wp_error( $with_parent ) && ! empty( $with_parent ) ? (int) $with_parent[0] : (int) $existing->term_id;
				} else {
					$term_id = (int) $existing->term_id;
				}
			} else {
				$inserted = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
				if ( is_wp_error( $inserted ) && isset( $inserted->error_data['term_exists'] ) ) {
					$term_id = (int) $inserted->error_data['term_exists'];
				} elseif ( is_wp_error( $inserted ) ) {
					continue;
				} else {
					$term_id = (int) $inserted['term_id'];
				}
			}

			$ids[]   = $term_id;
			$parent  = $term_id;
		}

		return $ids;
	}

	/**
	 * Callback string non-kosong.
	 *
	 * @param string $v Nilai.
	 * @return bool
	 */
	private static function string_not_empty( $v ) {
		return '' !== trim( (string) $v );
	}

	/**
	 * Pastikan term ada pada taxonomy (whitelist pa_* / product_tag / product_cat);
	 * buat bila belum ada. Mengembalikan term_id + slug.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     Nama term.
	 * @return array|WP_Error { term_id, slug }
	 */
	public static function ensure_term( $taxonomy, $name ) {
		/*
		 * Only attribute taxonomies go through attribute_taxonomy(): it always
		 * returns a 'pa_'-prefixed slug, so passing a core taxonomy through it
		 * turned 'product_tag' into 'pa_product_tag'. The whitelist below then
		 * never matched product_tag, and the term was created in an attribute
		 * taxonomy instead. set_tag_ids() received an id that is not a
		 * product_tag, so tags silently never landed on the product and every
		 * later sync run saw the source tags against an empty WP value and
		 * proposed the same update again. Categories escaped this because they
		 * go through ensure_category_chain(), not ensure_term().
		 */
		$taxonomy = trim( (string) $taxonomy );
		if ( ! in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true ) ) {
			// Normalisasi via attribute_taxonomy() (bukan sanitize_title yang
			// mengubah underscore menjadi dash dan memutus format pa_ukuran).
			$taxonomy = BCS_CSV_Parser::attribute_taxonomy( $taxonomy );
		}
		$name     = trim( (string) $name );

		// Whitelist taxonomy (spec §5.5.3).
		$is_product_cat  = 'product_cat' === $taxonomy;
		$is_product_tag  = 'product_tag' === $taxonomy;
		$is_pa           = 0 === strpos( $taxonomy, 'pa_' ) && strlen( $taxonomy ) > 3;

		if ( ! $is_product_cat && ! $is_product_tag && ! $is_pa ) {
			return new WP_Error(
				'bcs_taxonomy_forbidden',
				sprintf(
					/* translators: %s: nama taxonomy */
					__( 'Taxonomy "%s" is outside the whitelist (product_cat, product_tag, pa_*).', 'bulk-catalog-studio' ),
					$taxonomy
				)
			);
		}

		if ( '' === $name ) {
			return new WP_Error( 'bcs_validation', __( 'Term name is empty.', 'bulk-catalog-studio' ) );
		}

		// Untuk pa_* global: pastikan attribute taxonomy terdaftar dulu.
		if ( $is_pa ) {
			$ensured = self::ensure_global_attribute( $taxonomy );
			if ( is_wp_error( $ensured ) ) {
				return $ensured;
			}
		}

		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term instanceof WP_Term ) {
			return array(
				'term_id' => (int) $term->term_id,
				'slug'    => $term->slug,
			);
		}

		$slug = sanitize_title( $name );

		// Slug bisa sudah ada dengan nama beda — pakai slug eksplisit.
		$existing_by_slug = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing_by_slug instanceof WP_Term ) {
			return array(
				'term_id' => (int) $existing_by_slug->term_id,
				'slug'    => $existing_by_slug->slug,
			);
		}

		$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $inserted ) ) {
			if ( isset( $inserted->error_data['term_exists'] ) ) {
				$term_id = (int) $inserted->error_data['term_exists'];
				$term    = get_term( $term_id, $taxonomy );

				return array(
					'term_id' => $term_id,
					'slug'    => $term instanceof WP_Term ? $term->slug : $slug,
				);
			}

			return $inserted;
		}

		return array(
			'term_id' => (int) $inserted['term_id'],
			'slug'    => isset( $inserted['slug'] ) ? $inserted['slug'] : $slug,
		);
	}

	/**
	 * Pastikan global attribute taxonomy pa_* terdaftar (wc_attribute_taxonomies
	 * + register_taxonomy untuk request saat ini).
	 *
	 * @param string $taxonomy Taxonomy pa_*.
	 * @return true|WP_Error
	 */
	public static function ensure_global_attribute( $taxonomy ) {
		if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) || ! function_exists( 'wc_create_attribute' ) ) {
			return new WP_Error( 'bcs_missing_woocommerce', __( 'WooCommerce attribute functions are unavailable.', 'bulk-catalog-studio' ) );
		}

		$slug = self::pa_slug( $taxonomy );

		$existing_id = wc_attribute_taxonomy_id_by_name( $slug );
		if ( $existing_id ) {
			// Pastikan taxonomy ter-registrasi untuk request ini.
			if ( ! taxonomy_exists( $taxonomy ) ) {
				self::register_pa_taxonomy( $taxonomy, $slug );
			}

			return true;
		}

		$attribute_id = wc_create_attribute(
			array(
				'name'         => ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);

		if ( is_wp_error( $attribute_id ) ) {
			// Bisa jadi race dengan proses lain — cek lagi.
			$existing_id = wc_attribute_taxonomy_id_by_name( $slug );
			if ( $existing_id ) {
				return true;
			}

			return $attribute_id;
		}

		// Register taxonomy untuk request saat ini + invalidate cache WC.
		self::register_pa_taxonomy( $taxonomy, $slug );
		delete_transient( 'wc_attribute_taxonomies' );

		return true;
	}

	/**
	 * Register taxonomy pa_* untuk request berjalan.
	 *
	 * @param string $taxonomy Taxonomy pa_*.
	 * @param string $slug     Slug tanpa prefix.
	 * @return void
	 */
	private static function register_pa_taxonomy( $taxonomy, $slug ) {
		if ( taxonomy_exists( $taxonomy ) ) {
			return;
		}

		register_taxonomy(
			$taxonomy,
			apply_filters( 'woocommerce_taxonomy_objects_product_attributes', array( 'product' ) ),
			array(
				'hierarchical'          => false,
				'show_ui'               => false,
				'show_in_nav_menus'     => false,
				'query_var'             => is_admin(),
				'rewrite'               => false,
				'public'                => false,
				'label'                 => ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
			)
		);
	}

	/**
	 * Slug pa_* tanpa prefix.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private static function pa_slug( $taxonomy ) {
		$slug = trim( (string) $taxonomy );

		// Strip prefix sebelum sanitasi agar 'pa_ukuran' tidak menjadi 'pa-ukuran'.
		if ( 0 === strpos( $slug, 'pa_' ) || 0 === strpos( $slug, 'pa-' ) ) {
			$slug = substr( $slug, 3 );
		}

		return sanitize_title( $slug );
	}

	/**
	 * Bangun WC_Product_Attribute[] dari payload.
	 *
	 * @param array  $attributes Payload atribut.
	 * @param string $sku        SKU produk (log error).
	 * @return array|WP_Error
	 */
	private static function build_attributes( array $attributes, $sku ) {
		$result = array();
		$position = 0;

		foreach ( (array) $attributes as $attr ) {
			$name    = (string) ( $attr['name'] ?? '' );
			$scope   = (string) ( $attr['scope'] ?? 'global_pa' );
			$options = (array) ( $attr['options'] ?? array() );
			$is_var  = ! empty( $attr['variation'] );

			if ( '' === $name || empty( $options ) ) {
				continue;
			}

			$wc_attribute = new WC_Product_Attribute();
			$wc_attribute->set_position( $position++ );
			$wc_attribute->set_visible( ! empty( $attr['visible'] ) );
			$wc_attribute->set_variation( $is_var );

			if ( 'global_pa' === $scope && 0 === strpos( $name, 'pa_' ) ) {
				// Atribut global (taxonomy pa_*): options = term IDs.
				$ensured = self::ensure_global_attribute( $name );
				if ( is_wp_error( $ensured ) ) {
					return $ensured;
				}

				$term_ids = array();
				foreach ( $options as $option ) {
					$term = self::ensure_term( $name, (string) $option );
					if ( is_wp_error( $term ) ) {
						return $term;
					}
					$term_ids[] = (int) $term['term_id'];
				}

				$wc_attribute->set_id( wc_attribute_taxonomy_id_by_name( self::pa_slug( $name ) ) );
				$wc_attribute->set_name( sanitize_title( $name ) );
				$wc_attribute->set_options( $term_ids );
			} else {
				// Atribut custom (non-taxonomy): options = nilai mentah.
				$wc_attribute->set_id( 0 );
				$wc_attribute->set_name( sanitize_text_field( str_replace( 'pa_', '', $name ) ) );
				$wc_attribute->set_options( array_map( 'sanitize_text_field', $options ) );
			}

			$result[] = $wc_attribute;
		}

		if ( empty( $result ) ) {
			return new WP_Error(
				'bcs_validation',
				sprintf(
					/* translators: %s: SKU */
					__( 'Variable product %s has no valid attributes.', 'bulk-catalog-studio' ),
					(string) $sku
				)
			);
		}

		return $result;
	}

	/**
	 * Terapkan gambar (attachment_id_only) + alt text.
	 *
	 * @param WC_Product $product Produk.
	 * @param array|null $image   Definisi gambar.
	 * @return true|WP_Error
	 */
	private static function apply_image( $product, $image ) {
		if ( empty( $image ) || empty( $image['attachment_id'] ) ) {
			return true;
		}

		$attachment_id = absint( $image['attachment_id'] );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'bcs_validation',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Attachment #%d is invalid — image skipped.', 'bulk-catalog-studio' ),
					$attachment_id
				)
			);
		}

		$product->set_image_id( $attachment_id );

		// Alt text (gate1 #4: tetap diisi dari data CSV ke attachment yang dirujuk).
		// Satu-satunya pengecualian update_post_meta: meta core attachment
		// _wp_attachment_image_alt (whitelist ATTACHMENT_META_WHITELIST).
		if ( ! empty( $image['alt_text'] ) && in_array( '_wp_attachment_image_alt', self::ATTACHMENT_META_WHITELIST, true ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $image['alt_text'] ) );
		}

		return true;
	}

	/**
	 * Baca nilai SEO meta saat ini dari produk (untuk snapshot before).
	 *
	 * @param WC_Product $product Produk.
	 * @return array
	 */
	public static function read_seo_meta( $product ) {
		$seo = array();

		foreach ( self::seo_meta_map() as $provider => $keys ) {
			foreach ( $keys as $key ) {
				$seo[ $key ] = $product->get_meta( $key, true, 'edit' );
			}
		}

		return $seo;
	}

	/**
	 * Terapkan SEO meta sesuai provider (whitelist, spec §5.5.2).
	 *
	 * @param WC_Product $product Produk.
	 * @param array|null $seo     Definisi SEO payload.
	 * @return void
	 */
	private static function apply_seo_meta( $product, $seo ) {
		if ( empty( $seo ) || ( empty( $seo['title'] ) && empty( $seo['description'] ) ) ) {
			return;
		}

		$provider = (string) ( $seo['provider'] ?? 'auto' );

		if ( 'auto' === $provider ) {
			if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
				$provider = 'yoast';
			} elseif ( class_exists( 'RankMath' ) ) {
				$provider = 'rank_math';
			} else {
				// Tanpa provider SEO dikenal — V1 melewatkan penulisan SEO.
				return;
			}
		}

		if ( 'custom_fields' === $provider ) {
			// custom_fields V1: tidak menulis apa pun tanpa peta meta eksplisit.
			return;
		}

		$map = self::seo_meta_map();
		if ( ! isset( $map[ $provider ] ) ) {
			return;
		}

		$keys = $map[ $provider ];

		if ( ! empty( $seo['title'] ) && isset( $keys[0] ) ) {
			$product->update_meta_data( $keys[0], sanitize_text_field( (string) $seo['title'] ) );
		}
		if ( ! empty( $seo['description'] ) && isset( $keys[1] ) ) {
			$product->update_meta_data( $keys[1], sanitize_text_field( (string) $seo['description'] ) );
		}
	}

	/**
	 * Tag meta plugin _bcs_* (whitelist) untuk idempotency/rollback.
	 *
	 * @param WC_Product $product Produk.
	 * @param string     $row_key row_key.
	 * @param int        $job_id  Job ID.
	 * @param int        $plan_id Plan ID.
	 * @return void
	 */
	private static function tag_bcs_meta( $product, $row_key, $job_id, $plan_id ) {
		if ( '' !== (string) $row_key && in_array( '_bcs_row_key', self::BCS_META_WHITELIST, true ) ) {
			$product->update_meta_data( '_bcs_row_key', (string) $row_key );
		}
		if ( absint( $job_id ) && in_array( '_bcs_job_id', self::BCS_META_WHITELIST, true ) ) {
			$product->update_meta_data( '_bcs_job_id', absint( $job_id ) );
		}
		if ( absint( $plan_id ) && in_array( '_bcs_plan_id', self::BCS_META_WHITELIST, true ) ) {
			$product->update_meta_data( '_bcs_plan_id', absint( $plan_id ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Rollback primitives (dipakai BCS_Rollback_Service).
	 * ------------------------------------------------------------------- */

	/**
	 * Trash produk + seluruh variasinya (soft delete, gate1 #5).
	 *
	 * @param int $product_id Product ID.
	 * @return array|WP_Error { variations_trashed }
	 */
	public static function trash_product( $product_id ) {
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'bcs_not_found', __( 'Product not found for trash.', 'bulk-catalog-studio' ) );
		}

		// Whitelist post type.
		if ( ! in_array( $product->get_type(), array( 'simple', 'variable', 'external', 'grouped' ), true ) ) {
			return new WP_Error( 'bcs_invalid_target', __( 'Target is not a valid WooCommerce product.', 'bulk-catalog-studio' ) );
		}

		$variations_trashed = 0;

		if ( $product instanceof WC_Product_Variable ) {
			$children = (array) $product->get_children( 'edit' );
			foreach ( $children as $child_id ) {
				$child = wc_get_product( absint( $child_id ) );
				if ( $child instanceof WC_Product_Variation && 'trash' !== $child->get_status( 'edit' ) ) {
					$child->delete( false ); // force=false → trash.
					$variations_trashed++;
				}
			}
		}

		if ( 'trash' !== $product->get_status( 'edit' ) ) {
			$product->delete( false ); // force=false → trash (recoverable).
		}

		return array( 'variations_trashed' => $variations_trashed );
	}

	/**
	 * Pulihkan produk dari snapshot `before` (field tersentuh saja).
	 *
	 * @param int   $product_id Product ID.
	 * @param array $before     Snapshot.
	 * @return true|WP_Error
	 */
	public static function restore_from_snapshot( $product_id, array $before ) {
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'bcs_not_found', __( 'Product not found for restore.', 'bulk-catalog-studio' ) );
		}

		if ( isset( $before['name'] ) ) {
			$product->set_name( (string) $before['name'] );
		}
		if ( isset( $before['status'] ) && in_array( $before['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$product->set_status( (string) $before['status'] );
		}
		if ( isset( $before['description'] ) ) {
			$product->set_description( (string) $before['description'] );
		}
		if ( isset( $before['short_description'] ) ) {
			$product->set_short_description( (string) $before['short_description'] );
		}
		if ( isset( $before['sku'] ) ) {
			$product->set_sku( (string) $before['sku'] );
		}
		if ( isset( $before['regular_price'] ) ) {
			$product->set_regular_price( '' === (string) $before['regular_price'] ? '' : self::decimal( $before['regular_price'] ) );
		}
		if ( isset( $before['sale_price'] ) ) {
			$product->set_sale_price( '' === (string) $before['sale_price'] ? '' : self::decimal( $before['sale_price'] ) );
		}
		if ( isset( $before['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $before['manage_stock'] );
		}
		if ( isset( $before['stock_quantity'] ) && null !== $before['stock_quantity'] ) {
			$product->set_stock_quantity( absint( $before['stock_quantity'] ) );
		}
		if ( isset( $before['weight'] ) ) {
			$product->set_weight( '' === (string) $before['weight'] ? '' : self::decimal( $before['weight'] ) );
		}
		if ( ! empty( $before['dimensions'] ) && is_array( $before['dimensions'] ) ) {
			if ( isset( $before['dimensions']['length'] ) ) {
				$product->set_length( (string) $before['dimensions']['length'] );
			}
			if ( isset( $before['dimensions']['width'] ) ) {
				$product->set_width( (string) $before['dimensions']['width'] );
			}
			if ( isset( $before['dimensions']['height'] ) ) {
				$product->set_height( (string) $before['dimensions']['height'] );
			}
		}
		if ( isset( $before['catalog_visibility'] ) ) {
			$product->set_catalog_visibility( (string) $before['catalog_visibility'] );
		}
		if ( isset( $before['tax_class'] ) && null !== $before['tax_class'] ) {
			$product->set_tax_class( (string) $before['tax_class'] );
		}
		if ( isset( $before['category_ids'] ) && is_array( $before['category_ids'] ) ) {
			$product->set_category_ids( array_map( 'intval', $before['category_ids'] ) );
		}
		if ( isset( $before['tag_ids'] ) && is_array( $before['tag_ids'] ) ) {
			$product->set_tag_ids( array_map( 'intval', $before['tag_ids'] ) );
		}
		if ( array_key_exists( 'image_id', $before ) ) {
			$product->set_image_id( ! empty( $before['image_id'] ) ? absint( $before['image_id'] ) : '' );

			// Pulihkan alt attachment (whitelist).
			if ( ! empty( $before['image_id'] ) && isset( $before['image_alt'] ) ) {
				update_post_meta( absint( $before['image_id'] ), '_wp_attachment_image_alt', sanitize_text_field( (string) $before['image_alt'] ) );
			}
		}
		if ( isset( $before['seo'] ) && is_array( $before['seo'] ) ) {
			foreach ( $before['seo'] as $meta_key => $meta_value ) {
				if ( ! self::is_allowed_seo_meta_key( (string) $meta_key ) ) {
					continue;
				}
				$product->update_meta_data( (string) $meta_key, null === $meta_value ? '' : (string) $meta_value );
			}
		}

		$product->save();

		if ( $product instanceof WC_Product_Variable ) {
			WC_Product_Variable::sync( $product->get_id() );
			wc_delete_product_transients( $product->get_id() );
		}

		return true;
	}

	/**
	 * Meta key SEO di-whitelist?
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function is_allowed_seo_meta_key( $key ) {
		foreach ( self::seo_meta_map() as $keys ) {
			if ( in_array( $key, $keys, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Format desimal via wc_format_decimal bila tersedia.
	 *
	 * @param mixed $value Nilai.
	 * @return string
	 */
	private static function decimal( $value ) {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return (string) wc_format_decimal( $value );
		}

		return (string) ( 0 + str_replace( ',', '', (string) $value ) );
	}
}
