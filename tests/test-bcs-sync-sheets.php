<?php
/**
 * BCS Sheets API fetcher harness (no WordPress bootstrap required).
 *
 * Covers the native JWT exchange, token transient cache, values-to-CSV
 * conversion, parser guards, and encrypted service-account credentials.
 */

error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'AUTH_KEY', 'sheets-api-test-auth-key' );
define( 'DB_PASSWORD', 'sheets-api-test-db-password' );

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $value ) ) : ''; }
function sanitize_textarea_field( $value ) { return is_scalar( $value ) ? trim( (string) $value ) : ''; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_option( $name, $default = false ) { return $default; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }

$GLOBALS['bcs_sheet_transients'] = array();
$GLOBALS['bcs_sheet_post_calls'] = 0;
$GLOBALS['bcs_sheet_get_calls']  = 0;
$GLOBALS['bcs_sheet_last_post']  = array();
$GLOBALS['bcs_sheet_last_get']   = array();
$GLOBALS['bcs_sheet_get_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode(
		array(
			'values' => array(
				array( 'name', 'description', 'sku', 'regular_price' ),
				array( 'Item', '', 'SKU-1', '10' ),
				array( 'Second', '', 'SKU-2', '20' ),
			),
		)
	),
);

function get_transient( $key ) {
	if ( ! isset( $GLOBALS['bcs_sheet_transients'][ $key ] ) ) {
		return false;
	}
	$entry = $GLOBALS['bcs_sheet_transients'][ $key ];
	if ( isset( $entry['expires'] ) && $entry['expires'] > 0 && $entry['expires'] <= time() ) {
		unset( $GLOBALS['bcs_sheet_transients'][ $key ] );
		return false;
	}
	return $entry['value'];
}

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['bcs_sheet_transients'][ $key ] = array(
		'value'   => $value,
		'expires' => $expiration > 0 ? time() + (int) $expiration : 0,
	);
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['bcs_sheet_transients'][ $key ] );
	return true;
}

function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['bcs_sheet_post_calls']++;
	$GLOBALS['bcs_sheet_last_post'] = array( 'url' => $url, 'args' => $args );
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'access_token' => 'stub-access-token', 'expires_in' => 3600 ) ),
	);
}

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['bcs_sheet_get_calls']++;
	$GLOBALS['bcs_sheet_last_get'] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['bcs_sheet_get_response'];
}

function wp_remote_retrieve_response_code( $response ) { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return (string) $response['body']; }

$base = dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/includes/';
require $base . 'class-bcs-settings.php';
require $base . 'class-bcs-csv-parser.php';
require $base . 'class-bcs-sync-connection.php';
require $base . 'class-bcs-sync-fetcher.php';
require $base . 'class-bcs-sync-fetcher-csv.php';
require $base . 'class-bcs-sync-fetcher-sheets-api.php';

$fail = 0;
function expect_sheets( $label, $condition ) {
	global $fail;
	echo ( $condition ? 'PASS' : 'FAIL' ) . " - $label\n";
	if ( ! $condition ) {
		$fail++;
	}
}

$key_resource = openssl_pkey_new(
	array(
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	)
);
$private_key = '';
openssl_pkey_export( $key_resource, $private_key );
$key_details = openssl_pkey_get_details( $key_resource );
$public_key  = $key_details['key'];
$credentials = array(
	'type'         => 'service_account',
	'client_email' => 'sync-test@example.iam.gserviceaccount.com',
	'private_key'  => $private_key,
);
$credentials_json = json_encode( $credentials );
$mapping = array(
	'name'          => 'name',
	'description'   => 'description',
	'sku'           => 'sku',
	'regular_price' => 'regular_price',
);
$config = array(
	'fetcher'        => 'sheets_api',
	'sheet_config'   => array(
		'spreadsheet_id' => 'sheet-test-id',
		'range'          => 'Products!A1:D100',
	),
	'credentials'    => $credentials_json,
	'column_mapping' => $mapping,
);

$factory_fetcher = BCS_Sync_Fetcher_Factory::factory( 'sheets_api' );
expect_sheets( 'factory returns the Sheets API fetcher', $factory_fetcher instanceof BCS_Sync_Fetcher_Sheets_Api );

