<?php
/**
 * BCS Sync fetcher contract and factory.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface BCS_Sync_Fetcher
 */
interface BCS_Sync_Fetcher {

	/**
	 * Fetch and parse a configured source.
	 *
	 * @param array $config Fetcher configuration.
	 * @return array|WP_Error { rows, fetched_at, meta }
	 */
	public function fetch( array $config );

	/**
	 * Test a source without applying a diff or writing catalog state.
	 *
	 * @param array $config Fetcher configuration.
	 * @return array|WP_Error { rows, fetched_at, meta }
	 */
	public function test( array $config );
}

/**
 * Factory for the V1 fetchers.
 */
class BCS_Sync_Fetcher_Factory {

	/**
	 * Make a fetcher by enum value.
	 *
	 * @param string $name Fetcher enum.
	 * @return BCS_Sync_Fetcher|WP_Error
	 */
	public static function make( $name ) {
		$name = sanitize_key( $name );
		if ( 'csv_export' === $name ) {
			return new BCS_Sync_Fetcher_Csv_Export();
		}
		if ( 'sheets_api' === $name ) {
			return new BCS_Sync_Fetcher_Sheets_Api();
		}

		return new WP_Error( 'invalid_fetcher', __( 'Unknown sync fetcher.', 'bulk-catalog-studio' ) );
	}

	/**
	 * Alias for callers that prefer create().
	 *
	 * @param string $name Fetcher enum.
	 * @return BCS_Sync_Fetcher|WP_Error
	 */
	public static function create( $name ) {
		return self::make( $name );
	}

	/**
	 * Explicit factory alias used by sync integrations.
	 *
	 * @param string $name Fetcher enum.
	 * @return BCS_Sync_Fetcher|WP_Error
	 */
	public static function factory( $name ) {
		return self::make( $name );
	}

	/**
	 * Alias for dependency-injection callers.
	 *
	 * @param string $name Fetcher enum.
	 * @return BCS_Sync_Fetcher|WP_Error
	 */
	public static function get( $name ) {
		return self::make( $name );
	}

	/**
	 * Make a fetcher directly from a normalized connection.
	 *
	 * @param array $connection Connection data.
	 * @return BCS_Sync_Fetcher|WP_Error
	 */
	public static function for_connection( array $connection ) {
		return self::make( isset( $connection['fetcher'] ) ? $connection['fetcher'] : '' );
	}
}
