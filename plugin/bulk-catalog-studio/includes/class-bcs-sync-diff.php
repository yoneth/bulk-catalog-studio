<?php
/**
 * Key-based BCS Sync diff engine and guard chain.
 *
 * The engine is deliberately independent from the runner: it produces a
 * deterministic operation list, evaluates row/run guards, and never writes a
 * product or a post meta value.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Sync_Diff
 */
class BCS_Sync_Diff {

	/**
	 * Build a diff from parser rows, a connection, and optional preloaded state.
	 *
	 * @param array $rows       Parser rows, or an array containing rows.
	 * @param array $connection Normalized connection.
	 * @param array $state      Optional batch-loaded catalog state.
	 * @return array|WP_Error
	 */
	public static function diff( array $rows, array $connection, array $state = array() ) {
		if ( isset( $rows['rows'] ) && is_array( $rows['rows'] ) ) {
			$rows = $rows['rows'];
		}
		if ( empty( $rows ) ) {
			return new WP_Error( 'fetch_zero_rows', __( 'The sync source contains zero data rows.', 'bulk-catalog-studio' ) );
		}

		$connection = self::normalize_connection( $connection );
		$policy     = $connection['policy'];
		$thresholds = $connection['thresholds'];
		$state      = self::normalize_state( $state, $rows, (int) $connection['id'] );

		$duplicate = self::duplicate_skus( $rows );
		if ( $duplicate ) {
			return new WP_Error(
				'duplicate_sku',
				sprintf(
					/* translators: %s: duplicated SKU */
					__( 'The sync source contains a duplicate SKU: %s.', 'bulk-catalog-studio' ),
					$duplicate
				)
			);
		}

		$operations  = array();
		$quarantine  = array();
		$present_skus = array();
		$catalog      = isset( $state['products'] ) && is_array( $state['products'] ) ? $state['products'] : array();

		foreach ( array_values( $rows ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sku = strtoupper( trim( (string) ( isset( $row['sku'] ) ? $row['sku'] : '' ) ) );
			$action = strtolower( trim( (string) ( isset( $row['sync_action'] ) ? $row['sync_action'] : '' ) ) );
			$operation_start = count( $operations );
			if ( '' === $sku ) {
				$quarantine[] = array(
					'sku'     => '',
					'reason'  => 'sku_invalid',
					'payload' => $row,
					'before'  => null,
				);
				continue;
			}
			$present_skus[ strtolower( $sku ) ] = $sku;
			foreach ( (array) ( isset( $row['variations'] ) ? $row['variations'] : array() ) as $variation ) {
				if ( is_array( $variation ) && ! empty( $variation['sku'] ) ) {
					$present_skus[ strtolower( trim( (string) $variation['sku'] ) ) ] = strtoupper( trim( (string) $variation['sku'] ) );
				}
			}

			// hold is intentionally visible to the missing-row guard but produces
			// no operation in this run.
			if ( 'hold' === $action ) {
				continue;
			}
			if ( '' !== $action && ! in_array( $action, array( 'delete', 'hold' ), true ) ) {
				$quarantine[] = array(
					'sku'     => $sku,
					'reason'  => 'sync_action_invalid',
					'payload' => $row,
					'before'  => null,
				);
				continue;
			}

			$existing = self::find_product( $catalog, $sku );
			if ( is_array( $existing ) && ! empty( $existing['connection_id'] ) && (int) $existing['connection_id'] !== (int) $connection['id'] ) {
				$quarantine[] = array(
					'sku'     => $sku,
					'reason'  => 'connection_ownership_conflict',
					'payload' => $row,
					'before'  => $existing,
				);
				continue;
			}
			if ( 'delete' === $action ) {
				$delete_validation = self::validate_row_thresholds( $row, array( 'sku' => $sku ), $existing, $thresholds );
				if ( $delete_validation ) {
					$quarantine[] = array(
						'sku'     => $sku,
						'reason'  => $delete_validation,
						'payload' => array( 'sync_action' => 'delete' ),
						'before'  => $existing,
					);
					continue;
				}
				// An explicit delete is idempotent when the target was already
				// trashed or never existed; do not turn that into a failed run.
				if ( ! $existing ) {
					continue;
				}
				$operations[] = self::operation(
					'delete',
					$sku,
					'',
					array( 'sync_action' => 'delete' ),
					$existing,
					'explicit_delete',
					$existing ? self::hash_for_payload( array( 'sync_action' => 'delete', 'sku' => $sku ) ) : ''
				);
				continue;
			}

			$product_payload = self::product_payload( $row, $sku );
			$row_quarantine  = self::validate_row_thresholds( $row, $product_payload, $existing, $thresholds );
			if ( ! empty( $row_quarantine ) ) {
				$quarantine[] = array(
					'sku'     => $sku,
					'reason'  => $row_quarantine,
					'payload' => $product_payload,
					'before'  => $existing,
				);
				continue;
			}

			if ( ! $existing ) {
				$operations[] = self::operation(
					'create',
					$sku,
					'',
					$product_payload,
					null,
					'new_sku',
					self::hash_for_payload( $product_payload )
				);
			} else {
				$product_hash = self::hash_for_payload( $product_payload );
				$changed      = self::record_changed( $product_payload, $existing, $product_hash );
				if ( $changed ) {
					if ( self::is_conflict( $existing ) ) {
						$operations[] = self::operation(
							'conflict',
							$sku,
							'',
							$product_payload,
							$existing,
							'manual_edit_conflict',
							$product_hash
						);
					} else {
						$operations[] = self::operation(
							'update',
							$sku,
							'',
							$product_payload,
							$existing,
							'field_changed',
							$product_hash
						);
					}
				}
			}

			// A variation is an independent key. A parent update is not emitted
			// merely because a variation appears in a different sheet row/order.
			$variations = (array) ( isset( $row['variations'] ) ? $row['variations'] : array() );
			foreach ( $variations as $variation ) {
				if ( ! is_array( $variation ) ) {
					continue;
				}
				$variation_sku = strtoupper( trim( (string) ( isset( $variation['sku'] ) ? $variation['sku'] : '' ) ) );
				if ( '' === $variation_sku ) {
					$quarantine[] = array(
						'sku'           => $sku,
						'variation_sku' => '',
						'reason'        => 'variation_sku_invalid',
						'payload'       => $variation,
						'before'        => null,
					);
					continue;
				}
				$before_variation = self::find_variation( $existing, $state, $variation_sku );
				if ( is_array( $before_variation ) && array_key_exists( 'attributes', $before_variation ) && array_key_exists( 'attributes', $variation ) && ! empty( $variation['attributes'] ) ) {
					$current_attributes = $before_variation['attributes'];
					if ( self::normalize_for_compare( $variation['attributes'] ) !== self::normalize_for_compare( $current_attributes ) ) {
						$quarantine[] = array(
							'sku'           => $sku,
							'variation_sku' => $variation_sku,
							'reason'        => 'variation_attributes_immutable',
							'payload'       => $variation,
							'before'        => $before_variation,
						);
						continue;
					}
				}
				$variation_payload = self::variation_payload( $variation, $variation_sku );
				$variation_error = self::validate_variation_thresholds( $variation_payload, $before_variation, $thresholds );
				if ( $variation_error ) {
					$quarantine[] = array(
						'sku'           => $sku,
						'variation_sku' => $variation_sku,
						'reason'        => $variation_error,
						'payload'       => $variation_payload,
						'before'        => $before_variation,
					);
					continue;
				}
				if ( ! $before_variation && ! $existing ) {
					// New-product creates carry their variations in the parent
					// payload; no independent update operation is needed.
					continue;
				}
				if ( ! $before_variation ) {
					// V1 does not synthesize a new attribute structure during an
					// update. Keep this row visible and let the operator correct the
					// source instead of turning it into a destructive guess.
					$quarantine[] = array(
						'sku'           => $sku,
						'variation_sku' => $variation_sku,
						'reason'        => 'variation_not_found',
						'payload'       => $variation_payload,
						'before'        => null,
					);
					continue;
				}
				$variation_hash = self::hash_for_payload( $variation_payload );
				if ( self::record_changed( $variation_payload, $before_variation, $variation_hash ) ) {
					$variation_conflict = self::is_conflict( $before_variation );
					$variation_operation = self::operation(
						$variation_conflict ? 'conflict' : 'update_variation',
						$sku,
						$variation_sku,
						$variation_payload,
						$before_variation,
						$variation_conflict ? 'manual_edit_conflict' : 'variation_changed',
						$variation_hash
					);
					// The writer receives the parent ID for a normal variation update;
					// a conflict flag must target the child variation itself.
					if ( $variation_conflict && is_array( $before_variation ) && isset( $before_variation['id'] ) ) {
						$variation_operation['target_product_id'] = (int) $before_variation['id'];
					} elseif ( is_array( $existing ) && isset( $existing['id'] ) ) {
						$variation_operation['target_product_id'] = (int) $existing['id'];
					}
					$operations[] = $variation_operation;
				}
			}

			// Keep the optional source note on every operation emitted by this
			// source row. It is audit context, not part of the catalog hash.
			if ( isset( $row['sync_note'] ) && '' !== trim( (string) $row['sync_note'] ) ) {
				$sync_note = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( (string) $row['sync_note'] ) : trim( (string) $row['sync_note'] );
				for ( $operation_index = $operation_start; $operation_index < count( $operations ); $operation_index++ ) {
					$operations[ $operation_index ]['sync_note'] = $sync_note;
				}
			}
		}

		// Missing rows are only computed against the historical set. Rows held
		// explicitly above are present and therefore cannot be flagged missing.
		$seen_skus = isset( $policy['seen_skus'] ) && is_array( $policy['seen_skus'] ) ? $policy['seen_skus'] : array();
		$missing = array();
		foreach ( $seen_skus as $seen_sku ) {
			$seen_sku = strtoupper( trim( (string) $seen_sku ) );
			if ( '' !== $seen_sku && ! isset( $present_skus[ strtolower( $seen_sku ) ] ) ) {
				$missing[] = $seen_sku;
			}
		}
		$missing = array_values( array_unique( $missing ) );
		$missing_pct = count( $seen_skus ) > 0 ? ( count( $missing ) / count( $seen_skus ) ) * 100 : 0;
		if ( $missing_pct > (float) $thresholds['missing_row_flag_pct'] ) {
			return new WP_Error(
				'missing_rows_guard',
				sprintf(
					/* translators: 1: missing count, 2: seen count, 3: percentage */
					__( 'The source is missing %1$d of %2$d previously seen SKUs (%3$.1f%%), exceeding the missing-row guard.', 'bulk-catalog-studio' ),
					count( $missing ),
					count( $seen_skus ),
					$missing_pct
				)
			);
		}
		foreach ( $missing as $missing_sku ) {
			$before = self::find_product( $catalog, $missing_sku );
			$operations[] = self::operation( 'flag_missing', $missing_sku, '', array(), $before, 'missing_from_source', '' );
		}

		$operations = self::sort_operations( $operations );
		$summary = self::summary( $operations, $quarantine );
		$catalog_count = self::catalog_count( $state );
		$run_limit = max( (float) $thresholds['max_create_update_ops'], ( $catalog_count * (float) $thresholds['max_create_update_catalog_pct'] / 100 ) );
		$create_update_count = (int) $summary['create'] + (int) $summary['update'] + (int) $summary['update_variation'];
		$delete_count = (int) $summary['delete'];
		$status = 'ok';
		$approval_reason = '';
		if ( $create_update_count > $run_limit ) {
			$status = 'awaiting_approval';
			$approval_reason = 'threshold_run';
		} elseif ( $delete_count > (int) $thresholds['max_delete_ops'] ) {
			$status = 'awaiting_approval';
			$approval_reason = 'threshold_delete';
		} elseif ( 'approval_required' === $policy['auto_apply_mode'] && ( $create_update_count > 0 || $delete_count > 0 ) ) {
			$status = 'awaiting_approval';
			$approval_reason = 'policy_approval_required';
		}

		$hash_operations = array();
		foreach ( $operations as $operation ) {
			$hash_operations[] = self::operation_for_hash( $operation );
		}

		return array(
			'operations'       => $operations,
			'quarantine'       => $quarantine,
			'summary'          => $summary,
			'catalog_count'    => $catalog_count,
			'create_update_limit' => $run_limit,
			'diff_hash'        => sha1( self::json( $hash_operations ) ),
			'status'           => $status,
			'approval_reason'  => $approval_reason,
			'seen_skus'        => array_values( array_unique( array_merge( $seen_skus, array_values( $present_skus ) ) ) ),
		);
	}

	/**
	 * Alias build() for callers that name the operation build.
	 *
	 * @param array $rows       Parser rows.
	 * @param array $connection Connection.
	 * @param array $state      Optional state.
	 * @return array|WP_Error
	 */
	public static function build( array $rows, array $connection, array $state = array() ) {
		return self::diff( $rows, $connection, $state );
	}

	/**
	 * Alias calculate() for integration code.
	 *
	 * @param array $rows       Parser rows.
	 * @param array $connection Connection.
	 * @param array $state      Optional state.
	 * @return array|WP_Error
	 */
	public static function calculate( array $rows, array $connection, array $state = array() ) {
		return self::diff( $rows, $connection, $state );
	}

	/**
	 * Public canonical payload helper, useful when persisting sync meta.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	public static function payload_hash( array $payload ) {
		return self::hash_for_payload( $payload );
	}

	/**
	 * Normalize connection input for the diff engine.
	 *
	 * @param array $connection Raw or normalized connection.
	 * @return array
	 */
	private static function normalize_connection( array $connection ) {
		$id = isset( $connection['id'] ) ? absint( $connection['id'] ) : ( isset( $connection['connection_id'] ) ? absint( $connection['connection_id'] ) : 0 );
		$policy = isset( $connection['policy'] ) ? $connection['policy'] : array();
		$thresholds = isset( $connection['thresholds'] ) ? $connection['thresholds'] : array();
		if ( class_exists( 'BCS_Sync_Connection' ) ) {
			$policy = BCS_Sync_Connection::sanitize_policy( $policy );
			$thresholds = BCS_Sync_Connection::sanitize_thresholds( $thresholds );
		}
		if ( is_wp_error( $policy ) || ! is_array( $policy ) ) {
			$policy = array( 'on_missing_row' => 'flag', 'conflict_policy' => 'flag_only', 'auto_apply_mode' => 'within_threshold', 'seen_skus' => array() );
		}
		if ( is_wp_error( $thresholds ) || ! is_array( $thresholds ) ) {
			$thresholds = array(
				'max_create_update_ops' => 50,
				'max_create_update_catalog_pct' => 10,
				'max_delete_ops' => 10,
				'missing_row_flag_pct' => 20,
				'price_delta_pct' => 80,
				'stock_drop_pct' => 90,
				'sku_regex' => '^[A-Za-z0-9._-]{3,64}$',
			);
		}
		return array_merge( $connection, array( 'id' => $id, 'policy' => $policy, 'thresholds' => $thresholds ) );
	}

	/**
	 * Normalize state supplied by a caller or load it in one batch query.
	 *
	 * @param array $state      State input.
	 * @param array $rows       Source rows.
	 * @param int   $connection_id Connection ID.
	 * @return array
	 */
	private static function normalize_state( array $state, array $rows, $connection_id ) {
		$products = array();
		if ( isset( $state['products'] ) && is_array( $state['products'] ) ) {
			foreach ( $state['products'] as $key => $product ) {
				$normalized = self::normalize_record( $product );
				$sku = self::record_sku( $normalized, is_string( $key ) ? $key : '' );
				if ( '' !== $sku ) {
					$products[ strtolower( $sku ) ] = $normalized;
				}
			}
		} elseif ( isset( $state['by_sku'] ) && is_array( $state['by_sku'] ) ) {
			foreach ( $state['by_sku'] as $key => $product ) {
				$normalized = self::normalize_record( $product );
				$sku = self::record_sku( $normalized, is_string( $key ) ? $key : '' );
				if ( '' !== $sku ) {
					$products[ strtolower( $sku ) ] = $normalized;
				}
			}
		} else {
			$reserved = array( 'catalog_count', 'variations', 'by_sku' );
			foreach ( $state as $key => $product ) {
				if ( in_array( $key, $reserved, true ) || ! is_array( $product ) ) {
					continue;
				}
				$normalized = self::normalize_record( $product );
				$sku = self::record_sku( $normalized, is_string( $key ) ? $key : '' );
				if ( '' !== $sku ) {
					$products[ strtolower( $sku ) ] = $normalized;
				}
			}
		}

		$normalized_state = $state;
		$normalized_state['products'] = $products;
		if ( empty( $products ) && self::can_query_state() ) {
			$loaded = self::load_state_from_db( $rows, $connection_id );
			if ( ! empty( $loaded['products'] ) ) {
				$normalized_state['products'] = $loaded['products'];
			}
			if ( ! isset( $normalized_state['catalog_count'] ) && isset( $loaded['catalog_count'] ) ) {
				$normalized_state['catalog_count'] = $loaded['catalog_count'];
			}
		}
		if ( ! isset( $normalized_state['catalog_count'] ) ) {
			$normalized_state['catalog_count'] = count( $normalized_state['products'] );
		}
		return $normalized_state;
	}

	/**
	 * Batch query product and variation state from WP postmeta.
	 *
	 * @param array $rows Source rows.
	 * @param int   $connection_id Connection ID.
	 * @return array
	 */
	private static function load_state_from_db( array $rows, $connection_id ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array( 'products' => array(), 'catalog_count' => 0 );
		}

		$posts_table = isset( $wpdb->posts ) ? $wpdb->posts : $wpdb->prefix . 'posts';
		$meta_table  = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
		/*
		 * post_title/post_content/post_excerpt are selected because
		 * payload_matches_record() skips any field the record does not carry.
		 * Without them a sheet edit to the name, description or short
		 * description produced zero operations: the diff simply never looked at
		 * those columns.
		 */
		$sql = "SELECT p.ID, p.post_parent, p.post_type, p.post_modified, p.post_modified_gmt,
			p.post_title AS name, p.post_content AS description, p.post_excerpt AS short_description,
			MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value ELSE '' END) AS sku,
			MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value ELSE '' END) AS regular_price,
			MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value ELSE '' END) AS sale_price,
			MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value ELSE '' END) AS stock_quantity,
			MAX(CASE WHEN pm.meta_key = '_bcs_sync_hash' THEN pm.meta_value ELSE '' END) AS sync_hash,
			MAX(CASE WHEN pm.meta_key = '_bcs_synced_at' THEN pm.meta_value ELSE '' END) AS synced_at,
			MAX(CASE WHEN pm.meta_key = '_bcs_sync_post_modified' THEN pm.meta_value ELSE '' END) AS sync_post_modified,
			MAX(CASE WHEN pm.meta_key = '_bcs_connection_id' THEN pm.meta_value ELSE '' END) AS connection_id
			FROM {$posts_table} p
			LEFT JOIN {$meta_table} pm ON pm.post_id = p.ID
			WHERE p.post_type IN ('product','product_variation')
			AND p.post_status NOT IN ('trash','auto-draft')
			GROUP BY p.ID";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- tables are controlled WP table names; no user values.
		$records = $wpdb->get_results( $sql );
		$products = array();
		$variations = array();
		foreach ( (array) $records as $record ) {
			$record = is_object( $record ) ? (array) $record : $record;
			$normalized = self::normalize_record( $record );
			if ( 'product_variation' === (string) ( isset( $record['post_type'] ) ? $record['post_type'] : '' ) ) {
				$variations[ (int) $record['ID'] ] = $normalized;
				continue;
			}
			$sku = self::record_sku( $normalized, '' );
			if ( '' !== $sku ) {
				$normalized['variations'] = array();
				$products[ strtolower( $sku ) ] = $normalized;
			}
		}
		foreach ( $variations as $variation_id => $variation ) {
			$parent_id = absint( isset( $variation['parent_id'] ) ? $variation['parent_id'] : 0 );
			foreach ( $products as &$product ) {
				if ( (int) ( isset( $product['id'] ) ? $product['id'] : 0 ) === $parent_id ) {
					$vsku = self::record_sku( $variation, '' );
					if ( '' !== $vsku ) {
						$product['variations'][ strtolower( $vsku ) ] = $variation;
					}
					break;
				}
			}
			unset( $product );
		}

		$catalog_count = null;
		if ( method_exists( $wpdb, 'get_var' ) ) {
			$catalog_count = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$posts_table} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( null === $catalog_count || 0 === $catalog_count ) {
			$catalog_count = count( $products );
		}
		return array( 'products' => $products, 'variations' => $variations, 'catalog_count' => $catalog_count );
	}

	/**
	 * Whether a usable WPDB is available.
	 *
	 * @return bool
	 */
	private static function can_query_state() {
		global $wpdb;
		return isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' );
	}

	/**
	 * Normalize a record supplied by a DB query or a test state.
	 *
	 * @param mixed $record Record.
	 * @return array
	 */
	private static function normalize_record( $record ) {
		if ( is_object( $record ) ) {
			$record = get_object_vars( $record );
		}
		if ( ! is_array( $record ) ) {
			return array();
		}
		if ( isset( $record['props'] ) && is_array( $record['props'] ) ) {
			$record = array_merge( $record['props'], array( 'meta' => isset( $record['meta'] ) ? $record['meta'] : array() ) );
		}
		$meta = isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array();
		$map = array(
			'id'               => array( 'id', 'ID' ),
			'parent_id'        => array( 'parent_id', 'post_parent' ),
			'sku'              => array( 'sku' ),
			'name'             => array( 'name', 'post_title' ),
			'description'      => array( 'description', 'post_content' ),
			'short_description' => array( 'short_description', 'post_excerpt' ),
			'regular_price'    => array( 'regular_price', '_regular_price' ),
			'sale_price'       => array( 'sale_price', '_sale_price' ),
			'stock_quantity'   => array( 'stock_quantity', '_stock' ),
			'manage_stock'     => array( 'manage_stock', '_manage_stock' ),
			'post_modified'    => array( 'post_modified' ),
			'post_modified_gmt' => array( 'post_modified_gmt' ),
			'sync_post_modified' => array( 'sync_post_modified', '_bcs_sync_post_modified' ),
			'synced_at'        => array( 'synced_at', '_bcs_synced_at' ),
			'sync_hash'        => array( 'sync_hash', '_bcs_sync_hash' ),
			'connection_id'    => array( 'connection_id', '_bcs_connection_id' ),
			'conflict_flag'    => array( 'conflict_flag', '_bcs_conflict_flag' ),
		);
		$out = $record;
		foreach ( $map as $target => $keys ) {
			if ( array_key_exists( $target, $out ) && '' !== (string) $out[ $target ] ) {
				continue;
			}
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $out ) && '' !== (string) $out[ $key ] ) {
					$out[ $target ] = $out[ $key ];
					break;
				}
				if ( array_key_exists( $key, $meta ) ) {
					$out[ $target ] = $meta[ $key ];
					break;
				}
			}
		}
		if ( ! isset( $out['variations'] ) || ! is_array( $out['variations'] ) ) {
			$out['variations'] = array();
		}
		return $out;
	}

	/**
	 * SKU from a normalized record.
	 *
	 * @param array  $record Record.
	 * @param string $fallback Fallback key.
	 * @return string
	 */
	private static function record_sku( array $record, $fallback ) {
		return strtoupper( trim( (string) ( isset( $record['sku'] ) && '' !== (string) $record['sku'] ? $record['sku'] : $fallback ) ) );
	}

	/**
	 * Find product by case-insensitive SKU.
	 *
	 * @param array  $catalog Catalog keyed by lowercase SKU.
	 * @param string $sku SKU.
	 * @return array|null
	 */
	private static function find_product( array $catalog, $sku ) {
		$key = strtolower( trim( (string) $sku ) );
		return isset( $catalog[ $key ] ) && is_array( $catalog[ $key ] ) ? $catalog[ $key ] : null;
	}

	/**
	 * Find variation under a parent or global state map.
	 *
	 * @param array|null $product Parent.
	 * @param array      $state Global state.
	 * @param string     $sku Variation SKU.
	 * @return array|null
	 */
	private static function find_variation( $product, array $state, $sku ) {
		$key = strtolower( trim( (string) $sku ) );
		if ( is_array( $product ) && isset( $product['variations'][ $key ] ) ) {
			return self::normalize_record( $product['variations'][ $key ] );
		}
		if ( is_array( $product ) && isset( $product['variations'] ) ) {
			foreach ( (array) $product['variations'] as $candidate ) {
				$candidate = self::normalize_record( $candidate );
				if ( strtolower( self::record_sku( $candidate, '' ) ) === $key ) {
					return $candidate;
				}
			}
		}
		if ( isset( $state['variations'][ $key ] ) && is_array( $state['variations'][ $key ] ) ) {
			return self::normalize_record( $state['variations'][ $key ] );
		}
		return null;
	}

	/**
	 * Build product payload while omitting parser defaults that were not sent.
	 *
	 * @param array  $row Source row.
	 * @param string $sku SKU.
	 * @return array
	 */
	private static function product_payload( array $row, $sku ) {
		$payload = array( 'sku' => $sku );
		$fields = array( 'name', 'description', 'short_description', 'regular_price', 'sale_price', 'weight', 'categories', 'tags', 'attributes', 'image_id', 'image_url', 'image_alt', 'seo_title', 'seo_description' );
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $row ) || null === $row[ $field ] || '' === $row[ $field ] || ( is_array( $row[ $field ] ) && empty( $row[ $field ] ) ) ) {
				continue;
			}
			$payload[ $field ] = $row[ $field ];
		}
		if ( array_key_exists( 'stock_quantity', $row ) && ( ! array_key_exists( 'stock_provided', $row ) || $row['stock_provided'] ) ) {
			$payload['stock_quantity'] = (int) $row['stock_quantity'];
		}
		if ( isset( $row['manage_stock'] ) ) {
			$payload['manage_stock'] = (bool) $row['manage_stock'];
		}
		if ( isset( $row['dimensions'] ) && is_array( $row['dimensions'] ) ) {
			$dimensions = array();
			foreach ( array( 'length', 'width', 'height' ) as $dimension ) {
				if ( array_key_exists( $dimension, $row['dimensions'] ) && null !== $row['dimensions'][ $dimension ] && '' !== (string) $row['dimensions'][ $dimension ] ) {
					$dimensions[ $dimension ] = $row['dimensions'][ $dimension ];
				}
			}
			if ( ! empty( $dimensions ) ) {
				$payload['dimensions'] = $dimensions;
			}
		}
		if ( ! empty( $row['variations'] ) ) {
			$payload['variations'] = array_values( (array) $row['variations'] );
		}
		return $payload;
	}

	/**
	 * Build scalar variation payload.
	 *
	 * @param array  $variation Variation row.
	 * @param string $sku        Variation SKU.
	 * @return array
	 */
	private static function variation_payload( array $variation, $sku ) {
		$payload = array( 'sku' => $sku );
		foreach ( array( 'regular_price', 'sale_price', 'stock_quantity', 'weight' ) as $field ) {
			if ( ! array_key_exists( $field, $variation ) || null === $variation[ $field ] || '' === (string) $variation[ $field ] ) {
				continue;
			}
			if ( 'stock_quantity' === $field && array_key_exists( 'stock_provided', $variation ) && ! $variation['stock_provided'] ) {
				continue;
			}
			$payload[ $field ] = 'stock_quantity' === $field ? (int) $variation[ $field ] : $variation[ $field ];
		}
		if ( isset( $variation['dimensions'] ) && is_array( $variation['dimensions'] ) ) {
			$payload['dimensions'] = $variation['dimensions'];
		}
		return $payload;
	}

	/**
	 * Validate row-level threshold values.
	 *
	 * @param array      $row        Source row.
	 * @param array      $payload    Product payload.
	 * @param array|null $existing   Existing state.
	 * @param array      $thresholds Thresholds.
	 * @return string|null Reason.
	 */
	private static function validate_row_thresholds( array $row, array $payload, $existing, array $thresholds ) {
		$regex = '/' . trim( (string) $thresholds['sku_regex'], '/' ) . '/';
		if ( 1 !== preg_match( $regex, (string) $payload['sku'] ) ) {
			return 'sku_invalid';
		}
		if ( isset( $payload['regular_price'] ) && (float) $payload['regular_price'] <= 0 ) {
			return 'price_non_positive';
		}
		if ( isset( $payload['sale_price'] ) && (float) $payload['sale_price'] <= 0 ) {
			return 'price_non_positive';
		}
		if ( is_array( $existing ) ) {
			$current_price = self::first_price( $existing );
			if ( null !== $current_price && isset( $payload['regular_price'] ) && (float) $current_price > 0 ) {
				$delta = abs( ( (float) $payload['regular_price'] - (float) $current_price ) / (float) $current_price ) * 100;
				if ( $delta > (float) $thresholds['price_delta_pct'] ) {
					return 'price_delta_exceeded';
				}
			}
			$current_stock = isset( $existing['stock_quantity'] ) && is_numeric( $existing['stock_quantity'] ) ? (float) $existing['stock_quantity'] : null;
			if ( null !== $current_stock && $current_stock > 0 && isset( $payload['stock_quantity'] ) && (float) $payload['stock_quantity'] < ( $current_stock * ( 1 - ( (float) $thresholds['stock_drop_pct'] / 100 ) ) ) ) {
				return 'stock_drop_exceeded';
			}
		}
		return null;
	}

	/**
	 * Validate scalar thresholds for a variation.
	 *
	 * @param array      $payload    Variation payload.
	 * @param array|null $before     Existing variation.
	 * @param array      $thresholds Thresholds.
	 * @return string|null
	 */
	private static function validate_variation_thresholds( array $payload, $before, array $thresholds ) {
		$regex = '/' . trim( (string) $thresholds['sku_regex'], '/' ) . '/';
		if ( 1 !== preg_match( $regex, (string) $payload['sku'] ) ) {
			return 'sku_invalid';
		}
		foreach ( array( 'regular_price', 'sale_price' ) as $field ) {
			if ( isset( $payload[ $field ] ) && (float) $payload[ $field ] <= 0 ) {
				return 'price_non_positive';
			}
		}
		if ( is_array( $before ) ) {
			$current_price = self::first_price( $before );
			if ( null !== $current_price && isset( $payload['regular_price'] ) && (float) $current_price > 0 ) {
				$delta = abs( ( (float) $payload['regular_price'] - (float) $current_price ) / (float) $current_price ) * 100;
				if ( $delta > (float) $thresholds['price_delta_pct'] ) {
					return 'price_delta_exceeded';
				}
			}
			$current_stock = isset( $before['stock_quantity'] ) && is_numeric( $before['stock_quantity'] ) ? (float) $before['stock_quantity'] : null;
			if ( null !== $current_stock && $current_stock > 0 && isset( $payload['stock_quantity'] ) && (float) $payload['stock_quantity'] < ( $current_stock * ( 1 - ( (float) $thresholds['stock_drop_pct'] / 100 ) ) ) ) {
				return 'stock_drop_exceeded';
			}
		}
		return null;
	}

	/**
	 * First usable product price.
	 *
	 * @param array $record Record.
	 * @return float|null
	 */
	private static function first_price( array $record ) {
		foreach ( array( 'regular_price', 'sale_price', 'price' ) as $field ) {
			if ( isset( $record[ $field ] ) && '' !== (string) $record[ $field ] && is_numeric( $record[ $field ] ) ) {
				return (float) $record[ $field ];
			}
		}
		return null;
	}

	/**
	 * Compare only source-owned fields, using sync hash when available.
	 *
	 * @param array  $payload Payload.
	 * @param array  $before  Existing record.
	 * @param string $hash    Canonical source hash.
	 * @return bool
	 */
	private static function record_changed( array $payload, array $before, $hash ) {
		if ( isset( $before['sync_hash'] ) && '' !== (string) $before['sync_hash'] && hash_equals( (string) $before['sync_hash'], (string) $hash ) ) {
			// A matching source hash is idempotent. Manual conflict is detected
			// when the actual owned scalar differs from the source below.
			$owned_compare = self::payload_matches_record( $payload, $before );
			return ! $owned_compare;
		}
		return ! self::payload_matches_record( $payload, $before );
	}

	/**
	 * Compare source fields with current state.
	 *
	 * @param array $payload Source payload.
	 * @param array $record  Current record.
	 * @return bool
	 */
	private static function payload_matches_record( array $payload, array $record ) {
		/*
		 * A variable parent holds no price of its own in WooCommerce: its price
		 * is derived from the variations, so _regular_price/_sale_price stay
		 * empty on the parent post. Comparing the source's parent-level price
		 * against that empty value reports field_changed on every run and the
		 * sync can never converge. Price is still compared where it actually
		 * lives, on each variation.
		 */
		$is_variable_parent = ! empty( $record['variations'] ) && is_array( $record['variations'] );

		foreach ( $payload as $field => $value ) {
			if ( 'variations' === $field ) {
				// Variation keys are diffed independently below.
				continue;
			}
			if ( $is_variable_parent && in_array( $field, array( 'regular_price', 'sale_price' ), true ) ) {
				continue;
			}
			if ( in_array( $field, array( 'sku', 'attributes', 'categories', 'tags', 'image_id', 'image_url', 'image_alt', 'seo_title', 'seo_description' ), true ) ) {
				// These are included in the canonical hash, but not all test/WP
				// state loaders expose them. SKU is the key and already matched.
				if ( 'sku' === $field ) {
					continue;
				}
				if ( ! array_key_exists( $field, $record ) ) {
					continue;
				}
			}
			if ( 'dimensions' === $field && is_array( $value ) ) {
				/*
				 * Same rule as every other field below: a record that does not
				 * expose dimensions cannot be compared on them. Without this
				 * guard the branch compared each source dimension against null,
				 * so every product with dimensions reported field_changed on
				 * every run and the sync could never converge. The catalog
				 * record built from SQL does not select dimensions at all.
				 */
				if ( ! array_key_exists( 'dimensions', $record ) ) {
					continue;
				}
				$current_dimensions = isset( $record['dimensions'] ) && is_array( $record['dimensions'] ) ? $record['dimensions'] : array();
				foreach ( $value as $dimension => $dimension_value ) {
					if ( self::normalize_scalar( isset( $current_dimensions[ $dimension ] ) ? $current_dimensions[ $dimension ] : null ) !== self::normalize_scalar( $dimension_value ) ) {
						return false;
					}
				}
				continue;
			}
			if ( ! array_key_exists( $field, $record ) ) {
				continue;
			}
			/*
			 * The writer stores these through sanitize_text_field()/wp_kses_post(),
			 * so the value that lands in WordPress is not always byte-identical to
			 * the source. Comparing the raw source against the stored value would
			 * report field_changed on every run for any row the sanitiser touches
			 * — the same non-round-trip trap that stock and dimensions fell into.
			 * Compare what the writer would actually store.
			 */
			$source_value = $value;
			if ( 'name' === $field && function_exists( 'sanitize_text_field' ) ) {
				$source_value = sanitize_text_field( (string) $value );
			} elseif ( in_array( $field, array( 'description', 'short_description' ), true ) && function_exists( 'wp_kses_post' ) ) {
				$source_value = wp_kses_post( (string) $value );
			}
			if ( self::normalize_for_compare( $source_value ) !== self::normalize_for_compare( $record[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Manual conflict check.
	 *
	 * @param array $record Current record.
	 * @return bool
	 */
	private static function is_conflict( array $record ) {
		/*
		 * Preferred path: compare the product's current post_modified_gmt with
		 * the snapshot the last sync write left behind
		 * (BCS_Catalog_Writer::snapshot_post_modified). Comparing against
		 * _bcs_synced_at instead reports every synced product as conflicted,
		 * because storing _bcs_synced_at bumps post_modified after that
		 * timestamp is taken, and a variable product's parent is bumped again
		 * when WooCommerce syncs its variations. Both sides of this comparison
		 * are GMT, so no timezone coercion is needed.
		 */
		$snapshot = isset( $record['sync_post_modified'] ) ? trim( (string) $record['sync_post_modified'] ) : '';
		if ( '' !== $snapshot ) {
			$modified_gmt  = isset( $record['post_modified_gmt'] ) ? strtotime( (string) $record['post_modified_gmt'] . ' UTC' ) : false;
			$snapshot_time = strtotime( $snapshot . ' UTC' );
			return false !== $modified_gmt && false !== $snapshot_time && $modified_gmt > $snapshot_time;
		}

		// Records written before the snapshot existed keep the original check.
		$post_modified = isset( $record['post_modified'] ) ? strtotime( (string) $record['post_modified'] . ' UTC' ) : false;
		$synced_at     = isset( $record['synced_at'] ) ? strtotime( (string) $record['synced_at'] . ' UTC' ) : false;
		return false !== $post_modified && false !== $synced_at && $post_modified > $synced_at;
	}

	/**
	 * Validate duplicate parent and variation keys.
	 *
	 * @param array $rows Source rows.
	 * @return string|null Duplicate key.
	 */
	private static function duplicate_skus( array $rows ) {
		$seen = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$keys = array( isset( $row['sku'] ) ? $row['sku'] : '' );
			foreach ( (array) ( isset( $row['variations'] ) ? $row['variations'] : array() ) as $variation ) {
				if ( is_array( $variation ) ) {
					$keys[] = isset( $variation['sku'] ) ? $variation['sku'] : '';
				}
			}
			foreach ( $keys as $sku ) {
				$sku = strtolower( trim( (string) $sku ) );
				if ( '' === $sku ) {
					continue;
				}
				if ( isset( $seen[ $sku ] ) ) {
					return strtoupper( $sku );
				}
				$seen[ $sku ] = true;
			}
		}
		return null;
	}

	/**
	 * Create a normalized operation.
	 *
	 * @param string       $op           Operation.
	 * @param string       $sku          Parent SKU.
	 * @param string       $variation_sku Variation SKU.
	 * @param array        $payload      Payload.
	 * @param array|null   $before       Snapshot.
	 * @param string       $reason       Reason.
	 * @param string       $sync_hash    Source hash.
	 * @return array
	 */
	private static function operation( $op, $sku, $variation_sku, array $payload, $before, $reason, $sync_hash ) {
		$operation = array(
			'op'             => $op,
			'sku'            => (string) $sku,
			'variation_sku'  => '' === (string) $variation_sku ? null : (string) $variation_sku,
			'payload'        => $payload,
			'before'         => is_array( $before ) ? $before : null,
			'reason'         => (string) $reason,
			'sync_hash'      => (string) $sync_hash,
			'operation_key'  => sha1( strtolower( (string) $op ) . '|' . strtolower( (string) $sku ) . '|' . strtolower( (string) $variation_sku ) . '|' . (string) $sync_hash ),
		);
		if ( is_array( $before ) && isset( $before['id'] ) ) {
			$operation['target_product_id'] = (int) $before['id'];
		}
		return $operation;
	}

	/**
	 * Sort by stable key so source row reordering cannot alter the diff hash.
	 *
	 * @param array $operations Operations.
	 * @return array
	 */
	private static function sort_operations( array $operations ) {
		usort(
			$operations,
			function ( $left, $right ) {
				$left_key  = strtolower( (string) $left['op'] . '|' . (string) $left['sku'] . '|' . (string) ( isset( $left['variation_sku'] ) ? $left['variation_sku'] : '' ) );
				$right_key = strtolower( (string) $right['op'] . '|' . (string) $right['sku'] . '|' . (string) ( isset( $right['variation_sku'] ) ? $right['variation_sku'] : '' ) );
				return strcmp( $left_key, $right_key );
			}
		);
		return array_values( $operations );
	}

	/**
	 * Summary counters.
	 *
	 * @param array $operations Operations.
	 * @param array $quarantine Quarantine rows.
	 * @return array
	 */
	private static function summary( array $operations, array $quarantine ) {
		$summary = array(
			'create'           => 0,
			'update'           => 0,
			'update_variation' => 0,
			'delete'           => 0,
			'flag_missing'     => 0,
			'conflict'         => 0,
			'quarantined'      => count( $quarantine ),
			'failed'           => 0,
			'total_operations' => count( $operations ),
		);
		foreach ( $operations as $operation ) {
			if ( isset( $summary[ $operation['op'] ] ) ) {
				$summary[ $operation['op'] ]++;
			}
		}
		return $summary;
	}

	/**
	 * Strip volatile fields for diff_hash.
	 *
	 * @param array $operation Operation.
	 * @return array
	 */
	private static function operation_for_hash( array $operation ) {
		return array(
			'op'            => $operation['op'],
			'sku'           => $operation['sku'],
			'variation_sku' => isset( $operation['variation_sku'] ) ? $operation['variation_sku'] : null,
			'payload'       => isset( $operation['payload'] ) ? $operation['payload'] : array(),
			'before'        => isset( $operation['before'] ) ? $operation['before'] : null,
			'reason'        => isset( $operation['reason'] ) ? $operation['reason'] : '',
			'sync_hash'     => isset( $operation['sync_hash'] ) ? $operation['sync_hash'] : '',
		);
	}

	/**
	 * Hash canonical data.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private static function hash_for_payload( array $payload ) {
		$payload = self::strip_internal( $payload );
		self::ksort_recursive( $payload );
		return sha1( self::json( $payload ) );
	}

	/**
	 * Remove parser/runtime-only fields.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function strip_internal( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( in_array( (string) $key, array( 'row_index', 'row_key', 'sync_action', 'sync_note', 'stock_provided', 'operation_key', 'sync_hash' ), true ) ) {
					continue;
				}
				$out[ $key ] = self::strip_internal( $item );
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Recursive key sort.
	 *
	 * @param mixed $value Value by reference.
	 * @return void
	 */
	private static function ksort_recursive( &$value ) {
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as &$item ) {
			self::ksort_recursive( $item );
		}
		unset( $item );
		ksort( $value );
	}

	/**
	 * JSON helper.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function json( $value ) {
		return function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $value ) : (string) json_encode( $value );
	}

	/**
	 * Normalize scalar values for comparisons.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function normalize_for_compare( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_numeric( $value ) ) {
			return (string) ( 0 + $value );
		}
		if ( is_array( $value ) ) {
			$value = self::strip_internal( $value );
			self::ksort_recursive( $value );
			return self::json( $value );
		}
		return trim( (string) $value );
	}

	/**
	 * Normalize one scalar, used by dimension compare.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function normalize_scalar( $value ) {
		return self::normalize_for_compare( $value );
	}

	/**
	 * Catalog count for threshold calculation.
	 *
	 * @param array $state State.
	 * @return int
	 */
	private static function catalog_count( array $state ) {
		return max( 0, isset( $state['catalog_count'] ) ? (int) $state['catalog_count'] : ( isset( $state['products'] ) ? count( $state['products'] ) : 0 ) );
	}
}
