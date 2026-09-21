<?php
/**
 * BCS Sync run state machine, lock, chunked apply, resume, and rollback.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Sync_Runner
 */
class BCS_Sync_Runner {

	const CHUNK_SIZE    = 10;
	const TIME_BUDGET   = 240;
	const STALE_SECONDS = 600;

	/**
	 * Valid run states.
	 *
	 * @return array
	 */
	public static function states() {
		return array( 'pending', 'fetching', 'diffing', 'awaiting_approval', 'approved', 'applying', 'done', 'partially_failed', 'failed_fetch', 'failed_guard', 'rolled_back', 'superseded' );
	}

	/**
	 * Valid state transitions.
	 *
	 * @return array
	 */
	public static function allowed_transitions() {
		return array(
			'pending'           => array( 'fetching' ),
			'fetching'          => array( 'diffing', 'failed_fetch' ),
			'diffing'           => array( 'awaiting_approval', 'applying', 'failed_fetch', 'failed_guard' ),
			'awaiting_approval' => array( 'approved', 'superseded' ),
			'approved'          => array( 'applying' ),
			'applying'          => array( 'done', 'partially_failed' ),
			'done'              => array( 'rolled_back' ),
			'partially_failed'  => array( 'rolled_back' ),
			'failed_fetch'      => array(),
			'failed_guard'      => array(),
			'rolled_back'       => array(),
			'superseded'        => array(),
		);
	}

