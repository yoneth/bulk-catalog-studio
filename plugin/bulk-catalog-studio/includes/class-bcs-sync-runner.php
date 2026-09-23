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
	 * @param int         $connection_id Connection ID.
	 * @param string      $trigger       manual|cron|webhook|mcp.
	 * @param string|null $actor_override Optional audit actor (for example mcp).
	 * @return array|WP_Error
	 */
	public static function run( $connection_id, $trigger = 'manual', $actor_override = null ) {
		global $wpdb;

		$connection_id = absint( $connection_id );
		$trigger = sanitize_key( $trigger );
		if ( ! in_array( $trigger, array( 'manual', 'cron', 'webhook', 'mcp' ), true ) ) {
			$trigger = 'manual';
		}
		$lock_name = 'bcs_sync_' . $connection_id;
		$locked   = false;
		$run_id   = 0;
		$actor    = null !== $actor_override ? sanitize_key( $actor_override ) : self::actor_for_trigger( $trigger );
		if ( ! in_array( $actor, array( 'sync', 'cron', 'mcp', 'wp_admin' ), true ) ) {
			$actor = self::actor_for_trigger( $trigger );
		}

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
					return new WP_Error( 'lock_busy', __( 'Another sync run is already active for this connection.', 'bulk-catalog-studio' ) );
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
			if ( $latest && in_array( $latest['state'], array( 'running', 'pending', 'fetching', 'diffing' ), true ) ) {
				return new WP_Error( 'lock_busy', __( 'Another sync run is already active for this connection.', 'bulk-catalog-studio' ) );
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
				return self::fail_run( $run_id, 'failed_fetch', $transition->get_error_code(), $transition->get_error_message(), $actor );
			}

			$fetcher = BCS_Sync_Fetcher_Factory::make( $connection['fetcher'] );
			if ( is_wp_error( $fetcher ) ) {
				return self::fail_run( $run_id, 'failed_fetch', $fetcher->get_error_code(), $fetcher->get_error_message(), $actor );
			}
			$fetched = $fetcher->fetch( $connection );
			if ( is_wp_error( $fetched ) ) {
				return self::fail_run( $run_id, 'failed_fetch', $fetched->get_error_code(), $fetched->get_error_message(), $actor );
			}
			self::update_run( $run_id, array( 'fetched_rows' => count( (array) $fetched['rows'] ), 'heartbeat_at' => self::now_mysql() ) );

			$transition = self::transition( $run_id, 'fetching', 'diffing' );
			if ( is_wp_error( $transition ) ) {
				return self::fail_run( $run_id, 'failed_guard', $transition->get_error_code(), $transition->get_error_message(), $actor );
			}

			$diff = BCS_Sync_Diff::diff( (array) $fetched['rows'], $connection );
			if ( is_wp_error( $diff ) ) {
				$failed_state = in_array( $diff->get_error_code(), array( 'fetch_zero_rows', 'schema_mismatch', 'fetch_not_csv', 'schema_drift' ), true ) ? 'failed_fetch' : 'failed_guard';
				return self::fail_run( $run_id, $failed_state, $diff->get_error_code(), $diff->get_error_message(), $actor );
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
				return self::fail_run( $run_id, 'failed_guard', 'runner_exception', $exception->getMessage(), $actor );
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
	 * Approve a threshold-held run after validating its one-time token.
	 * The diff_hash and token checks keep an approval bound to the exact
	 * persisted diff and a single approval event.
	 *
	 * @param int         $run_id    Run ID.
	 * @param string      $diff_hash Expected diff hash.
	 * @param string|null $token     Plaintext approval token.
	 * @param int|null    $user_id   Approving user ID.
	 * @return array|WP_Error
	 */
	public static function approve_run( $run_id, $diff_hash, $token = null, $user_id = null ) {
		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'bcs_not_found', __( 'Sync run not found.', 'bulk-catalog-studio' ) );
		}
		if ( ! hash_equals( (string) $run['diff_hash'], (string) $diff_hash ) ) {
			return new WP_Error( 'diff_hash_mismatch', __( 'The approval diff_hash does not match the stored sync diff.', 'bulk-catalog-studio' ) );
		}

		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
		$verify = self::verify_run_token( $run_id, $diff_hash, (string) $token );
		if ( is_wp_error( $verify ) ) {
			return $verify;
		}

		if ( 'awaiting_approval' !== $run['state'] ) {
			return new WP_Error( 'invalid_state', __( 'Only an awaiting_approval sync run can be approved.', 'bulk-catalog-studio' ) );
		}

		// MAJOR-2: win the state transition before consuming the token. Keep both
		// writes in one transaction so a post-transition DB failure cannot leave
		// an approved run with an unconsumed token.
		global $wpdb;
		$transaction = false;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction control for the custom-table approval mutation.
			$transaction = false !== $wpdb->query( 'START TRANSACTION' );
		}
		$transition = self::transition( $run_id, 'awaiting_approval', 'approved', array( 'error_code' => null, 'heartbeat_at' => self::now_mysql() ) );
		if ( is_wp_error( $transition ) ) {
			if ( $transaction ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Roll back the approval transaction.
				$wpdb->query( 'ROLLBACK' );
			}
			return $transition;
		}
		$consume = self::consume_run_token( $run_id, $user_id );
		if ( is_wp_error( $consume ) ) {
			if ( $transaction ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Roll back the approval transaction.
				$wpdb->query( 'ROLLBACK' );
			}
			return $consume;
		}
		if ( $transaction ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commit the approval transaction.
			$committed = $wpdb->query( 'COMMIT' );
			if ( false === $committed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Roll back when commit fails.
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'bcs_db_error', __( 'The sync approval transaction could not be committed.', 'bulk-catalog-studio' ) );
			}
		}
		return self::get_run( $run_id );
	}

	/**
	 * Generate a plaintext run approval token once.
	 *
	 * @param int $run_id Run ID.
	 * @return string|WP_Error Plaintext token, never persisted in plaintext.
	 */
	public static function generate_run_token( $run_id ) {
		global $wpdb;

		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'bcs_not_found', __( 'Sync run not found.', 'bulk-catalog-studio' ) );
		}
		if ( 'awaiting_approval' !== $run['state'] ) {
			return new WP_Error( 'invalid_state', __( 'Approval tokens can only be generated for an awaiting_approval sync run.', 'bulk-catalog-studio' ) );
		}
		if ( '' === (string) $run['diff_hash'] ) {
			return new WP_Error( 'bcs_hash_missing', __( 'The sync run has no persisted diff_hash to bind the approval token to.', 'bulk-catalog-studio' ) );
		}
		if ( ! function_exists( 'wp_hash_password' ) ) {
			return new WP_Error( 'bcs_token_unavailable', __( 'WordPress password hashing is unavailable for sync approval tokens.', 'bulk-catalog-studio' ) );
		}

		$charset = self::run_token_charset();
		$suffix  = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$suffix .= $charset[ wp_rand( 0, strlen( $charset ) - 1 ) ];
		}
		$token   = 'BCS-' . $suffix;
		$now     = self::now_mysql();
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + ( 24 * HOUR_IN_SECONDS ) );
		$table   = $wpdb->prefix . 'bcs_sync_runs';

		// Generate replaces a previous unused token; only the hash is stored.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; wpdb prepares values.
		$updated = $wpdb->update(
			$table,
			array(
				'token_hash'       => wp_hash_password( $token ),
				'token_expires_at' => $expires,
				'token_used_at'    => null,
				'approved_by'      => null,
				'approved_at'      => null,
			),
			array( 'id' => absint( $run_id ), 'state' => 'awaiting_approval' ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'bcs_db_error', __( 'Failed to store the sync approval token for this awaiting run.', 'bulk-catalog-studio' ) );
		}

		return $token;
	}

	/**
	 * Verify a run token against the stored diff hash and 24-hour expiry.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $diff_hash Expected diff hash.
	 * @param string $token Plaintext token.
	 * @return true|WP_Error
	 */
	public static function verify_run_token( $run_id, $diff_hash, $token ) {
		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'bcs_not_found', __( 'Sync run not found.', 'bulk-catalog-studio' ) );
		}
		if ( ! hash_equals( (string) $run['diff_hash'], (string) $diff_hash ) ) {
			return new WP_Error( 'diff_hash_mismatch', __( 'The approval diff_hash does not match the stored sync diff.', 'bulk-catalog-studio' ) );
		}
		if ( empty( $run['token_hash'] ) ) {
			return new WP_Error( 'bcs_token_missing', __( 'No approval token exists for this sync run yet. Generate one on the admin Sync screen.', 'bulk-catalog-studio' ) );
		}
		if ( ! empty( $run['token_used_at'] ) ) {
			return new WP_Error( 'bcs_token_used', __( 'The sync approval token has already been used. Generate a new token on the admin Sync screen.', 'bulk-catalog-studio' ) );
		}
		if ( ! empty( $run['token_expires_at'] ) && strtotime( (string) $run['token_expires_at'] . ' UTC' ) < time() ) {
			return new WP_Error( 'bcs_token_expired', __( 'The sync approval token has expired after 24 hours. Generate a new token.', 'bulk-catalog-studio' ) );
		}

		$token = strtoupper( trim( (string) $token ) );
		if ( ! preg_match( '/^[A-Z0-9-]{6,12}$/', $token ) ) {
			return new WP_Error( 'bcs_token_invalid', __( 'Invalid sync token format (must be 6–12 characters A-Z0-9-).', 'bulk-catalog-studio' ) );
		}
		if ( ! function_exists( 'wp_check_password' ) || ! wp_check_password( $token, (string) $run['token_hash'], 0 ) ) {
			return new WP_Error( 'bcs_token_invalid', __( 'Wrong sync approval token.', 'bulk-catalog-studio' ) );
		}

		return true;
	}

	/**
	 * Atomically consume a run token after the run has transitioned.
	 *
	 * @param int $run_id Run ID.
	 * @param int $user_id Approving user.
	 * @return true|WP_Error
	 */
	public static function consume_run_token( $run_id, $user_id ) {
		global $wpdb;

		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'bcs_not_found', __( 'Sync run not found.', 'bulk-catalog-studio' ) );
		}
		if ( ! empty( $run['token_used_at'] ) ) {
			return new WP_Error( 'bcs_token_used', __( 'The sync approval token has already been used.', 'bulk-catalog-studio' ) );
		}

		$now   = self::now_mysql();
		$table = $wpdb->prefix . 'bcs_sync_runs';
		// The WHERE token_used_at IS NULL clause is the single-use gate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table name is derived from the WP prefix; all values are prepared.
		$affected = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from the WP prefix; values use placeholders.
			$wpdb->prepare(
				"UPDATE {$table} SET token_used_at = %s, approved_by = %d, approved_at = %s WHERE id = %d AND token_used_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from the WP prefix; values use placeholders.
				$now,
				absint( $user_id ),
				$now,
				absint( $run_id )
			)
		);
		if ( 1 !== (int) $affected ) {
			return new WP_Error( 'bcs_token_used', __( 'The sync approval token was already consumed by another process (atomic single-use).', 'bulk-catalog-studio' ) );
		}

		return true;
	}

	/**
	 * Charset shared with BCS_Approval_Token without changing that class.
	 *
	 * @return string
	 */
	private static function run_token_charset() {
		return 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	}

	/**
	 * Alias for approval callers.
	 *
	 * @param int         $run_id    Run ID.
	 * @param string      $diff_hash Expected diff hash.
	 * @param string|null $token     Plaintext approval token.
	 * @param int|null    $user_id   Approving user ID.
	 * @return array|WP_Error
	 */
	public static function approve( $run_id, $diff_hash, $token = null, $user_id = null ) {
		return self::approve_run( $run_id, $diff_hash, $token, $user_id );
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
	 * List sync runs for the admin screen.
	 *
	 * @param int $connection_id Optional connection filter.
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function list_runs( $connection_id = 0, $limit = 100 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bcs_sync_runs';
		$limit = max( 1, min( 500, absint( $limit ) ) );
		if ( absint( $connection_id ) > 0 ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id = %d ORDER BY id DESC LIMIT %d", absint( $connection_id ), $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom table and prepared filter/limit SQL.
		$rows = $wpdb->get_results( $sql );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::normalize_run( $row );
		}
		return $out;
	}

	/**
	 * Return unresolved quarantine entries, enriched with their source run.
	 *
	 * @param int $connection_id Optional connection filter.
	 * @param int $limit Maximum entries.
	 * @return array
	 */
	/**
	 * Identity of a quarantine entry, independent of which run recorded it.
	 *
	 * Quarantine lives inside each run's diff_summary, so a scheduled sync that
	 * keeps meeting the same rejected row records an identical entry every time
	 * it runs. Once a minute that is one new row in the Quarantine screen per
	 * minute, for one underlying problem. Everything a human would use to tell
	 * two entries apart goes into the key; the payload hash is what makes an
	 * entry whose values actually changed a genuinely new one.
	 *
	 * @param int   $connection_id Connection the entry belongs to.
	 * @param array $item          Quarantine entry.
	 * @return string
	 */
	/**
	 * Mark every other unresolved copy of one quarantine entry as settled.
	 *
	 * Only bookkeeping: no catalog write happens here. The apply, when the human
	 * chose one, was already performed against the run they acted on.
	 *
	 * @param int    $connection_id Connection to scan.
	 * @param string $group_key     Key from quarantine_key().
	 * @param string $action        apply|discard, recorded as the resolution.
	 * @param int    $skip_run_id   Run already updated by the caller.
	 * @return int Number of additional runs updated.
	 */
	private static function settle_quarantine_group( $connection_id, $group_key, $action, $skip_run_id ) {
		$updated_runs = 0;
		$now = self::now_mysql();
		$user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		foreach ( self::runs_with_quarantine( $connection_id ) as $run ) {
			if ( (int) $run['id'] === (int) $skip_run_id ) {
				continue;
			}
			$diff = self::decode_diff( $run['diff_summary'] );
			$items = isset( $diff['quarantine'] ) && is_array( $diff['quarantine'] ) ? $diff['quarantine'] : array();
			if ( empty( $items ) ) {
				continue;
			}
			$changed = false;
			foreach ( $items as $index => $entry ) {
				if ( ! is_array( $entry ) || ! empty( $entry['resolved'] ) ) {
					continue;
				}
				if ( self::quarantine_key( (int) $run['connection_id'], $entry ) !== $group_key ) {
					continue;
				}
				$items[ $index ]['resolved']    = true;
				$items[ $index ]['resolution']  = $action;
				$items[ $index ]['resolved_at'] = $now;
				$items[ $index ]['resolved_by'] = $user;
				$changed = true;
			}
			if ( $changed ) {
				$diff['quarantine'] = array_values( $items );
				self::update_run( (int) $run['id'], array( 'diff_summary' => self::json( $diff ) ) );
				$updated_runs++;
			}
		}
		return $updated_runs;
	}

	/**
	 * Runs that actually carry quarantine entries.
	 *
	 * The quarantine screen used to walk the last 500 runs. On a one-minute
	 * schedule that window is about eight hours, so an entry older than that
	 * became invisible to the human who had to answer it — while still blocking
	 * log retention, because a run holding an unanswered entry is never purged.
	 * A question nobody can see that stops cleanup forever is worse than a wider
	 * query.
	 *
	 * Rather than reading every run and discarding most of them, the filter is
	 * pushed into SQL: only rows whose diff_summary actually contains a
	 * non-empty quarantine array come back. On the live site that is a handful
	 * of rows out of nearly nine hundred. Resolved entries are included on
	 * purpose — the grouping needs them to know a group has been answered.
	 *
	 * @param int $connection_id Connection filter, 0 for all.
	 * @param int $limit         Maximum rows; the caller paginates if it grows.
	 * @return array
	 */
	private static function runs_with_quarantine( $connection_id = 0, $limit = 2000 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bcs_sync_runs';
		$limit = max( 1, min( 20000, absint( $limit ) ) );
		// esc_like() exists on the real $wpdb; guard anyway so a stripped-down
		// database object cannot turn a listing into a fatal error.
		$marker = '"quarantine":[{';
		$needle = '%' . ( method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $marker ) : addcslashes( $marker, '_%\\' ) ) . '%';

		if ( absint( $connection_id ) > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id = %d AND diff_summary LIKE %s ORDER BY id DESC LIMIT %d", absint( $connection_id ), $needle, $limit )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE diff_summary LIKE %s ORDER BY id DESC LIMIT %d", $needle, $limit )
			);
		}
		// Normalized the same way list_runs() does, so callers see one shape.
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::normalize_run( $row );
		}
		return $out;
	}

	public static function quarantine_key( $connection_id, array $item ) {
		$payload = isset( $item['payload'] ) && is_array( $item['payload'] ) ? $item['payload'] : array();
		ksort( $payload );
		return sha1(
			absint( $connection_id ) . '|' .
			strtolower( trim( (string) ( isset( $item['sku'] ) ? $item['sku'] : '' ) ) ) . '|' .
			strtolower( trim( (string) ( isset( $item['variation_sku'] ) ? $item['variation_sku'] : '' ) ) ) . '|' .
			(string) ( isset( $item['reason'] ) ? $item['reason'] : '' ) . '|' .
			self::json( $payload )
		);
	}

	public static function list_quarantine( $connection_id = 0, $limit = 200 ) {
		$limit = max( 1, min( 10000, absint( $limit ) ) );

		$groups   = array();
		$resolved = array();
		foreach ( self::runs_with_quarantine( $connection_id ) as $run ) {
			$diff = self::decode_diff( $run['diff_summary'] );
			foreach ( (array) ( isset( $diff['quarantine'] ) ? $diff['quarantine'] : array() ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$key = self::quarantine_key( (int) $run['connection_id'], $item );
				if ( ! empty( $item['resolved'] ) ) {
					// Resolving one occurrence settles the whole group: the
					// human answered the question the entry was asking.
					$resolved[ $key ] = true;
					continue;
				}
				if ( isset( $groups[ $key ] ) ) {
					$groups[ $key ]['occurrences']++;
					// Track the range explicitly rather than trusting the order
					// list_runs() happens to return.
					if ( (int) $run['id'] > (int) $groups[ $key ]['last_seen_run_id'] ) {
						$groups[ $key ]['last_seen_run_id'] = (int) $run['id'];
						$groups[ $key ]['run_id']           = (int) $run['id'];
						$groups[ $key ]['run_state']        = (string) $run['state'];
						$groups[ $key ]['diff_hash']        = (string) $run['diff_hash'];
					}
					if ( (int) $run['id'] < (int) $groups[ $key ]['first_seen_run_id'] ) {
						$groups[ $key ]['first_seen_run_id'] = (int) $run['id'];
					}
					continue;
				}
				// An action targets the latest run that still holds the entry.
				$item['run_id']            = (int) $run['id'];
				$item['connection_id']     = (int) $run['connection_id'];
				$item['run_state']         = (string) $run['state'];
				$item['diff_hash']         = (string) $run['diff_hash'];
				$item['quarantine_key']    = $key;
				$item['occurrences']       = 1;
				$item['last_seen_run_id']  = (int) $run['id'];
				$item['first_seen_run_id'] = (int) $run['id'];
				$groups[ $key ]            = $item;
			}
		}

		$items = array();
		foreach ( $groups as $key => $item ) {
			if ( isset( $resolved[ $key ] ) ) {
				continue;
			}
			$items[] = $item;
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * Count conflict and quarantine notices for the connection list ability.
	 *
	 * @return array
	 */
	public static function pending_counts() {
		$conflicts = 0;
		if ( function_exists( 'get_posts' ) ) {
			$posts = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'     => '_bcs_conflict_flag',
							'value'   => '',
							'compare' => '!=',
						),
					),
				)
			);
			$conflicts = count( (array) $posts );
		}
		return array(
			'conflicts_pending'  => $conflicts,
			'quarantine_pending' => count( self::list_quarantine( 0, 10000 ) ),
		);
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
	 * Resolve one quarantined item from the admin quarantine screen.
	 *
	 * @param int $run_id Run ID.
	 * @param string $sku Parent or variation SKU.
	 * @param string $action apply or discard.
	 * @param string $actor_via Audit actor context.
	 * @return array|WP_Error
	 */
	public static function resolve_quarantine( $run_id, $sku, $action, $actor_via = 'wp_admin' ) {
		$action = sanitize_key( $action );
		if ( ! in_array( $action, array( 'apply', 'discard' ), true ) ) {
			return new WP_Error( 'invalid_action', __( 'Quarantine action must be apply or discard.', 'bulk-catalog-studio' ) );
		}

		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'bcs_not_found', __( 'Sync run not found.', 'bulk-catalog-studio' ) );
		}
		$diff = self::decode_diff( $run['diff_summary'] );
		$items = isset( $diff['quarantine'] ) && is_array( $diff['quarantine'] ) ? $diff['quarantine'] : array();
		$wanted = strtolower( trim( (string) $sku ) );
		$found_index = -1;
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! empty( $item['resolved'] ) ) {
				continue;
			}
			$item_sku = strtolower( trim( (string) ( isset( $item['sku'] ) ? $item['sku'] : '' ) ) );
			$variation_sku = strtolower( trim( (string) ( isset( $item['variation_sku'] ) ? $item['variation_sku'] : '' ) ) );
			if ( $wanted === $item_sku || ( '' !== $variation_sku && $wanted === $variation_sku ) ) {
				$found_index = $index;
				break;
			}
		}
		if ( $found_index < 0 ) {
			return new WP_Error( 'quarantine_not_found', __( 'The unresolved quarantine item was not found for this run and SKU.', 'bulk-catalog-studio' ) );
		}

		$item = $items[ $found_index ];
		$product_id = 0;
		if ( 'apply' === $action ) {
			$applied = self::apply_quarantine_item( $run, $item, $actor_via );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			$product_id = isset( $applied['product_id'] ) ? (int) $applied['product_id'] : 0;
		}

		$group_key = self::quarantine_key( (int) $run['connection_id'], $item );

		$items[ $found_index ]['resolved']       = true;
		$items[ $found_index ]['resolution']     = $action;
		$items[ $found_index ]['resolved_at']    = self::now_mysql();
		$items[ $found_index ]['resolved_by']    = get_current_user_id();
		$diff['quarantine']                      = array_values( $items );
		$updated = self::update_run( $run_id, array( 'diff_summary' => self::json( $diff ) ) );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		/*
		 * The same entry is recorded again by every run that meets the same
		 * rejected row, so a scheduled sync can hold hundreds of identical
		 * copies. Resolving only this run would leave all of them open and the
		 * screen unchanged — the human would have to answer the same question
		 * once per minute of history. The write, if any, happened once above;
		 * the rest of the group is only marked settled.
		 */
		self::settle_quarantine_group( (int) $run['connection_id'], $group_key, $action, (int) $run_id );

		BCS_Audit_Logger::log_row(
			'bcs/sync-quarantine',
			'quarantine_' . $action,
			'ok',
			0,
			isset( $item['sku'] ) ? $item['sku'] : $sku,
			$product_id ? $product_id : ( isset( $item['before']['id'] ) ? absint( $item['before']['id'] ) : null ),
			'Quarantine item resolved manually.',
			self::quarantine_operation_key( $run_id, $item ),
			$run_id,
			0,
			'sync',
			$actor_via
		);

		return array(
			'run_id'      => (int) $run_id,
			'sku'         => isset( $item['sku'] ) ? (string) $item['sku'] : (string) $sku,
			'action'      => $action,
			'resolved'    => true,
			'product_id'  => $product_id,
		);
	}

	/**
	 * Apply one quarantine payload through the catalog writer.
	 *
	 * @param array  $run Run.
	 * @param array  $item Quarantine item.
	 * @param string $actor_via Audit actor.
	 * @return array|WP_Error
	 */
	private static function apply_quarantine_item( array $run, array $item, $actor_via ) {
		$connection = BCS_Sync_Connection::get( (int) $run['connection_id'] );
		if ( is_wp_error( $connection ) || ! is_array( $connection ) ) {
			return is_wp_error( $connection ) ? $connection : new WP_Error( 'bcs_not_found', __( 'Sync connection not found for quarantine item.', 'bulk-catalog-studio' ) );
		}
		$payload = isset( $item['payload'] ) && is_array( $item['payload'] ) ? $item['payload'] : array();
		$before  = isset( $item['before'] ) && is_array( $item['before'] ) ? $item['before'] : array();
		$sku     = isset( $item['sku'] ) ? (string) $item['sku'] : '';
		$key     = self::quarantine_operation_key( $run['id'], $item );
		$target  = isset( $before['id'] ) ? absint( $before['id'] ) : 0;
		$hash    = class_exists( 'BCS_Sync_Diff' ) ? BCS_Sync_Diff::payload_hash( $payload ) : sha1( self::json( $payload ) );

		if ( ! empty( $item['variation_sku'] ) ) {
			$parent_id = isset( $before['parent_id'] ) ? absint( $before['parent_id'] ) : 0;
			if ( ! $parent_id && class_exists( 'BCS_Duplicate_Checker' ) ) {
				$parent_id = (int) BCS_Duplicate_Checker::find_by_sku( $sku );
			}
			if ( ! $parent_id ) {
				return new WP_Error( 'variation_parent_not_found', __( 'The parent product for this quarantined variation was not found.', 'bulk-catalog-studio' ) );
			}
			$result = BCS_Catalog_Writer::update_variation(
				$parent_id,
				(string) $item['variation_sku'],
				$payload,
				array( 'sync_hash' => $hash, 'connection_id' => $connection['id'] )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'product_id' => isset( $result['variation_id'] ) ? (int) $result['variation_id'] : $parent_id );
		}

		if ( 'sync_action' === (string) ( isset( $item['reason'] ) ? $item['reason'] : '' ) || 'delete' === (string) ( isset( $payload['sync_action'] ) ? $payload['sync_action'] : '' ) ) {
			if ( ! $target && class_exists( 'BCS_Duplicate_Checker' ) ) {
				$target = (int) BCS_Duplicate_Checker::find_by_sku( $sku );
			}
			if ( ! $target ) {
				return new WP_Error( 'bcs_not_found', __( 'Product not found for quarantined delete.', 'bulk-catalog-studio' ) );
			}
			$result = BCS_Catalog_Writer::trash_product( $target );
			return is_wp_error( $result ) ? $result : array( 'product_id' => $target );
		}

		$operation = array(
			'op'                => $target ? 'update_product' : 'create_product',
			'product_type'      => ! empty( $payload['variations'] ) || ! empty( $payload['attributes'] ) ? 'variable' : 'simple',
			'row_key'           => $key,
			'target_product_id' => $target,
			'payload'           => $payload,
		);
		$result = BCS_Catalog_Writer::execute_operation( $operation, $run['id'], 0 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$product_id = isset( $result['product_id'] ) ? (int) $result['product_id'] : $target;
		$sync_state = BCS_Catalog_Writer::set_sync_state( $product_id, $hash, $connection['id'] );
		if ( is_wp_error( $sync_state ) ) {
			return $sync_state;
		}
		return array( 'product_id' => $product_id );
	}

	/**
	 * Resolve a product conflict without silently overwriting catalog data.
	 *
	 * @param int    $product_id Product/variation ID.
	 * @param string $resolution sheet_wins or keep_wp.
	 * @param string $actor_via Audit actor.
	 * @return array|WP_Error
	 */
	public static function resolve_conflict( $product_id, $resolution, $actor_via = 'wp_admin' ) {
		$resolution = sanitize_key( $resolution );
		if ( ! in_array( $resolution, array( 'sheet_wins', 'keep_wp' ), true ) ) {
			return new WP_Error( 'invalid_resolution', __( 'Conflict resolution must be sheet_wins or keep_wp.', 'bulk-catalog-studio' ) );
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'bcs_missing_woocommerce', __( 'WooCommerce is required to resolve a sync conflict.', 'bulk-catalog-studio' ) );
		}
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'bcs_not_found', __( 'Product not found for conflict resolution.', 'bulk-catalog-studio' ) );
		}

		$sku           = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku( 'edit' ) : '';
		$connection_id = method_exists( $product, 'get_meta' ) ? absint( $product->get_meta( '_bcs_connection_id', true, 'edit' ) ) : 0;
		$payload       = self::current_product_payload( $product );
		$hash          = BCS_Sync_Diff::payload_hash( $payload );

		if ( 'sheet_wins' === $resolution ) {
			$operation = self::find_conflict_operation( $product_id, $sku, $connection_id );
			if ( ! is_array( $operation ) || ! isset( $operation['payload'] ) || ! is_array( $operation['payload'] ) ) {
				return new WP_Error( 'conflict_source_missing', __( 'The source payload for this conflict is no longer available. Keep the WP version or run the connection again.', 'bulk-catalog-studio' ) );
			}
			$payload = $operation['payload'];
			$hash    = ! empty( $operation['sync_hash'] ) && preg_match( '/^[a-f0-9]{40}$/i', (string) $operation['sync_hash'] ) ? (string) $operation['sync_hash'] : BCS_Sync_Diff::payload_hash( $payload );

			if ( $product instanceof WC_Product_Variation ) {
				$parent_id = method_exists( $product, 'get_parent_id' ) ? absint( $product->get_parent_id() ) : 0;
				$variation_sku = ! empty( $operation['variation_sku'] ) ? (string) $operation['variation_sku'] : $sku;
				if ( ! $parent_id || '' === $variation_sku ) {
					return new WP_Error( 'conflict_target_invalid', __( 'The variation parent or SKU could not be resolved for Sheet wins.', 'bulk-catalog-studio' ) );
				}
				$result = BCS_Catalog_Writer::update_variation(
					$parent_id,
					$variation_sku,
					$payload,
					array( 'sync_hash' => $hash, 'connection_id' => $connection_id )
				);
			} else {
				$result = BCS_Catalog_Writer::execute_operation(
					array(
						'op'                => 'update_product',
						'target_product_id' => absint( $product_id ),
						'payload'           => $payload,
					),
					0,
					0
				);
				if ( ! is_wp_error( $result ) ) {
					$result = BCS_Catalog_Writer::set_sync_state( $product_id, $hash, $connection_id );
				}
			}
		} else {
			// Keep WP acknowledges the current catalog values and lets the next
			// source diff decide whether the source should be applied again.
			$result = BCS_Catalog_Writer::set_sync_state( $product_id, $hash, $connection_id );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		BCS_Audit_Logger::log_row( 'bcs/sync-conflict', 'resolve_' . $resolution, 'ok', 0, $sku, absint( $product_id ), 'Sync conflict resolved: ' . $resolution . '.', sha1( 'conflict|' . $product_id . '|' . $resolution . '|' . $hash ), 0, 0, 'sync', $actor_via );
		return array( 'product_id' => absint( $product_id ), 'resolution' => $resolution, 'resolved' => true );
	}

	/**
	 * Build a canonical enough snapshot of current WooCommerce scalar fields.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function current_product_payload( $product ) {
		$payload = array();
		$methods = array(
			'sku'            => 'get_sku',
			'name'           => 'get_name',
			'regular_price'  => 'get_regular_price',
			'sale_price'     => 'get_sale_price',
			'stock_quantity' => 'get_stock_quantity',
			'weight'         => 'get_weight',
		);
		foreach ( $methods as $key => $method ) {
			if ( method_exists( $product, $method ) ) {
				$payload[ $key ] = $product->{$method}( 'edit' );
			}
		}
		return $payload;
	}

	/**
	 * Find the latest persisted source operation for a flagged product.
	 *
	 * @param int    $product_id Product/variation ID.
	 * @param string $sku Product/variation SKU.
	 * @param int    $connection_id Optional connection filter.
	 * @return array|null
	 */
	private static function find_conflict_operation( $product_id, $sku, $connection_id = 0 ) {
		foreach ( self::list_runs( $connection_id, 500 ) as $run ) {
			$diff = self::decode_diff( $run['diff_summary'] );
			foreach ( (array) ( isset( $diff['operations'] ) ? $diff['operations'] : array() ) as $operation ) {
				if ( ! is_array( $operation ) || 'conflict' !== (string) ( isset( $operation['op'] ) ? $operation['op'] : '' ) ) {
					continue;
				}
				$before    = isset( $operation['before'] ) && is_array( $operation['before'] ) ? $operation['before'] : array();
				$before_id = isset( $before['id'] ) ? absint( $before['id'] ) : 0;
				$target_id = isset( $operation['target_product_id'] ) ? absint( $operation['target_product_id'] ) : 0;
				if ( absint( $product_id ) === $before_id || absint( $product_id ) === $target_id ) {
					return $operation;
				}
				$operation_sku = strtolower( trim( (string) ( isset( $operation['sku'] ) ? $operation['sku'] : '' ) ) );
				$wanted_sku    = strtolower( trim( (string) $sku ) );
				if ( '' !== $wanted_sku && $wanted_sku === $operation_sku && empty( $operation['variation_sku'] ) ) {
					return $operation;
				}
			}
		}
		return null;
	}

	/**
	 * Stable operation key for a quarantine entry.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $item Item.
	 * @return string
	 */
	private static function quarantine_operation_key( $run_id, array $item ) {
		return sha1( 'quarantine|' . absint( $run_id ) . '|' . (string) ( isset( $item['sku'] ) ? $item['sku'] : '' ) . '|' . (string) ( isset( $item['variation_sku'] ) ? $item['variation_sku'] : '' ) . '|' . (string) ( isset( $item['reason'] ) ? $item['reason'] : '' ) );
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
			return self::fail_run( $run_id, 'failed_guard', 'missing_diff', __( 'The applying run has no persisted diff to resume.', 'bulk-catalog-studio' ), $actor );
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
		/*
		 * An image that could not be sideloaded is a warning, never an abort:
		 * the rest of the row is already written and the run must not fail over
		 * a remote fetch. The writer hands the reason back as image_warning and
		 * it belongs in the audit trail, otherwise a rejected URL — wrong
		 * scheme, host outside the whitelist, oversized file — would fail
		 * silently and be impossible to diagnose.
		 */
		if ( is_array( $result ) && ! empty( $result['image_warning'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: image warning */
				__( 'Image warning: %s', 'bulk-catalog-studio' ),
				(string) $result['image_warning']
			);
		}
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

		// Writing the children makes WooCommerce re-sync the parent, which bumps
		// the parent's post_modified past the snapshot taken when the parent
		// itself was written. Re-snapshot it here, once every child is done, or
		// every variable product reports a conflict on the next run.
		BCS_Catalog_Writer::snapshot_post_modified( absint( $product_id ) );
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
		/*
		 * The audit check above is the resume guard proper: it is scoped to this
		 * run and proves the write already succeeded inside it. The sync-hash
		 * check below is a weaker secondary signal and must not overrule the
		 * diff.
		 *
		 * The diff decides by comparing fields against live WordPress state;
		 * the hash check decides by comparing the source row's hash with the
		 * hash stored at the last successful write. When a field fails to
		 * round-trip into WordPress the two disagree permanently: the diff
		 * reports field_changed on every run because WP still differs, while
		 * the hash check reports "already applied" because the source never
		 * changed. The operation is then proposed forever and executed never,
		 * the run never converges, and the thresholds trip every time. Stock
		 * did exactly this while manage_stock stayed off.
		 *
		 * So when the diff has explicitly said the fields differ, trust the
		 * diff. Crash resume is unaffected: an operation that already succeeded
		 * in this run is still skipped by the audit check above.
		 */
		$reason = isset( $operation['reason'] ) ? (string) $operation['reason'] : '';
		if ( 'field_changed' === $reason ) {
			return false;
		}
		/*
		 * A conflict is raised by drift on the WordPress side, not in the
		 * source, so a matching source hash says nothing about whether the flag
		 * has been written. Letting the shortcut fire here meant
		 * _bcs_conflict_flag was never set for a manually edited product: the
		 * diff reported the conflict on every run and the flag was written on
		 * none of them.
		 */
		if ( 'conflict' === (string) ( isset( $operation['op'] ) ? $operation['op'] : '' ) ) {
			return false;
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
				'diff_summary' => self::json(
					array(
						'summary'    => $diff['summary'],
						'quarantine' => $diff['quarantine'],
						'operations' => $diff['operations'],
						'seen_skus'  => $diff['seen_skus'],
					)
				),
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
	 * @param string $note  Error note.
	 * @param string $actor Audit actor.
	 * @return WP_Error
	 */
	private static function fail_run( $run_id, $state, $code, $note, $actor = 'sync' ) {
		$run = self::get_run( $run_id );
		if ( $run && isset( self::allowed_transitions()[ $run['state'] ] ) && in_array( $state, self::allowed_transitions()[ $run['state'] ], true ) ) {
			self::transition(
				$run_id,
				$run['state'],
				$state,
				array(
					'error_code'   => substr( sanitize_key( $code ), 0, 60 ),
					'error_note'   => sanitize_textarea_field( $note ),
					'finished_at'  => self::now_mysql(),
					'heartbeat_at' => self::now_mysql(),
				)
			);
		} else {
			self::update_run(
				$run_id,
				array(
					'error_code'  => substr( sanitize_key( $code ), 0, 60 ),
					'error_note'  => sanitize_textarea_field( $note ),
					'finished_at' => self::now_mysql(),
				)
			);
		}
		if ( in_array( $code, array( 'schema_drift', 'fetch_not_csv', 'fetch_http', 'fetch_zero_rows' ), true ) ) {
			$run = self::get_run( $run_id );
			if ( $run ) {
				BCS_Sync_Connection::update( $run['connection_id'], array( 'status' => 'error_state' ) );
			}
		}
		BCS_Audit_Logger::log_call( 'bcs/sync-run', 'sync_run', 'failed', $note, $run_id, 0, null, 'sync', $actor );
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
		$now   = self::now_mysql();
		$data  = array(
			'connection_id'     => absint( $connection_id ),
			'run_trigger'      => $trigger,
			'state'             => 'pending',
			'diff_hash'         => null,
			'diff_summary'      => null,
			'token_hash'        => null,
			'token_expires_at'  => null,
			'token_used_at'     => null,
			'approved_by'       => null,
			'approved_at'       => null,
			'fetched_rows'      => 0,
			'error_code'        => null,
			'error_note'        => null,
			'started_at'        => $now,
			'finished_at'       => null,
			'heartbeat_at'      => $now,
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
		$allowed = array( 'connection_id', 'run_trigger', 'state', 'diff_hash', 'diff_summary', 'token_hash', 'token_expires_at', 'token_used_at', 'approved_by', 'approved_at', 'fetched_rows', 'error_code', 'error_note', 'started_at', 'finished_at', 'heartbeat_at' );
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
		return array_merge(
			$diff,
			array(
				'run_id' => (int) $run_id,
				'state'  => $state,
				'status' => $state,
			)
		);
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
			'id'               => (int) ( isset( $row['id'] ) ? $row['id'] : 0 ),
			'connection_id'    => (int) ( isset( $row['connection_id'] ) ? $row['connection_id'] : 0 ),
			'trigger'          => (string) ( isset( $row['run_trigger'] ) ? $row['run_trigger'] : '' ),
			'state'            => (string) ( isset( $row['state'] ) ? $row['state'] : '' ),
			'diff_hash'        => isset( $row['diff_hash'] ) ? $row['diff_hash'] : null,
			'diff_summary'     => isset( $row['diff_summary'] ) ? $row['diff_summary'] : null,
			'fetched_rows'     => (int) ( isset( $row['fetched_rows'] ) ? $row['fetched_rows'] : 0 ),
			'error_code'       => isset( $row['error_code'] ) ? $row['error_code'] : null,
			'error_note'       => isset( $row['error_note'] ) ? $row['error_note'] : null,
			'started_at'       => isset( $row['started_at'] ) ? $row['started_at'] : null,
			'finished_at'      => isset( $row['finished_at'] ) ? $row['finished_at'] : null,
			'heartbeat_at'     => isset( $row['heartbeat_at'] ) ? $row['heartbeat_at'] : null,
			'token_hash'       => isset( $row['token_hash'] ) ? $row['token_hash'] : null,
			'token_expires_at' => isset( $row['token_expires_at'] ) ? $row['token_expires_at'] : null,
			'token_used_at'    => isset( $row['token_used_at'] ) ? $row['token_used_at'] : null,
			'approved_by'      => isset( $row['approved_by'] ) ? ( $row['approved_by'] ? (int) $row['approved_by'] : null ) : null,
			'approved_at'      => isset( $row['approved_at'] ) ? $row['approved_at'] : null,
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
		return array(
			'create'           => 0,
			'update'           => 0,
			'update_variation' => 0,
			'delete'           => 0,
			'flag_missing'     => 0,
			'conflict'         => 0,
			'quarantined'      => 0,
			'failed'           => 0,
			'total_operations' => 0,
		);
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
		return (string) wp_json_encode( $value );
	}
}