$fetcher = new BCS_Sync_Fetcher_Sheets_Api();
$first = $fetcher->fetch( $config );
expect_sheets( 'JWT exchange and values fetch succeed', ! is_wp_error( $first ) && 2 === count( $first['rows'] ) );
expect_sheets( 'empty middle CSV cell keeps the following SKU column aligned', ! is_wp_error( $first ) && 'SKU-2' === $first['rows'][1]['sku'] && '20' === $first['rows'][1]['regular_price'] );
expect_sheets( 'API range and formatted-value option are sent', false !== strpos( $GLOBALS['bcs_sheet_last_get']['url'], 'Products%21A1%3AD100' ) && false !== strpos( $GLOBALS['bcs_sheet_last_get']['url'], 'valueRenderOption=FORMATTED_VALUE' ) );

$post_body = isset( $GLOBALS['bcs_sheet_last_post']['args']['body'] ) ? $GLOBALS['bcs_sheet_last_post']['args']['body'] : '';
parse_str( (string) $post_body, $post_fields );
$jwt = isset( $post_fields['assertion'] ) ? $post_fields['assertion'] : '';
$jwt_parts = explode( '.', $jwt );
$decode_segment = function ( $segment ) {
	$segment .= str_repeat( '=', ( 4 - strlen( $segment ) % 4 ) % 4 );
	return json_decode( base64_decode( strtr( $segment, '-_', '+/' ) ), true );
};
$jwt_header = count( $jwt_parts ) === 3 ? $decode_segment( $jwt_parts[0] ) : array();
$jwt_claims = count( $jwt_parts ) === 3 ? $decode_segment( $jwt_parts[1] ) : array();
$signature  = count( $jwt_parts ) === 3 ? base64_decode( strtr( $jwt_parts[2] . str_repeat( '=', ( 4 - strlen( $jwt_parts[2] ) % 4 ) % 4 ), '-_', '+/' ) ) : false;
$verified = count( $jwt_parts ) === 3 && 1 === openssl_verify( $jwt_parts[0] . '.' . $jwt_parts[1], $signature, $public_key, OPENSSL_ALGO_SHA256 );
expect_sheets( 'JWT header contains RS256 and JWT type', isset( $jwt_header['alg'], $jwt_header['typ'] ) && 'RS256' === $jwt_header['alg'] && 'JWT' === $jwt_header['typ'] );
expect_sheets( 'JWT claims contain service-account scope and token audience', isset( $jwt_claims['iss'], $jwt_claims['scope'], $jwt_claims['aud'] ) && $credentials['client_email'] === $jwt_claims['iss'] && BCS_Sync_Fetcher_Sheets_Api::SCOPE === $jwt_claims['scope'] && BCS_Sync_Fetcher_Sheets_Api::TOKEN_URL === $jwt_claims['aud'] );
expect_sheets( 'JWT claims use the requested iat/exp window', isset( $jwt_claims['iat'], $jwt_claims['exp'] ) && ( time() - 62 ) <= $jwt_claims['iat'] && $jwt_claims['iat'] <= time() - 58 && ( time() + 3598 ) <= $jwt_claims['exp'] && $jwt_claims['exp'] <= time() + 3602 );
expect_sheets( 'JWT signature verifies as RS256', $verified );
expect_sheets( 'token request uses form-urlencoded grant and assertion', 'urn:ietf:params:oauth:grant-type:jwt-bearer' === $post_fields['grant_type'] && '' !== $post_fields['assertion'] );

$first_post_calls = $GLOBALS['bcs_sheet_post_calls'];
$cached = $fetcher->fetch( $config );
expect_sheets( 'cached token is reused without a second POST', ! is_wp_error( $cached ) && $first_post_calls === $GLOBALS['bcs_sheet_post_calls'] );
$cache_key = 'bcs_sync_gtoken_' . sha1( $credentials['client_email'] );
$GLOBALS['bcs_sheet_transients'][ $cache_key ]['expires'] = time() - 1;
$expired = $fetcher->fetch( $config );
expect_sheets( 'expired token cache triggers a new POST', ! is_wp_error( $expired ) && $GLOBALS['bcs_sheet_post_calls'] === $first_post_calls + 1 );