	/**
	 * Run one connection. A stale applying run is resumed idempotently.
	 *
	 * @param int    $connection_id Connection ID.
	 * @param string $trigger       manual|cron|webhook|mcp.
	 * @return array|WP_Error
	 */
	public static function run( $connection_id, $trigger = 'manual' ) {
		global $wpdb;

		$connection_id = absint( $connection_id );
		$trigger = sanitize_key( $trigger );
		if ( ! in_array( $trigger, array( 'manual', 'cron', 'webhook', 'mcp' ), true ) ) {
			$trigger = 'manual';
		}
		$lock_name = 'bcs_sync_' . $connection_id;
		$locked   = false;
		$run_id   = 0;
		$actor    = self::actor_for_trigger( $trigger );

		try {
			$lock_result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
			if ( '1' !== (string) $lock_result && 1 !== (int) $lock_result ) {
				return new WP_Error( 'lock_busy', __( 'Another sync run is already active for this connection.', 'bulk-catalog-studio' ) );
			}
			$locked = true;

			$connection = BCS_Sync_Connection::get( $connection_id );
			if ( is_wp_error( $connection ) ) {
				return $connection;
			}
			if ( ! $connection ) {
				return new WP_Error( 'bcs_not_found', __( 'Sync connection not found.', 'bulk-catalog-studio' ) );
			}
			if ( 'active' !== $connection['status'] ) {
				return new WP_Error( 'connection_paused', __( 'The sync connection is not active.', 'bulk-catalog-studio' ) );
			}

			$latest = self::latest_run( $connection_id );
			if ( $latest && 'applying' === $latest['state'] ) {
				if ( ! self::is_stale( $latest ) ) {
					return new WP_Error( 'run_in_progress', __( 'The connection already has a fresh applying run.', 'bulk-catalog-studio' ) );
				}
				$run_id = (int) $latest['id'];
				return self::resume_apply( $run_id, $connection, $actor );
			}
			if ( $latest && 'approved' === $latest['state'] ) {
				$run_id = (int) $latest['id'];
				$diff = self::decode_diff( $latest['diff_summary'] );
				$transition = self::transition( $run_id, 'approved', 'applying', array( 'heartbeat_at' => self::now_mysql() ) );
				if ( is_wp_error( $transition ) ) {
					return $transition;
				}
				return self::apply_run( $run_id, $connection, $diff, $actor );
			}
			if ( $latest && in_array( $latest['state'], array( 'pending', 'fetching', 'diffing' ), true ) ) {
				return new WP_Error( 'run_in_progress', __( 'The connection already has a run in progress.', 'bulk-catalog-studio' ) );
			}
			if ( $latest && 'awaiting_approval' === $latest['state'] ) {
				self::transition( (int) $latest['id'], 'awaiting_approval', 'superseded', array( 'finished_at' => self::now_mysql(), 'error_code' => 'superseded' ) );
			}

			$run_id = self::insert_run( $connection_id, $trigger );
			if ( is_wp_error( $run_id ) ) {
				return $run_id;
			}
			BCS_Audit_Logger::log_call( 'bcs/sync-run', 'sync_run', 'ok', __( 'Sync run started.', 'bulk-catalog-studio' ), $run_id, 0, null, 'sync', $actor );

			$transition = self::transition( $run_id, 'pending', 'fetching' );
			if ( is_wp_error( $transition ) ) {
				return self::fail_run( $run_id, 'failed_fetch', $transition->get_error_code(), $transition->get_error_message() );
			}

			$fetcher = BCS_Sync_Fetcher_Factory::make( $connection['fetcher'] );
			if ( is_wp_error( $fetcher ) ) {
				return self::fail_run( $run_id, 'failed_fetch', $fetcher->get_error_code(), $fetcher->get_error_message() );
			}
			$fetched = $fetcher->fetch( $connection );
			if ( is_wp_error( $fetched ) ) {
				return self::fail_run( $run_id, 'failed_fetch', $fetched->get_error_code(), $fetched->get_error_message() );
			}
			self::update_run( $run_id, array( 'fetched_rows' => count( (array) $fetched['rows'] ), 'heartbeat_at' => self::now_mysql() ) );

			$transition = self::transition( $run_id, 'fetching', 'diffing' );
			if ( is_wp_error( $transition ) ) {
				return self::fail_run( $run_id, 'failed_guard', $transition->get_error_code(), $transition->get_error_message() );
			}

			$diff = BCS_Sync_Diff::diff( (array) $fetched['rows'], $connection );
			if ( is_wp_error( $diff ) ) {
				$failed_state = in_array( $diff->get_error_code(), array( 'fetch_zero_rows', 'schema_mismatch', 'fetch_not_csv', 'schema_drift' ), true ) ? 'failed_fetch' : 'failed_guard';
				return self::fail_run( $run_id, $failed_state, $diff->get_error_code(), $diff->get_error_message() );
			}
			self::persist_diff( $run_id, $diff );

			if ( 'awaiting_approval' === $diff['status'] ) {
				$transition = self::transition(
					$run_id,
					'diffing',
					'awaiting_approval',
					array( 'diff_hash' => $diff['diff_hash'], 'error_code' => $diff['approval_reason'], 'heartbeat_at' => self::now_mysql() )
				);
				if ( is_wp_error( $transition ) ) {
					return $transition;
				}
				return self::with_run_result( $run_id, $diff, 'awaiting_approval' );
			}

			$transition = self::transition( $run_id, 'diffing', 'applying', array( 'diff_hash' => $diff['diff_hash'], 'heartbeat_at' => self::now_mysql() ) );
			if ( is_wp_error( $transition ) ) {
				return $transition;
			}
			return self::apply_run( $run_id, $connection, $diff, $actor );
		} catch ( Throwable $exception ) {
			if ( $run_id ) {
				return self::fail_run( $run_id, 'failed_guard', 'runner_exception', $exception->getMessage() );
			}
			return new WP_Error( 'runner_exception', $exception->getMessage() );
		} finally {
			if ( $locked ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
	}

	/**
	 * Atomically transition a run state.
	 *
	 * @param int    $run_id  Run ID.
	 * @param string $expected Expected current state.
	 * @param string $to      Target state.
	 * @param array  $extra   Extra columns.
	 * @return true|WP_Error
	 */
	public static function transition( $run_id, $expected, $to, array $extra = array() ) {
		global $wpdb;
		$allowed = self::allowed_transitions();
		if ( ! isset( $allowed[ $expected ] ) || ! in_array( $to, $allowed[ $expected ], true ) ) {
			return new WP_Error( 'invalid_state', __( 'The sync run state transition is not allowed.', 'bulk-catalog-studio' ) );
		}
		$data = array_merge( $extra, array( 'state' => $to ) );
		if ( ! isset( $data['heartbeat_at'] ) && 'applying' === $to ) {
			$data['heartbeat_at'] = self::now_mysql();
		}
		$formats = self::formats( $data );
		$table = $wpdb->prefix . 'bcs_sync_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic custom-table state transition.
		$result = $wpdb->update( $table, $data, array( 'id' => absint( $run_id ), 'state' => $expected ), $formats, array( '%d', '%s' ) );
		if ( 1 !== (int) $result ) {
			return new WP_Error( 'state_conflict', __( 'The sync run was changed by another process.', 'bulk-catalog-studio' ) );
		}
		return true;
	}

	/**
	 * Alias used by state-machine tests.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $expected Expected state.
	 * @param string $to New state.
	 * @param array  $extra Extra fields.
	 * @return true|WP_Error
	 */
	public static function transition_state( $run_id, $expected, $to, array $extra = array() ) {
		return self::transition( $run_id, $expected, $to, $extra );
	}

	/**
	 * Approve a threshold-held run after an external one-time approval gate.
	 * The caller must validate the token before invoking this service; the
	 * diff_hash guard keeps an approval bound to the exact persisted diff.
	 *
	 * @param int    $run_id    Run ID.
	 * @param string $diff_hash Expected diff hash.
	 * @return array|WP_Error
	 */
	public static function approve_run( $run_id, $diff_hash ) {
		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'bcs_not_found', __( 'Sync run not found.', 'bulk-catalog-studio' ) );
		}
		if ( 'awaiting_approval' !== $run['state'] ) {
			return new WP_Error( 'invalid_state', __( 'Only an awaiting_approval sync run can be approved.', 'bulk-catalog-studio' ) );
		}
		if ( ! hash_equals( (string) $run['diff_hash'], (string) $diff_hash ) ) {
			return new WP_Error( 'diff_hash_mismatch', __( 'The approval diff_hash does not match the stored sync diff.', 'bulk-catalog-studio' ) );
		}
		$transition = self::transition( $run_id, 'awaiting_approval', 'approved', array( 'error_code' => null, 'heartbeat_at' => self::now_mysql() ) );
		if ( is_wp_error( $transition ) ) {
			return $transition;
		}
		return self::get_run( $run_id );
	}

