<?php
/**
 * Google Sheets CSV export fetcher for BCS Sync.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Sync_Fetcher_Csv_Export
 */
class BCS_Sync_Fetcher_Csv_Export implements BCS_Sync_Fetcher {

	/**
	 * Fetch, validate, and parse a Google Sheets CSV export.
	 *
	 * @param array $config Connection config + mapping.
	 * @return array|WP_Error
	 */
	public function fetch( array $config ) {
		$sheet_config = isset( $config['sheet_config'] ) && is_array( $config['sheet_config'] ) ? $config['sheet_config'] : $config;
		$spreadsheet_id = $this->spreadsheet_id( $sheet_config );
		if ( '' === $spreadsheet_id ) {
			return new WP_Error( 'fetch_invalid_config', __( 'A Google Spreadsheet ID is required for the CSV export fetcher.', 'bulk-catalog-studio' ) );
		}

		$gid = isset( $sheet_config['gid'] ) ? (string) $sheet_config['gid'] : '0';
		if ( '' === $gid ) {
			$gid = '0';
		}
		$url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $spreadsheet_id ) . '/export?format=csv&gid=' . rawurlencode( $gid ) . '&_cb=' . time();

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'text/csv' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_http', $response->get_error_message() );
		}

		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : ( isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0 );
		if ( 200 !== $code ) {
			return new WP_Error(
				'fetch_http',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Google Sheets CSV export returned HTTP status %d.', 'bulk-catalog-studio' ),
					$code
				)
			);
		}

		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : ( isset( $response['body'] ) ? (string) $response['body'] : '' );
		$sniff = ltrim( $body, " \t\r\n\x0B\x0C\xEF\xBB\xBF" );
		if ( '' !== $sniff && '<' === substr( $sniff, 0, 1 ) ) {
			return new WP_Error( 'fetch_not_csv', __( 'The fetched response looks like HTML, not CSV.', 'bulk-catalog-studio' ) );
		}
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'fetch_zero_rows', __( 'The fetched CSV contains zero rows.', 'bulk-catalog-studio' ) );
		}

		$mapping = array();
		if ( isset( $config['column_mapping'] ) && is_array( $config['column_mapping'] ) ) {
			$mapping = $config['column_mapping'];
		} elseif ( isset( $config['mapping'] ) && is_array( $config['mapping'] ) ) {
			$mapping = $config['mapping'];
		} elseif ( isset( $sheet_config['column_mapping'] ) && is_array( $sheet_config['column_mapping'] ) ) {
			$mapping = $sheet_config['column_mapping'];
		} elseif ( isset( $sheet_config['mapping'] ) && is_array( $sheet_config['mapping'] ) ) {
			$mapping = $sheet_config['mapping'];
		}
		$delimiter = isset( $config['delimiter'] ) ? (string) $config['delimiter'] : ( isset( $sheet_config['delimiter'] ) ? (string) $sheet_config['delimiter'] : ',' );
		$header_row = ! isset( $config['header_row'] ) || (bool) $config['header_row'];
		$source_hash = sha1( $body );

		$parsed = BCS_CSV_Parser::parse( $body, $delimiter, $header_row, $mapping, $source_hash );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$headers = isset( $parsed['detected_columns'] ) ? (array) $parsed['detected_columns'] : array();
		$unmapped = isset( $parsed['unmapped_columns'] ) ? (array) $parsed['unmapped_columns'] : array();
		if ( $header_row && ! empty( $mapping ) && count( $headers ) !== count( $mapping ) ) {
			return new WP_Error(
				'schema_mismatch',
				sprintf(
					/* translators: 1: header count, 2: mapping count */
					__( 'CSV header has %1$d columns but the saved mapping has %2$d columns.', 'bulk-catalog-studio' ),
					count( $headers ),
					count( $mapping )
				)
			);
		}

		if ( $header_row && count( $headers ) > 0 && ( count( $unmapped ) / count( $headers ) ) > 0.30 ) {
			return new WP_Error( 'schema_drift', __( 'More than 30% of the CSV columns are unknown to the saved mapping.', 'bulk-catalog-studio' ) );
		}
		if ( empty( $parsed['rows'] ) ) {
			return new WP_Error( 'fetch_zero_rows', __( 'The fetched CSV contains a header but no data rows.', 'bulk-catalog-studio' ) );
		}

		return array(
			'rows'       => array_values( (array) $parsed['rows'] ),
			'fetched_at' => gmdate( 'c' ),
			'meta'       => array(
				'url'              => $url,
				'source_hash'      => $source_hash,
				'detected_columns' => array_values( $headers ),
				'unmapped_columns' => array_values( $unmapped ),
				'warnings'         => isset( $parsed['warnings'] ) ? (array) $parsed['warnings'] : array(),
				'errors'           => isset( $parsed['errors'] ) ? (array) $parsed['errors'] : array(),
			),
		);
	}

	/**
	 * Test is deliberately the same fetch+parse path and has no side effects.
	 *
	 * @param array $config Connection config.
	 * @return array|WP_Error
	 */
	public function test( array $config ) {
		return $this->fetch( $config );
	}

	/**
	 * Resolve an ID from connection config or a Sheets URL.
	 *
	 * @param array $config Sheet configuration.
	 * @return string
	 */
	private function spreadsheet_id( array $config ) {
		foreach ( array( 'spreadsheet_id', 'id', 'sheet_id' ) as $key ) {
			if ( ! empty( $config[ $key ] ) ) {
				return sanitize_text_field( (string) $config[ $key ] );
			}
		}
		if ( ! empty( $config['url'] ) && preg_match( '#/spreadsheets/d/([^/]+)#', (string) $config['url'], $matches ) ) {
			return sanitize_text_field( rawurldecode( $matches[1] ) );
		}

		return '';
	}
}

/* Backward-friendly acronym alias for integrations that use CSV casing. */
if ( ! class_exists( 'BCS_Sync_Fetcher_CSV_Export' ) ) {
	class_alias( 'BCS_Sync_Fetcher_Csv_Export', 'BCS_Sync_Fetcher_CSV_Export' );
}
if ( ! class_exists( 'BCS_Sync_Fetcher_CSV' ) ) {
	class_alias( 'BCS_Sync_Fetcher_Csv_Export', 'BCS_Sync_Fetcher_CSV' );
}