$GLOBALS['bcs_sheet_get_response'] = array( 'response' => array( 'code' => 403 ), 'body' => '{}' );
$forbidden = $fetcher->fetch( $config );
expect_sheets( '403 is a fetch_http_error mentioning service-account sharing', is_wp_error( $forbidden ) && 'fetch_http_error' === $forbidden->get_error_code() && false !== strpos( strtolower( $forbidden->get_error_message() ), 'sheet may not be shared with the service account' ) );

$GLOBALS['bcs_sheet_get_response'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'error' => array( 'message' => 'Spreadsheet was not found.' ) ) ) );
$api_error = $fetcher->fetch( $config );
expect_sheets( 'JSON API error maps to fetch_api_error with its message', is_wp_error( $api_error ) && 'fetch_api_error' === $api_error->get_error_code() && false !== strpos( $api_error->get_error_message(), 'Spreadsheet was not found.' ) );

$GLOBALS['bcs_sheet_get_response'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'values' => array() ) ) );
$zero = $fetcher->fetch( $config );
expect_sheets( 'empty values map to fetch_zero_rows', is_wp_error( $zero ) && 'fetch_zero_rows' === $zero->get_error_code() );

$GLOBALS['bcs_sheet_get_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( array( 'values' => array( array( 'name', 'sku' ), array( 'Item', 'SKU-1' ) ) ) ),
);
$schema = $fetcher->fetch( $config );
expect_sheets( 'header and saved mapping count mismatch maps to schema_mismatch', is_wp_error( $schema ) && 'schema_mismatch' === $schema->get_error_code() );

$GLOBALS['bcs_sheet_get_response'] = array( 'response' => array( 'code' => 429 ), 'body' => '{}' );
$rate_limited = $fetcher->fetch( $config );
expect_sheets( '429 values response maps to fetch_rate_limited', is_wp_error( $rate_limited ) && 'fetch_rate_limited' === $rate_limited->get_error_code() );

$drift_mapping = array(
	'name' => 'name',
	'sku'  => 'sku',
	'x1'   => 'not-a-field',
	'x2'   => 'not-a-field',
	'x3'   => 'not-a-field',
	'x4'   => 'not-a-field',
);
$GLOBALS['bcs_sheet_get_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( array( 'values' => array( array( 'name', 'sku', 'x1', 'x2', 'x3', 'x4' ), array( 'Drift', 'SKU-8', 'a', 'b', 'c', 'd' ) ) ) ),
);
$drift = $fetcher->fetch( array_merge( $config, array( 'column_mapping' => $drift_mapping ) ) );
expect_sheets( 'more than 30 percent unknown columns maps to schema_drift', is_wp_error( $drift ) && 'schema_drift' === $drift->get_error_code() );

$encrypted = BCS_Sync_Connection::encrypt( $credentials_json );
$GLOBALS['bcs_sheet_get_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( array( 'values' => array( array( 'name', 'description', 'sku', 'regular_price' ), array( 'Encrypted', '', 'SKU-9', '9' ) ) ) ),
);
delete_transient( $cache_key );
$encrypted_result = $fetcher->fetch( array_merge( $config, array( 'credentials' => $encrypted ) ) );
expect_sheets( 'encrypted credentials decrypt and fetch successfully', ! is_wp_error( $encrypted ) && ! is_wp_error( $encrypted_result ) && 'SKU-9' === $encrypted_result['rows'][0]['sku'] );

$broken_credentials = $credentials;
$broken_credentials['private_key'] = 'not-a-private-key';
delete_transient( $cache_key );
$broken = $fetcher->fetch( array_merge( $config, array( 'credentials' => json_encode( $broken_credentials ) ) ) );
expect_sheets( 'malformed private key maps to credentials_invalid', is_wp_error( $broken ) && 'credentials_invalid' === $broken->get_error_code() );

if ( $fail > 0 ) {
	echo "\n$fail SHEETS API TESTS FAILED\n";
	exit( 1 );
}
echo "\nALL SHEETS API TESTS PASSED\n";
exit( 0 );
