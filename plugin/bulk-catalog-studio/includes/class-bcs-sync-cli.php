<?php
/**
 * WP-CLI commands for the BCS Sync worker.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Sync_CLI
 */
class BCS_Sync_CLI {

	/**
	 * Register `wp bcs sync ...`.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! class_exists( 'WP_CLI' ) || ! method_exists( 'WP_CLI', 'add_command' ) ) {
			return;
		}
		WP_CLI::add_command( 'bcs sync', __CLASS__ );
	}

	/**
	 * Run one connection: wp bcs sync run --connection-id=<id>.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		$connection_id = isset( $assoc_args['connection-id'] ) ? absint( $assoc_args['connection-id'] ) : 0;
		if ( $connection_id < 1 ) {
			$this->error( __( 'The --connection-id argument is required.', 'bulk-catalog-studio' ) );
		}
		$result = BCS_Sync_Runner::run( $connection_id, 'cron' );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}
		$this->line( sprintf( __( 'Sync run #%1$d finished with state %2$s.', 'bulk-catalog-studio' ), (int) $result['run_id'], isset( $result['status'] ) ? $result['status'] : $result['state'] ) );
	}

	/**
	 * Run every active connection whose calculated due time is now.
	 *
	 * WP-CLI registers a class method under its own name, so without the
	 * subcommand annotation this would only answer to `wp bcs sync run_due`, while the
	 * spec, the runbook and the system cron job all call `wp bcs sync run-due`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bcs sync run-due
	 *
	 * @subcommand run-due
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function run_due( $args, $assoc_args ) {
		$connections = BCS_Sync_Connection::list_connections( 'active' );
		if ( is_wp_error( $connections ) ) {
			$this->error( $connections->get_error_message() );
		}
		$now = time();
		$ran = 0;
		$failed = 0;
		foreach ( (array) $connections as $connection ) {
			$due_at = BCS_Sync_Connection::next_run_at( $connection, $connection['last_ok_run_at'] );
			if ( $due_at && strtotime( $due_at ) > $now ) {
				continue;
			}
			$ran++;
			$result = BCS_Sync_Runner::run( (int) $connection['id'], 'cron' );
			if ( is_wp_error( $result ) ) {
				$failed++;
				$this->warning( sprintf( __( 'Connection #%1$d failed: %2$s', 'bulk-catalog-studio' ), (int) $connection['id'], $result->get_error_message() ) );
				continue;
			}
			$this->line( sprintf( __( 'Connection #%d: %s.', 'bulk-catalog-studio' ), (int) $connection['id'], isset( $result['status'] ) ? $result['status'] : 'done' ) );
		}
		if ( $failed > 0 ) {
			$this->error( sprintf( __( '%1$d of %2$d due connection(s) failed.', 'bulk-catalog-studio' ), $failed, $ran ) );
		}
		$this->success( sprintf( __( 'Processed %d due connection(s).', 'bulk-catalog-studio' ), $ran ) );
	}

	/**
	 * Delete log history older than the configured retention.
	 *
	 * The plugin registers no WP-Cron event (spec §1.2): scheduling is a system
	 * cron job, so retention is a command rather than a hooked event.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be deleted without deleting anything.
	 *
	 * [--batch-size=<n>]
	 * : Rows per table per invocation. Defaults to the purge_batch_size setting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bcs sync purge-logs --dry-run
	 *     wp bcs sync purge-logs
	 *
	 * @subcommand purge-logs
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function purge_logs( $args, $assoc_args ) {
		$options = array( 'dry_run' => ! empty( $assoc_args['dry-run'] ) );
		if ( isset( $assoc_args['batch-size'] ) ) {
			$options['batch_size'] = absint( $assoc_args['batch-size'] );
		}
		$report = BCS_Log_Purge::purge( $options );
		$this->success(
			sprintf(
				/* translators: 1: runs, 2: audit rows, 3: protected runs, 4: dry-run marker */
				__( 'Purge: %1$d sync run(s), %2$d audit row(s); %3$d run(s) protected.%4$s', 'bulk-catalog-studio' ),
				(int) $report['runs_deleted'],
				(int) $report['audit_deleted'],
				(int) $report['runs_protected'],
				! empty( $report['dry_run'] ) ? ' (dry run — nothing deleted)' : ''
			)
		);
	}

	/**
	 * Fetch+parse only: wp bcs sync test-connection --connection-id=<id>.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bcs sync test-connection --connection-id=1
	 *
	 * @subcommand test-connection
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function test_connection( $args, $assoc_args ) {
		$connection_id = isset( $assoc_args['connection-id'] ) ? absint( $assoc_args['connection-id'] ) : 0;
		if ( $connection_id < 1 ) {
			$this->error( __( 'The --connection-id argument is required.', 'bulk-catalog-studio' ) );
		}
		$connection = BCS_Sync_Connection::get( $connection_id );
		if ( is_wp_error( $connection ) || ! $connection ) {
			$this->error( is_wp_error( $connection ) ? $connection->get_error_message() : __( 'Sync connection not found.', 'bulk-catalog-studio' ) );
		}
		$fetcher = BCS_Sync_Fetcher_Factory::make( $connection['fetcher'] );
		if ( is_wp_error( $fetcher ) ) {
			$this->error( $fetcher->get_error_message() );
		}
		$result = $fetcher->test( $connection );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}
		$this->success( sprintf( __( 'Connection test passed: %d row(s) fetched.', 'bulk-catalog-studio' ), count( (array) $result['rows'] ) ) );
	}

	/**
	 * WP-CLI error wrapper, easy to stub in tests.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function error( $message ) {
		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'error' ) ) {
			WP_CLI::error( $message );
		}
		throw new Exception( (string) $message );
	}

	/**
	 * WP-CLI success wrapper.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function success( $message ) {
		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'success' ) ) {
			WP_CLI::success( $message );
			return;
		}
		$this->line( $message );
	}

	/**
	 * WP-CLI warning wrapper.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function warning( $message ) {
		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'warning' ) ) {
			WP_CLI::warning( $message );
			return;
		}
		$this->line( 'Warning: ' . $message );
	}

	/**
	 * WP-CLI line wrapper.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function line( $message ) {
		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'log' ) ) {
			WP_CLI::log( $message );
			return;
		}
		echo (string) $message . "\n";
	}
}