	/**
	 * Alias for approval callers.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $diff_hash Expected diff hash.
	 * @return array|WP_Error
	 */
	public static function approve( $run_id, $diff_hash ) {
		return self::approve_run( $run_id, $diff_hash );
	}

	/**
	 * Read a raw run as a normalized array.
	 *
	 * @param int $run_id Run ID.
	 * @return array|null
	 */
	public static function get_run( $run_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bcs_sync_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; id prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $run_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		if ( ! $row ) {
			return null;
		}
		return self::normalize_run( $row );
	}

	/**
	 * Latest run for a connection.
	 *
	 * @param int $connection_id Connection ID.
	 * @return array|null
	 */
	public static function latest_run( $connection_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bcs_sync_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; id prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id = %d ORDER BY id DESC LIMIT 1", absint( $connection_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		return $row ? self::normalize_run( $row ) : null;
	}

	/**
	 * Roll back only the latest completed run for a connection.
	 *
	 * @param int $run_id Run ID.
	 * @return array|WP_Error
	 */
	public static function rollback_run( $run_id ) {
		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'bcs_not_found', __( 'Sync run not found.', 'bulk-catalog-studio' ) );
		}
		if ( ! in_array( $run['state'], array( 'done', 'partially_failed' ), true ) ) {
			return new WP_Error( 'invalid_state', __( 'Only a done or partially_failed sync run can be rolled back.', 'bulk-catalog-studio' ) );
		}
		$latest = self::latest_run( $run['connection_id'] );
		if ( ! $latest || (int) $latest['id'] !== (int) $run['id'] ) {
			return new WP_Error( 'run_not_latest', __( 'Only the latest sync run for a connection can be rolled back.', 'bulk-catalog-studio' ) );
		}

		$diff = self::decode_diff( $run['diff_summary'] );
		$counts = array( 'products_trashed' => 0, 'products_restored' => 0, 'variations_restored' => 0, 'failed' => 0 );
		$notes = array();
		foreach ( (array) ( isset( $diff['operations'] ) ? $diff['operations'] : array() ) as $operation ) {
			$key = isset( $operation['operation_key'] ) ? $operation['operation_key'] : '';
			if ( '' === $key || ! BCS_Audit_Logger::has_row_success( $run_id, $key, 'sync' ) ) {
				continue;
			}
			$sku = isset( $operation['sku'] ) ? $operation['sku'] : null;
			$variation_sku = isset( $operation['variation_sku'] ) ? $operation['variation_sku'] : null;
			$target = isset( $operation['target_product_id'] ) ? absint( $operation['target_product_id'] ) : 0;
			if ( 'create' === $operation['op'] ) {
				$target = BCS_Audit_Logger::find_row_product_id( $run_id, $key, 'create_product', 'sync' );
				if ( $target ) {
					$result = BCS_Catalog_Writer::trash_product( $target );
					if ( is_wp_error( $result ) ) {
						$counts['failed']++;
						$notes[] = $result->get_error_message();
					} else {
						$counts['products_trashed']++;
					}
				}
			} elseif ( 'update' === $operation['op'] && $target && ! empty( $operation['before'] ) ) {
				$result = BCS_Catalog_Writer::restore_from_snapshot( $target, (array) $operation['before'] );
				if ( is_wp_error( $result ) ) {
					$counts['failed']++;
					$notes[] = $result->get_error_message();
				} else {
					$counts['products_restored']++;
				}
			} elseif ( 'update_variation' === $operation['op'] && $target && $variation_sku && ! empty( $operation['before'] ) ) {
				$before = (array) $operation['before'];
				$restore = BCS_Catalog_Writer::update_variation(
					$target,
					$variation_sku,
					array(
						'sku'            => isset( $before['sku'] ) ? $before['sku'] : $variation_sku,
						'regular_price'  => isset( $before['regular_price'] ) ? $before['regular_price'] : '',
						'sale_price'     => isset( $before['sale_price'] ) ? $before['sale_price'] : '',
						'stock_quantity' => isset( $before['stock_quantity'] ) ? $before['stock_quantity'] : 0,
					),
					array( 'allow_sku_change' => true )
				);
				if ( is_wp_error( $restore ) ) {
					$counts['failed']++;
					$notes[] = $restore->get_error_message();
				} else {
					$counts['variations_restored']++;
				}
			}
		}
		$transition = self::transition( $run_id, $run['state'], 'rolled_back', array( 'finished_at' => self::now_mysql(), 'heartbeat_at' => self::now_mysql() ) );
		if ( is_wp_error( $transition ) ) {
			return $transition;
		}
		BCS_Audit_Logger::log_call( 'bcs/sync-run', 'rollback_run', 'rolled_back', __( 'Sync run rollback completed.', 'bulk-catalog-studio' ), $run_id, 0, null, 'sync', 'sync' );
		return array( 'run_id' => (int) $run_id, 'status' => 'rolled_back', 'counts' => $counts, 'note' => implode( ' | ', $notes ) );
	}

	/**
	 * Resume a stale applying run.
	 *
	 * @param int    $run_id Run ID.
	 * @param array  $connection Connection.
	 * @param string $actor Actor value.
	 * @return array|WP_Error
	 */
	private static function resume_apply( $run_id, array $connection, $actor ) {
		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'bcs_not_found', __( 'Applying sync run not found.', 'bulk-catalog-studio' ) );
		}
		$diff = self::decode_diff( $run['diff_summary'] );
		if ( empty( $diff['operations'] ) && empty( $diff['summary'] ) ) {
			return self::fail_run( $run_id, 'failed_guard', 'missing_diff', __( 'The applying run has no persisted diff to resume.', 'bulk-catalog-studio' ) );
		}
		return self::apply_run( $run_id, $connection, $diff, $actor );
	}

	/**
	 * Apply a persisted diff in chunks.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $connection Connection.
	 * @param array $diff Diff.
	 * @param string $actor Actor.
	 * @return array|WP_Error
	 */
	private static function apply_run( $run_id, array $connection, array $diff, $actor ) {
		$started = microtime( true );
		$summary = self::empty_apply_summary();
		$failures = array();
		$did_success = false;
		$operations = (array) ( isset( $diff['operations'] ) ? $diff['operations'] : array() );
		foreach ( $operations as $index => $operation ) {
			if ( 0 === $index % self::CHUNK_SIZE ) {
				self::update_run( $run_id, array( 'heartbeat_at' => self::now_mysql() ) );
			}
			if ( $index > 0 && 0 === $index % self::CHUNK_SIZE && ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
				return array_merge( $diff, array( 'run_id' => (int) $run_id, 'status' => 'applying', 'deferred' => true, 'summary' => $summary, 'failures' => $failures ) );
			}
			if ( self::operation_already_applied( $run_id, $operation ) ) {
				continue;
			}
			$result = self::apply_operation( $run_id, $connection, $operation, $actor );
			if ( is_wp_error( $result ) ) {
				$summary['failed']++;
				$failures[] = array( 'sku' => isset( $operation['sku'] ) ? $operation['sku'] : null, 'reason' => $result->get_error_message() );
				BCS_Audit_Logger::log_row( 'bcs/sync-run', (string) $operation['op'], 'failed', 0, isset( $operation['sku'] ) ? $operation['sku'] : null, isset( $operation['target_product_id'] ) ? $operation['target_product_id'] : null, $result->get_error_message(), isset( $operation['operation_key'] ) ? $operation['operation_key'] : null, $run_id, 0, 'sync', $actor );
				continue;
			}
			$did_success = true;
			$op_name = (string) $operation['op'];
			if ( isset( $summary[ $op_name ] ) ) {
				$summary[ $op_name ]++;
			}
		}

		$final = $summary['failed'] > 0 ? ( $did_success ? 'partially_failed' : 'partially_failed' ) : 'done';
		$summary['quarantined'] = isset( $diff['summary']['quarantined'] ) ? (int) $diff['summary']['quarantined'] : 0;
		$summary['total_operations'] = count( $operations );
		$transition = self::transition(
			$run_id,
			'applying',
			$final,
			array(
				'finished_at' => self::now_mysql(),
				'heartbeat_at' => self::now_mysql(),
				'diff_summary' => self::json( array( 'summary' => $summary, 'quarantine' => isset( $diff['quarantine'] ) ? $diff['quarantine'] : array(), 'operations' => $operations, 'seen_skus' => isset( $diff['seen_skus'] ) ? $diff['seen_skus'] : array() ) ),
			)
		);
		if ( is_wp_error( $transition ) ) {
			return $transition;
		}
		if ( 'done' === $final ) {
			BCS_Sync_Connection::update_seen_skus( $connection['id'], isset( $diff['seen_skus'] ) ? (array) $diff['seen_skus'] : array() );
			self::mark_connection_ok( $connection['id'] );
		}
		BCS_Audit_Logger::log_call( 'bcs/sync-run', 'sync_run', 'ok', sprintf( __( 'Sync run finished: %s.', 'bulk-catalog-studio' ), $final ), $run_id, 0, null, 'sync', $actor );
		return array_merge( $diff, array( 'run_id' => (int) $run_id, 'status' => $final, 'summary' => $summary, 'failures' => $failures, 'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ) ) );
	}

	/**
	 * Apply one operation through the catalog writer.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $connection Connection.
	 * @param array $operation Operation.
	 * @param string $actor Actor.
	 * @return array|true|WP_Error
	 */
	private static function apply_operation( $run_id, array $connection, array $operation, $actor ) {
		$op = isset( $operation['op'] ) ? (string) $operation['op'] : '';
		$sku = isset( $operation['sku'] ) ? (string) $operation['sku'] : null;
		$target = isset( $operation['target_product_id'] ) ? absint( $operation['target_product_id'] ) : 0;
		$result = true;
		$product_id = $target;
		$action = $op;

		if ( 'create' === $op ) {
			$payload = isset( $operation['payload'] ) && is_array( $operation['payload'] ) ? $operation['payload'] : array();
			$create_op = array(
				'op'           => 'create_product',
				'product_type' => ! empty( $payload['variations'] ) || ! empty( $payload['attributes'] ) ? 'variable' : 'simple',
				'row_key'      => isset( $operation['operation_key'] ) ? $operation['operation_key'] : '',
				'payload'      => $payload,
			);
			$result = BCS_Catalog_Writer::execute_operation( $create_op, $run_id, 0 );
			if ( ! is_wp_error( $result ) ) {
				$product_id = (int) $result['product_id'];
				$sync_state = BCS_Catalog_Writer::set_sync_state( $product_id, $operation['sync_hash'], $connection['id'] );
				if ( is_wp_error( $sync_state ) ) {
					$result = $sync_state;
				} else {
					self::set_created_variation_states( $product_id, isset( $payload['variations'] ) ? (array) $payload['variations'] : array(), $connection['id'] );
				}
			}
			$action = 'create_product';
		} elseif ( 'update' === $op ) {
			if ( ! $target && class_exists( 'BCS_Duplicate_Checker' ) ) {
				$target = (int) BCS_Duplicate_Checker::find_by_sku( $sku );
				$product_id = $target;
			}
			$update_op = array(
				'op'                => 'update_product',
				'target_product_id' => $target,
				'row_key'           => isset( $operation['operation_key'] ) ? $operation['operation_key'] : '',
				'payload'           => isset( $operation['payload'] ) ? $operation['payload'] : array(),
			);
			$result = BCS_Catalog_Writer::execute_operation( $update_op, $run_id, 0 );
			if ( ! is_wp_error( $result ) ) {
				$product_id = (int) $result['product_id'];
				$sync_state = BCS_Catalog_Writer::set_sync_state( $product_id, $operation['sync_hash'], $connection['id'] );
				if ( is_wp_error( $sync_state ) ) {
					$result = $sync_state;
				}
			}
			$action = 'update_product';
		} elseif ( 'update_variation' === $op ) {
			$result = BCS_Catalog_Writer::update_variation( $target, (string) $operation['variation_sku'], (array) $operation['payload'], array( 'sync_hash' => $operation['sync_hash'], 'connection_id' => $connection['id'] ) );
			if ( ! is_wp_error( $result ) && isset( $result['variation_id'] ) ) {
				$product_id = $result['variation_id'];
			}
			$action = 'update_variation';
		} elseif ( 'delete' === $op ) {
			if ( ! $target && class_exists( 'BCS_Duplicate_Checker' ) ) {
				$target = (int) BCS_Duplicate_Checker::find_by_sku( $sku );
				$product_id = $target;
			}
			$result = $target ? BCS_Catalog_Writer::trash_product( $target ) : new WP_Error( 'bcs_not_found', __( 'Product not found for sync delete.', 'bulk-catalog-studio' ) );
			$action = 'trash_product';
		} elseif ( 'flag_missing' === $op ) {
			$action = 'flag_missing';
			$result = true;
		} elseif ( 'conflict' === $op ) {
			if ( ! $target && class_exists( 'BCS_Duplicate_Checker' ) ) {
				$target = (int) BCS_Duplicate_Checker::find_by_sku( $sku );
				$product_id = $target;
			}
			$result = $target ? BCS_Catalog_Writer::flag_sync_conflict( $target ) : new WP_Error( 'bcs_not_found', __( 'Product not found for conflict flag.', 'bulk-catalog-studio' ) );
			$action = 'conflict';
		} else {
			return new WP_Error( 'invalid_operation', __( 'Unknown sync operation.', 'bulk-catalog-studio' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$message = ! empty( $operation['sync_note'] ) ? (string) $operation['sync_note'] : __( 'Sync operation applied.', 'bulk-catalog-studio' );
		BCS_Audit_Logger::log_row( 'bcs/sync-run', $action, 'ok', 0, $sku, $product_id ? $product_id : null, $message, isset( $operation['operation_key'] ) ? $operation['operation_key'] : null, $run_id, 0, 'sync', $actor );
		return $result;
	}

	/**
	 * Save per-variation hashes after a create operation.
	 *
	 * @param int   $product_id         Parent product ID.
	 * @param array $source_variations  Source variation payloads.
	 * @param int   $connection_id      Connection ID.
	 * @return void
	 */
	private static function set_created_variation_states( $product_id, array $source_variations, $connection_id ) {
		if ( ! function_exists( 'wc_get_product' ) || empty( $source_variations ) ) {
			return;
		}
		$parent = wc_get_product( absint( $product_id ) );
		if ( ! $parent instanceof WC_Product ) {
			return;
		}
		$hashes = array();
		foreach ( $source_variations as $source_variation ) {
			if ( ! is_array( $source_variation ) || empty( $source_variation['sku'] ) ) {
				continue;
			}
			$hashes[ strtolower( trim( (string) $source_variation['sku'] ) ) ] = BCS_Sync_Diff::payload_hash( $source_variation );
		}
		foreach ( (array) $parent->get_children( 'edit' ) as $child_id ) {
			$child = wc_get_product( absint( $child_id ) );
			if ( ! $child instanceof WC_Product_Variation ) {
				continue;
			}
			$key = strtolower( trim( (string) $child->get_sku( 'edit' ) ) );
			if ( isset( $hashes[ $key ] ) ) {
				BCS_Catalog_Writer::set_sync_state( $child->get_id(), $hashes[ $key ], $connection_id );
			}
		}
	}

	/**
	 * Detect idempotent completion using audit and sync hash meta.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $operation Operation.
	 * @return bool
	 */
	private static function operation_already_applied( $run_id, array $operation ) {
		$key = isset( $operation['operation_key'] ) ? (string) $operation['operation_key'] : '';
		if ( '' !== $key && class_exists( 'BCS_Audit_Logger' ) && BCS_Audit_Logger::has_row_success( $run_id, $key, 'sync' ) ) {
			return true;
		}
		$hash = isset( $operation['sync_hash'] ) ? (string) $operation['sync_hash'] : '';
		if ( '' === $hash || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}
		$target = isset( $operation['target_product_id'] ) ? absint( $operation['target_product_id'] ) : 0;
		if ( in_array( $operation['op'], array( 'create', 'update', 'delete', 'conflict' ), true ) && ! $target && class_exists( 'BCS_Duplicate_Checker' ) ) {
			$target = (int) BCS_Duplicate_Checker::find_by_sku( isset( $operation['sku'] ) ? $operation['sku'] : '' );
		}
		if ( 'update_variation' !== $operation['op'] && $target && hash_equals( $hash, (string) get_post_meta( $target, '_bcs_sync_hash', true ) ) ) {
			return true;
		}
		if ( 'update_variation' === $operation['op'] && $target && function_exists( 'wc_get_product' ) ) {
			$parent = wc_get_product( $target );
			if ( $parent instanceof WC_Product ) {
				foreach ( (array) $parent->get_children( 'edit' ) as $child_id ) {
					$child = wc_get_product( absint( $child_id ) );
					if ( $child instanceof WC_Product_Variation && strtolower( (string) $child->get_sku( 'edit' ) ) === strtolower( (string) $operation['variation_sku'] ) && hash_equals( $hash, (string) get_post_meta( $child->get_id(), '_bcs_sync_hash', true ) ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Persist diff in the run JSON column.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $diff Diff.
	 * @return void
	 */
	private static function persist_diff( $run_id, array $diff ) {
		self::update_run(
			$run_id,
			array(
				'diff_hash'    => $diff['diff_hash'],
				'diff_summary' => self::json( array( 'summary' => $diff['summary'], 'quarantine' => $diff['quarantine'], 'operations' => $diff['operations'], 'seen_skus' => $diff['seen_skus'] ) ),
				'heartbeat_at' => self::now_mysql(),
			)
		);
	}

	/**
	 * Mark a run failure and return the same WP_Error to the caller.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $state Failure state.
	 * @param string $code Error code.
	 * @param string $note Error note.
	 * @return WP_Error
	 */
	private static function fail_run( $run_id, $state, $code, $note ) {
		$run = self::get_run( $run_id );
		if ( $run && isset( self::allowed_transitions()[ $run['state'] ] ) && in_array( $state, self::allowed_transitions()[ $run['state'] ], true ) ) {
			self::transition( $run_id, $run['state'], $state, array( 'error_code' => substr( sanitize_key( $code ), 0, 60 ), 'error_note' => sanitize_textarea_field( $note ), 'finished_at' => self::now_mysql(), 'heartbeat_at' => self::now_mysql() ) );
		} else {
			self::update_run( $run_id, array( 'error_code' => substr( sanitize_key( $code ), 0, 60 ), 'error_note' => sanitize_textarea_field( $note ), 'finished_at' => self::now_mysql() ) );
		}
		if ( in_array( $code, array( 'schema_drift', 'fetch_not_csv', 'fetch_http', 'fetch_zero_rows' ), true ) ) {
			$run = self::get_run( $run_id );
			if ( $run ) {
				BCS_Sync_Connection::update( $run['connection_id'], array( 'status' => 'error_state' ) );
			}
		}
		BCS_Audit_Logger::log_call( 'bcs/sync-run', 'sync_run', 'failed', $note, $run_id, 0, null, 'sync', 'sync' );
		return new WP_Error( $code, $note );
	}

	/**
	 * Insert a pending run.
	 *
	 * @param int    $connection_id Connection ID.
	 * @param string $trigger Trigger.
	 * @return int|WP_Error
	 */
	private static function insert_run( $connection_id, $trigger ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bcs_sync_runs';
		$now = self::now_mysql();
		$data = array(
			'connection_id' => absint( $connection_id ),
			'trigger'       => $trigger,
			'state'         => 'pending',
			'diff_hash'     => null,
			'diff_summary'  => null,
			'fetched_rows'  => 0,
			'error_code'    => null,
			'error_note'    => null,
			'started_at'    => $now,
			'finished_at'   => null,
			'heartbeat_at'  => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; wpdb insert prepares values.
		$inserted = $wpdb->insert( $table, $data, self::formats( $data ) );
		if ( ! $inserted ) {
			return new WP_Error( 'bcs_db_error', __( 'Failed to create the sync run.', 'bulk-catalog-studio' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update arbitrary safe run fields.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $fields Fields.
	 * @return true|WP_Error
	 */
	private static function update_run( $run_id, array $fields ) {
		global $wpdb;
		$allowed = array( 'connection_id', 'trigger', 'state', 'diff_hash', 'diff_summary', 'fetched_rows', 'error_code', 'error_note', 'started_at', 'finished_at', 'heartbeat_at' );
		$data = array();
		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$data[ $key ] = $value;
			}
		}
		if ( empty( $data ) ) {
			return true;
		}
		$table = $wpdb->prefix . 'bcs_sync_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$updated = $wpdb->update( $table, $data, array( 'id' => absint( $run_id ) ), self::formats( $data ), array( '%d' ) );
		return false === $updated ? new WP_Error( 'bcs_db_error', __( 'Failed to update the sync run.', 'bulk-catalog-studio' ) ) : true;
	}

	/**
	 * Mark connection successful.
	 *
	 * @param int $connection_id Connection ID.
	 * @return void
	 */
	private static function mark_connection_ok( $connection_id ) {
		BCS_Sync_Connection::update( $connection_id, array( 'status' => 'active', 'last_ok_run_at' => self::now_mysql() ) );
	}

	/**
	 * Build a response with run metadata.
	 *
	 * @param int    $run_id Run ID.
	 * @param array  $diff Diff.
	 * @param string $state State.
	 * @return array
	 */
	private static function with_run_result( $run_id, array $diff, $state ) {
		return array_merge( $diff, array( 'run_id' => (int) $run_id, 'state' => $state, 'status' => $state ) );
	}

	/**
	 * Normalize DB run.
	 *
	 * @param object $row DB row.
	 * @return array
	 */
	private static function normalize_run( $row ) {
		$row = is_object( $row ) ? get_object_vars( $row ) : (array) $row;
		return array(
			'id'            => (int) ( isset( $row['id'] ) ? $row['id'] : 0 ),
			'connection_id' => (int) ( isset( $row['connection_id'] ) ? $row['connection_id'] : 0 ),
			'trigger'       => (string) ( isset( $row['trigger'] ) ? $row['trigger'] : '' ),
			'state'         => (string) ( isset( $row['state'] ) ? $row['state'] : '' ),
			'diff_hash'     => isset( $row['diff_hash'] ) ? $row['diff_hash'] : null,
			'diff_summary'  => isset( $row['diff_summary'] ) ? $row['diff_summary'] : null,
			'fetched_rows'  => (int) ( isset( $row['fetched_rows'] ) ? $row['fetched_rows'] : 0 ),
			'error_code'    => isset( $row['error_code'] ) ? $row['error_code'] : null,
			'error_note'    => isset( $row['error_note'] ) ? $row['error_note'] : null,
			'started_at'    => isset( $row['started_at'] ) ? $row['started_at'] : null,
			'finished_at'   => isset( $row['finished_at'] ) ? $row['finished_at'] : null,
			'heartbeat_at'  => isset( $row['heartbeat_at'] ) ? $row['heartbeat_at'] : null,
		);
	}

	/**
	 * Decode persisted diff JSON.
	 *
	 * @param mixed $json JSON.
	 * @return array
	 */
	private static function decode_diff( $json ) {
		$decoded = json_decode( (string) $json, true );
		return is_array( $decoded ) ? $decoded : array( 'operations' => array(), 'summary' => array() );
	}

	/**
	 * Is heartbeat older than ten minutes?
	 *
	 * @param array $run Run.
	 * @return bool
	 */
	private static function is_stale( array $run ) {
		$heartbeat = ! empty( $run['heartbeat_at'] ) ? $run['heartbeat_at'] : $run['finished_at'];
		$time = $heartbeat ? strtotime( (string) $heartbeat . ' UTC' ) : 0;
		return ! $time || $time < time() - self::STALE_SECONDS;
	}

	/**
	 * Actor value for audit.
	 *
	 * @param string $trigger Trigger.
	 * @return string
	 */
	private static function actor_for_trigger( $trigger ) {
		if ( 'cron' === $trigger ) {
			return 'cron';
		}
		if ( 'mcp' === $trigger ) {
			return 'mcp';
		}
		return 'sync';
	}

	/**
	 * Empty application counters.
	 *
	 * @return array
	 */
	private static function empty_apply_summary() {
		return array( 'create' => 0, 'update' => 0, 'update_variation' => 0, 'delete' => 0, 'flag_missing' => 0, 'conflict' => 0, 'quarantined' => 0, 'failed' => 0, 'total_operations' => 0 );
	}

	/**
	 * MySQL UTC timestamp.
	 *
	 * @return string
	 */
	private static function now_mysql() {
		return class_exists( 'BCS_Settings' ) ? BCS_Settings::now_mysql() : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Formats for wpdb insert/update.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private static function formats( array $data ) {
		$formats = array();
		foreach ( $data as $key => $value ) {
			if ( 'connection_id' === $key || 'fetched_rows' === $key || 'id' === $key ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
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
}
