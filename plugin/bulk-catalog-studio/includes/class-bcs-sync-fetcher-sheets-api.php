<?php
/**
 * Google Sheets API service-account fetcher for BCS Sync.
 *
 * The fetcher deliberately uses the native WordPress HTTP API and OpenSSL so
 * the plugin does not need a Google client-library dependency. Source rows are
 * converted to the same CSV parser contract used by the CSV export fetcher.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Sync_Fetcher_Sheets_Api
 */
class BCS_Sync_Fetcher_Sheets_Api implements BCS_Sync_Fetcher {

	/**
	 * Google OAuth service-account token endpoint.
	 *
	 * @var string
	 */
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Read-only Sheets scope.
	 *
	 * @var string
	 */
	const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

	/**
	 * Default A1 range when a connection omits one explicitly.
	 *
	 * @var string
	 */
	const DEFAULT_RANGE = 'A1:Z10000';

	/**
	 * Cached token lifetime. Google tokens live for about one hour; leave a
	 * ten-minute safety margin before requesting a new token.
	 *
	 * @var int
	 */
	const TOKEN_TTL = 3000;

	/**
	 * Fetch and parse values from Google Sheets API v4.
	 *
	 * @param array $config Connection config + mapping.
	 * @return array|WP_Error
	 */
	public function fetch( array $config ) {
		$sheet_config = isset( $config['sheet_config'] ) && is_array( $config['sheet_config'] ) ? $config['sheet_config'] : $config;
		$spreadsheet_id = $this->spreadsheet_id( $sheet_config );
		if ( '' === $spreadsheet_id ) {
			return new WP_Error( 'fetch_invalid_config', __( 'A Google Spreadsheet ID is required for the Sheets API fetcher.', 'bulk-catalog-studio' ) );
		}

		$range = isset( $sheet_config['range'] ) ? trim( (string) $sheet_config['range'] ) : '';
		if ( '' === $range ) {
			$range = self::DEFAULT_RANGE;
		}

		$credentials = $this->credentials( $config );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$access_token = $this->access_token( $credentials['client_email'], $credentials['private_key'] );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id ) . '/values/' . rawurlencode( $range ) . '?valueRenderOption=FORMATTED_VALUE';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_http_error', $response->get_error_message() );
		}

		$http_code = $this->response_code( $response );
		if ( 401 === $http_code || 403 === $http_code ) {
			return new WP_Error(
				'fetch_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Google Sheets API returned HTTP %1$d. The sheet may not be shared with the service account.', 'bulk-catalog-studio' ),
					$http_code
				)
			);
		}
		if ( 429 === $http_code ) {
			return new WP_Error( 'fetch_rate_limited', __( 'Google Sheets API rate limit reached (HTTP 429). Retry with backoff.', 'bulk-catalog-studio' ) );
		}
		if ( $http_code < 200 || $http_code >= 300 ) {
			return new WP_Error(
				'fetch_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Google Sheets API returned HTTP status %d.', 'bulk-catalog-studio' ),
					$http_code
				)
			);
		}

		$body = $this->response_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'fetch_api_error', __( 'The Google Sheets API response was not valid JSON.', 'bulk-catalog-studio' ) );
		}
		if ( array_key_exists( 'error', $decoded ) ) {
			return new WP_Error( 'fetch_api_error', $this->api_error_message( $decoded['error'] ) );
		}
		if ( ! array_key_exists( 'values', $decoded ) || ! is_array( $decoded['values'] ) ) {
			return new WP_Error( 'fetch_api_error', __( 'The Google Sheets API response did not contain a values array.', 'bulk-catalog-studio' ) );
		}
		if ( empty( $decoded['values'] ) ) {
			return new WP_Error( 'fetch_zero_rows', __( 'The Google Sheets API returned zero rows.', 'bulk-catalog-studio' ) );
		}

		$csv = $this->values_to_csv( $decoded['values'] );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}
		if ( '' === trim( $csv ) ) {
			return new WP_Error( 'fetch_zero_rows', __( 'The Google Sheets API returned zero rows.', 'bulk-catalog-studio' ) );
		}

		$mapping = $this->mapping( $config, $sheet_config );
		$source_hash = sha1( $csv );
		$parsed = BCS_CSV_Parser::parse( $csv, ',', true, $mapping, $source_hash );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$headers = isset( $parsed['detected_columns'] ) ? (array) $parsed['detected_columns'] : array();
		$unmapped = isset( $parsed['unmapped_columns'] ) ? (array) $parsed['unmapped_columns'] : array();
		if ( ! empty( $mapping ) && count( $headers ) !== count( $mapping ) ) {
			return new WP_Error(
				'schema_mismatch',
				sprintf(
					/* translators: 1: header count, 2: mapping count */
					__( 'Sheets API header has %1$d columns but the saved mapping has %2$d columns.', 'bulk-catalog-studio' ),
					count( $headers ),
					count( $mapping )
				)
			);
		}
		if ( count( $headers ) > 0 && ( count( $unmapped ) / count( $headers ) ) > 0.30 ) {
			return new WP_Error( 'schema_drift', __( 'More than 30% of the Sheets API columns are unknown to the saved mapping.', 'bulk-catalog-studio' ) );
		}
		if ( empty( $parsed['rows'] ) ) {
			return new WP_Error( 'fetch_zero_rows', __( 'The Google Sheets API returned a header but no data rows.', 'bulk-catalog-studio' ) );
		}

		return array(
			'rows'       => array_values( (array) $parsed['rows'] ),
			'fetched_at' => gmdate( 'c' ),
			'meta'       => array(
				'url'              => $url,
				'spreadsheet_id'   => $spreadsheet_id,
				'range'            => $range,
				'source_hash'      => $source_hash,
				'detected_columns' => array_values( $headers ),
				'unmapped_columns' => array_values( $unmapped ),
				'warnings'         => isset( $parsed['warnings'] ) ? (array) $parsed['warnings'] : array(),
				'errors'           => isset( $parsed['errors'] ) ? (array) $parsed['errors'] : array(),
			),
		);
	}

	/**
	 * Test is the same fetch+parse path and has no catalog side effects.
	 *
	 * @param array $config Connection config.
	 * @return array|WP_Error
	 */
	public function test( array $config ) {
		return $this->fetch( $config );
	}

	/**
	 * Resolve credentials, accepting the normalized plaintext from
	 * BCS_Sync_Connection::get() and an encrypted value for isolated callers.
	 *
	 * @param array $config Connection config.
	 * @return array|WP_Error
	 */
	private function credentials( array $config ) {
		$raw = array_key_exists( 'credentials', $config ) ? $config['credentials'] : null;
		$decoded = null;

		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( is_scalar( $raw ) && '' !== trim( (string) $raw ) ) {
			$raw_string = (string) $raw;
			$decoded = json_decode( $raw_string, true );
			if ( ! is_array( $decoded ) && class_exists( 'BCS_Sync_Connection' ) && method_exists( 'BCS_Sync_Connection', 'decrypt' ) ) {
				$decrypted = BCS_Sync_Connection::decrypt( $raw_string );
				if ( is_wp_error( $decrypted ) ) {
					if ( 'encryption_unavailable' === $decrypted->get_error_code() ) {
						return $decrypted;
					}
					return new WP_Error( 'credentials_invalid', __( 'The stored service account credentials could not be decrypted.', 'bulk-catalog-studio' ) );
				}
				$decoded = json_decode( (string) $decrypted, true );
			}
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'credentials_invalid', __( 'The service account credentials must be valid JSON.', 'bulk-catalog-studio' ) );
		}
		$client_email = isset( $decoded['client_email'] ) ? trim( (string) $decoded['client_email'] ) : '';
		$private_key  = isset( $decoded['private_key'] ) ? (string) $decoded['private_key'] : '';
		if ( '' === $client_email || '' === $private_key ) {
			return new WP_Error( 'credentials_invalid', __( 'The service account JSON must contain client_email and private_key.', 'bulk-catalog-studio' ) );
		}

		return array(
			'client_email' => $client_email,
			'private_key'  => str_replace( '\\n', "\n", $private_key ),
		);
	}

	/**
	 * Get a cached token or exchange a signed JWT for a new access token.
	 *
	 * @param string $client_email Service account email.
	 * @param string $private_key  Service account private key.
	 * @return string|WP_Error
	 */
	private function access_token( $client_email, $private_key ) {
		$cache_key = 'bcs_sync_gtoken_' . sha1( (string) $client_email );
		$cached = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$jwt = $this->build_jwt( $client_email, $private_key );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$body = http_build_query(
			array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_http_error', $response->get_error_message() );
		}

		$http_code = $this->response_code( $response );
		$response_body = $this->response_body( $response );
		if ( $http_code < 200 || $http_code >= 300 ) {
			$decoded_error = json_decode( $response_body, true );
			$message = is_array( $decoded_error ) && isset( $decoded_error['error_description'] ) ? (string) $decoded_error['error_description'] : __( 'Google OAuth token request failed.', 'bulk-catalog-studio' );
			return new WP_Error(
				'fetch_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: token error */
					__( 'Google OAuth token request returned HTTP %1$d: %2$s', 'bulk-catalog-studio' ),
					$http_code,
					$message
				)
			);
		}

		$decoded = json_decode( $response_body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
			return new WP_Error( 'fetch_api_error', __( 'Google OAuth token response did not contain an access token.', 'bulk-catalog-studio' ) );
		}

		$token = (string) $decoded['access_token'];
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, $token, self::TOKEN_TTL );
		}

		return $token;
	}

	/**
	 * Build and sign the service-account JWT assertion.
	 *
	 * @param string $client_email Service account email.
	 * @param string $private_key  Service account private key.
	 * @return string|WP_Error
	 */
	private function build_jwt( $client_email, $private_key ) {
		if ( ! function_exists( 'openssl_sign' ) || ! defined( 'OPENSSL_ALGO_SHA256' ) ) {
			return new WP_Error( 'encryption_unavailable', __( 'OpenSSL is required to sign the Google service account JWT.', 'bulk-catalog-studio' ) );
		}

		$now = time();
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		$claims = array(
			'iss'   => (string) $client_email,
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'iat'   => $now - 60,
			'exp'   => $now + 3600,
		);
		$encoded_header = $this->base64url_encode( (string) wp_json_encode( $header ) );
		$encoded_claims = $this->base64url_encode( (string) wp_json_encode( $claims ) );
		$signing_input = $encoded_header . '.' . $encoded_claims;

		$key = $private_key;
		if ( function_exists( 'openssl_pkey_get_private' ) ) {
			$key_resource = @openssl_pkey_get_private( $private_key ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid user-supplied key must become a controlled WP_Error.
			if ( false === $key_resource ) {
				return new WP_Error( 'credentials_invalid', __( 'The service account private key is invalid.', 'bulk-catalog-studio' ) );
			}
			$key = $key_resource;
		}

		$signature = '';
		$signed = @openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- convert OpenSSL key errors to a controlled WP_Error.
		if ( ! $signed || '' === $signature ) {
			return new WP_Error( 'credentials_invalid', __( 'The service account private key could not be used for RS256 signing.', 'bulk-catalog-studio' ) );
		}

		return $signing_input . '.' . $this->base64url_encode( $signature );
	}

	/**
	 * Convert a Google values grid to CSV while retaining empty cells.
	 *
	 * @param array $values Google Sheets values grid.
	 * @return string|WP_Error
	 */
	private function values_to_csv( array $values ) {
		$handle = fopen( 'php://temp', 'r+' );
		if ( ! $handle ) {
			return new WP_Error( 'fetch_api_error', __( 'The Sheets API response could not be buffered as CSV.', 'bulk-catalog-studio' ) );
		}

		foreach ( $values as $row ) {
			if ( ! is_array( $row ) ) {
				fclose( $handle );
				return new WP_Error( 'fetch_api_error', __( 'The Sheets API values response contained an invalid row.', 'bulk-catalog-studio' ) );
			}
			$cells = array();
			foreach ( array_values( $row ) as $cell ) {
				if ( is_array( $cell ) || is_object( $cell ) ) {
					$cells[] = '';
				} elseif ( null === $cell ) {
					$cells[] = '';
				} else {
					$cells[] = (string) $cell;
				}
			}
			if ( false === fputcsv( $handle, $cells, ',', '"', '\\' ) ) {
				fclose( $handle );
				return new WP_Error( 'fetch_api_error', __( 'The Sheets API values response could not be converted to CSV.', 'bulk-catalog-studio' ) );
			}
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return false === $csv ? '' : (string) $csv;
	}

	/**
	 * Read a mapping from the normalized connection shape.
	 *
	 * @param array $config       Full connection config.
	 * @param array $sheet_config Sheet config.
	 * @return array
	 */
	private function mapping( array $config, array $sheet_config ) {
		if ( isset( $config['column_mapping'] ) && is_array( $config['column_mapping'] ) ) {
			return $config['column_mapping'];
		}
		if ( isset( $config['mapping'] ) && is_array( $config['mapping'] ) ) {
			return $config['mapping'];
		}
		if ( isset( $sheet_config['column_mapping'] ) && is_array( $sheet_config['column_mapping'] ) ) {
			return $sheet_config['column_mapping'];
		}
		if ( isset( $sheet_config['mapping'] ) && is_array( $sheet_config['mapping'] ) ) {
			return $sheet_config['mapping'];
		}

		return array();
	}

	/**
	 * Resolve a spreadsheet ID from connection config or a Sheets URL.
	 *
	 * @param array $config Sheet config.
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

	/**
	 * Encode bytes for a JWT segment.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Extract an HTTP response code with a test-harness fallback.
	 *
	 * @param mixed $response WordPress HTTP response.
	 * @return int
	 */
	private function response_code( $response ) {
		if ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
			return (int) wp_remote_retrieve_response_code( $response );
		}
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}

	/**
	 * Extract an HTTP response body with a test-harness fallback.
	 *
	 * @param mixed $response WordPress HTTP response.
	 * @return string
	 */
	private function response_body( $response ) {
		if ( function_exists( 'wp_remote_retrieve_body' ) ) {
			return (string) wp_remote_retrieve_body( $response );
		}
		return isset( $response['body'] ) ? (string) $response['body'] : '';
	}

	/**
	 * Format a Google API error payload without exposing credentials.
	 *
	 * @param mixed $error Error payload.
	 * @return string
	 */
	private function api_error_message( $error ) {
		if ( is_array( $error ) ) {
			if ( isset( $error['message'] ) && '' !== (string) $error['message'] ) {
				return (string) $error['message'];
			}
			if ( isset( $error['status'] ) && '' !== (string) $error['status'] ) {
				return (string) $error['status'];
			}
		}
		if ( is_scalar( $error ) && '' !== (string) $error ) {
			return (string) $error;
		}

		return __( 'The Google Sheets API returned an error response.', 'bulk-catalog-studio' );
	}
}

/* Backward-friendly acronym alias for integrations that use API casing. */
if ( ! class_exists( 'BCS_Sync_Fetcher_Sheets_API' ) ) {
	class_alias( 'BCS_Sync_Fetcher_Sheets_Api', 'BCS_Sync_Fetcher_Sheets_API' );
}
