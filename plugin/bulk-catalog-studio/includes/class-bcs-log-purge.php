<?php
/**
 * Retention for the BCS log tables.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Log_Purge
 *
 * A sync on a one-minute schedule writes a run row and a handful of audit rows
 * every minute, forever. Measured on the live site: 881 runs and 2200 audit rows
 * in about 22 hours, with wp_bcs_sync_runs already at 3.67 MB because each run
 * stores its whole diff as JSON. Without retention this is unbounded growth on a
 * shared web host.
 *
 * Deleting history is irreversible, so the selection is deliberately done in PHP
 * rather than in one large DELETE ... WHERE NOT IN (...) statement: every row is
 * checked against the protection rules individually, deletes happen one row at a
 * time in small batches so no long table lock is taken, and the rules are unit
 * testable. Anything the system still needs is never a candidate, regardless of
 * age.
 */
class BCS_Log_Purge {

	/**
	 * Run states that must never be purged: the run has not finished, so its row
	 * is live state rather than history.
	 */
	const ACTIVE_STATES = array( 'pending', 'fetching', 'diffing', 'awaiting_approval', 'approved', 'applying' );

	/**
	 * Collect every run id that must survive a purge, whatever its age.
	 *
	 * @param array $runs All run rows (id, connection_id, state, diff_summary).
	 * @return array Run id => reason it is protected.
	 */
	public static function protected_run_ids( array $runs ) {
		$protected = array();
		$latest_per_connection = array();

		foreach ( $runs as $run ) {
			$id = (int) ( isset( $run['id'] ) ? $run['id'] : 0 );
			if ( $id < 1 ) {
				continue;
			}
			$connection_id = (int) ( isset( $run['connection_id'] ) ? $run['connection_id'] : 0 );

			// (a) The latest run of every connection: rollback_run() refuses to
			// roll back anything else, so deleting it would silently remove the
			// only run a human could still undo.
			if ( ! isset( $latest_per_connection[ $connection_id ] ) || $id > $latest_per_connection[ $connection_id ] ) {
				$latest_per_connection[ $connection_id ] = $id;
			}

			// (b) A run still waiting for a human, or still in flight.
			$state = (string) ( isset( $run['state'] ) ? $run['state'] : '' );
			if ( in_array( $state, self::ACTIVE_STATES, true ) ) {
				$protected[ $id ] = 'active_state:' . $state;
				continue;
			}

			// (c) A run holding a quarantine entry nobody has answered yet.
			// Quarantine lives inside diff_summary, so deleting the run deletes
			// the question along with it.
			$diff = isset( $run['diff_summary'] ) ? $run['diff_summary'] : '';
			if ( is_string( $diff ) ) {
				$diff = json_decode( $diff, true );
			}
			if ( is_array( $diff ) && ! empty( $diff['quarantine'] ) && is_array( $diff['quarantine'] ) ) {
				foreach ( $diff['quarantine'] as $entry ) {
					if ( is_array( $entry ) && empty( $entry['resolved'] ) ) {
						$protected[ $id ] = 'unresolved_quarantine';
						break;
					}
				}
			}
		}

		foreach ( $latest_per_connection as $latest_id ) {
			if ( ! isset( $protected[ $latest_id ] ) ) {
				$protected[ $latest_id ] = 'latest_run';
			}
		}

		return $protected;
	}

	/**
	 * Delete history older than the configured retention.
	 *
	 * @param array $args Optional overrides: dry_run (bool), batch_size (int).
	 * @return array Report: runs_deleted, audit_deleted, runs_protected, dry_run.
	 */
	public static function purge( array $args = array() ) {
		global $wpdb;

		$dry_run    = ! empty( $args['dry_run'] );
		$batch_size = isset( $args['batch_size'] ) ? absint( $args['batch_size'] ) : absint( BCS_Settings::get( 'purge_batch_size', 200 ) );
		$batch_size = max( 1, min( 1000, $batch_size ) );

		$runs_days  = absint( BCS_Settings::get( 'sync_runs_retention_days', 90 ) );
		$audit_days = absint( BCS_Settings::get( 'audit_retention_days', 90 ) );

		$runs_table  = $wpdb->prefix . 'bcs_sync_runs';
		$audit_table = $wpdb->prefix . 'bcs_audit';

		$report = array(
			'runs_deleted'    => 0,
			'audit_deleted'   => 0,
			'runs_protected'  => 0,
			'dry_run'         => $dry_run,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- nama tabel dari prefix.
		$all_runs = $wpdb->get_results( "SELECT id, connection_id, state, started_at, diff_summary FROM {$runs_table}", ARRAY_A );
		$all_runs = is_array( $all_runs ) ? $all_runs : array();
		$protected = self::protected_run_ids( $all_runs );
		$report['runs_protected'] = count( $protected );

		$deleted_run_ids = array();
		if ( $runs_days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $runs_days * DAY_IN_SECONDS ) );
			$batch  = 0;
			foreach ( $all_runs as $run ) {
				if ( $batch >= $batch_size ) {
					break;
				}
				$id = (int) $run['id'];
				if ( isset( $protected[ $id ] ) ) {
					continue;
				}
				$started = (string) ( isset( $run['started_at'] ) ? $run['started_at'] : '' );
				if ( '' === $started || $started >= $cutoff ) {
					continue;
				}
				if ( ! $dry_run ) {
					$wpdb->delete( $runs_table, array( 'id' => $id ), array( '%d' ) );
				}
				$deleted_run_ids[ $id ] = true;
				$report['runs_deleted']++;
				$batch++;
			}
		}

		if ( $audit_days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $audit_days * DAY_IN_SECONDS ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- nama tabel dari prefix.
			$audit_rows = $wpdb->get_results( "SELECT id, job_id, source, created_at FROM {$audit_table}", ARRAY_A );
			$audit_rows = is_array( $audit_rows ) ? $audit_rows : array();
			$batch = 0;
			foreach ( $audit_rows as $row ) {
				if ( $batch >= $batch_size ) {
					break;
				}
				$created = (string) ( isset( $row['created_at'] ) ? $row['created_at'] : '' );
				if ( '' === $created || $created >= $cutoff ) {
					continue;
				}
				// (d) Audit belonging to a run that is being kept stays with it:
				// a protected run without its trail is a run nobody can explain.
				if ( 'sync' === (string) ( isset( $row['source'] ) ? $row['source'] : '' ) ) {
					$job_id = (int) ( isset( $row['job_id'] ) ? $row['job_id'] : 0 );
					if ( isset( $protected[ $job_id ] ) ) {
						continue;
					}
				}
				if ( ! $dry_run ) {
					$wpdb->delete( $audit_table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
				}
				$report['audit_deleted']++;
				$batch++;
			}
		}

		if ( ! $dry_run && ( $report['runs_deleted'] > 0 || $report['audit_deleted'] > 0 ) && class_exists( 'BCS_Audit_Logger' ) ) {
			BCS_Audit_Logger::log_row(
				'bcs/log-purge',
				'purge_logs',
				'ok',
				0,
				null,
				null,
				sprintf(
					/* translators: 1: runs deleted, 2: audit rows deleted, 3: runs protected */
					__( 'Log purge removed %1$d sync run(s) and %2$d audit row(s); %3$d run(s) protected.', 'bulk-catalog-studio' ),
					$report['runs_deleted'],
					$report['audit_deleted'],
					$report['runs_protected']
				),
				sha1( 'purge|' . gmdate( 'Y-m-d H:i:s' ) ),
				0,
				0,
				'sync',
				'cron'
			);
		}

		return $report;
	}
}
