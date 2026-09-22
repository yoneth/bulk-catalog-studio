<?php
/**
 * BCS Sync foundation harness (no WordPress bootstrap required).
 *
 * The harness covers crypto, CSV export guards, key-based diff/thresholds,
 * variation writer behavior, runner state/lock/resume, and sync audit source.
 */

error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'AUTH_KEY', 'sync-test-auth-key' );
define( 'DB_PASSWORD', 'sync-test-db-password' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $value ) ) : ''; }
function sanitize_textarea_field( $value ) { return is_scalar( $value ) ? trim( (string) $value ) : ''; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_option( $name, $default = false ) { return $default; }
function wp_kses_post( $value ) { return (string) $value; }
function update_post_meta( $id, $key, $value ) { return true; }
function get_post_meta( $id, $key, $single = true ) {
	$product = wc_get_product( $id );
	return $product ? $product->get_meta( $key, $single, 'edit' ) : '';
}
function get_post( $id ) { return null; }
function get_term_by( $field, $value, $taxonomy ) { return false; }
function wp_insert_term( $name, $taxonomy, $args = array() ) { return array( 'term_id' => 1, 'slug' => sanitize_key( $name ) ); }
function get_terms( $args ) { return array(); }
function wc_delete_product_transients( $id ) {}
function wc_format_decimal( $value ) { return (string) ( 0 + str_replace( ',', '', (string) $value ) ); }
function wc_get_product_id_by_sku( $sku ) {
	$needle = strtolower( trim( (string) $sku ) );
	foreach ( (array) $GLOBALS['bcs_products'] as $id => $product ) {
		if ( strtolower( trim( (string) $product->get_sku( 'edit' ) ) ) === $needle ) { return (int) $id; }
	}
	return null;
}
function taxonomy_exists( $taxonomy ) { return false; }
function register_taxonomy( $taxonomy, $objects, $args ) {}
function wc_attribute_taxonomy_id_by_name( $slug ) { return 1; }
function wc_create_attribute( $args ) { return 1; }
function apply_filters( $tag, $value ) { return $value; }
function get_current_user_id() { return 1; }
function is_admin() { return false; }
function mysql2date( $format, $mysql, $translate = true ) { return (string) $mysql; }
function wp_rand( $min = 0, $max = 0 ) { return rand( $min, $max ); }
function wp_hash_password( $value ) { return 'H$' . md5( (string) $value ); }
function wp_check_password( $value, $hash, $user_id = 0 ) { return hash_equals( (string) $hash, wp_hash_password( $value ) ); }

class Fake_Sync_WPDB {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options = 'wp_options';
	public $insert_id = 0;
	public $tables = array();
	public $lock_result = 1;

	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $arg ) {
			$replacement = null === $arg ? 'NULL' : ( is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'" );
			$sql = preg_replace( '/%[dsf]/', $replacement, $sql, 1 );
		}
		return $sql;
	}
	private function table_rows( $table ) { return isset( $this->tables[ $table ] ) ? $this->tables[ $table ] : array(); }
	private function conditions( $sql ) {
		$conditions = array();
		if ( preg_match_all( "/(\w+)\s*=\s*'?([^'\s]+)'?/", $sql, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( 'id' === strtolower( $match[1] ) || in_array( strtolower( $match[1] ), array( 'connection_id', 'state', 'job_id', 'source', 'level', 'status', 'payload_digest' ), true ) ) {
					$conditions[ $match[1] ] = trim( $match[2], "'" );
				}
			}
		}
		return $conditions;
	}
	private function matches( $row, $conditions ) {
		foreach ( $conditions as $key => $value ) {
			if ( isset( $row[ $key ] ) && (string) $row[ $key ] !== (string) $value ) { return false; }
		}
		return true;
	}
	public function insert( $table, $data, $formats = null ) {
		$data['id'] = ++$this->insert_id;
		$this->tables[ $table ][] = $data;
		return 1;
	}
	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$rows = $this->table_rows( $table );
		$affected = 0;
		foreach ( $rows as $index => $row ) {
			$ok = true;
			foreach ( $where as $key => $value ) { if ( ! isset( $row[ $key ] ) || (string) $row[ $key ] !== (string) $value ) { $ok = false; } }
			if ( ! $ok ) { continue; }
			foreach ( $data as $key => $value ) { $rows[ $index ][ $key ] = $value; }
			$affected++;
		}
		$this->tables[ $table ] = $rows;
		return $affected;
	}
	public function delete( $table, $where, $formats = null ) {
		$rows = $this->table_rows( $table ); $kept = array(); $deleted = 0;
		foreach ( $rows as $row ) { if ( $this->matches( $row, $where ) ) { $deleted++; } else { $kept[] = $row; } }
		$this->tables[ $table ] = $kept;
		return $deleted;
	}
	public function get_row( $sql ) {
		if ( false !== stripos( $sql, 'SHOW COLUMNS' ) ) { return null; }
		if ( preg_match( '/FROM\s+(\S+)/i', $sql, $match ) ) {
			$table = trim( $match[1], '`' );
			$conditions = $this->conditions( $sql );
			$rows = $this->table_rows( $table );
			usort( $rows, function ( $a, $b ) { return (int) ( $b['id'] ?? 0 ) - (int) ( $a['id'] ?? 0 ); } );
			foreach ( $rows as $row ) { if ( $this->matches( $row, $conditions ) ) { return (object) $row; } }
		}
		return null;
	}
	public function get_results( $sql, $mode = null ) {
		if ( preg_match( '/FROM\s+(\S+)/i', $sql, $match ) ) {
			$table = trim( $match[1], '`' ); $conditions = $this->conditions( $sql ); $rows = array();
			foreach ( $this->table_rows( $table ) as $row ) { if ( $this->matches( $row, $conditions ) ) { $rows[] = ARRAY_A === $mode ? $row : (object) $row; } }
			return $rows;
		}
		return array();
	}
	public function get_var( $sql ) {
		if ( false !== stripos( $sql, 'GET_LOCK' ) ) { return $this->lock_result; }
		if ( false !== stripos( $sql, 'RELEASE_LOCK' ) ) { return 1; }
		if ( preg_match( '/SELECT\s+COUNT\(id\)\s+FROM\s+(\S+)/i', $sql, $match ) ) { return (string) count( $this->table_rows( trim( $match[1], '`' ) ) ); }
		if ( preg_match( '/SELECT\s+COUNT\(ID\)\s+FROM\s+(\S+)/i', $sql, $match ) ) { return '0'; }
		if ( preg_match( '/SELECT\s+([^\s]+)\s+FROM\s+(\S+)/i', $sql, $match ) ) {
			$row = $this->get_row( $sql );
			if ( $row ) { $key = trim( $match[1], '`' ); return isset( $row->{$key} ) ? (string) $row->{$key} : null; }
		}
		return null;
	}
	public function get_col( $sql ) { return array(); }
	public function query( $sql ) { return 1; }
}
$GLOBALS['wpdb'] = new Fake_Sync_WPDB();
$GLOBALS['bcs_products'] = array();
$GLOBALS['bcs_http_body'] = "name,sku,regular_price\nItem,SKU-1,10\n";

class WC_Product {
	public $props = array(); public $meta = array();
	public function __call( $method, $args ) {
		if ( 0 === strpos( $method, 'set_' ) ) { $this->props[ substr( $method, 4 ) ] = isset( $args[0] ) ? $args[0] : null; return $this; }
		if ( 0 === strpos( $method, 'get_' ) ) { $key = substr( $method, 4 ); return isset( $this->props[ $key ] ) ? $this->props[ $key ] : null; }
		return null;
	}
	public function save() { if ( empty( $this->props['id'] ) ) { $this->props['id'] = ++$GLOBALS['bcs_wc_next_id']; } $GLOBALS['bcs_products'][ $this->props['id'] ] = $this; return $this->props['id']; }
	public function get_id() { return (int) ( $this->props['id'] ?? 0 ); }
	public function get_type() { return 'simple'; }
	public function get_children( $context = 'edit' ) { return (array) ( $this->props['children'] ?? array() ); }
	public function delete( $force = false ) { $this->props['status'] = 'trash'; return true; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_meta( $key, $single = true, $context = 'edit' ) { return $this->meta[ $key ] ?? ''; }
}
class WC_Product_Variable extends WC_Product { public function get_type() { return 'variable'; } public static function sync( $id ) {} }
class WC_Product_Variation extends WC_Product { public function get_type() { return 'variation'; } }
class WC_Product_Attribute { public function __call( $method, $args ) { return $this; } }
function wc_get_product( $id ) { return $GLOBALS['bcs_products'][ (int) $id ] ?? null; }
$GLOBALS['bcs_wc_next_id'] = 1000;
function wp_remote_get( $url, $args = array() ) { return array( 'response' => array( 'code' => 200 ), 'body' => $GLOBALS['bcs_http_body'] ); }
function wp_remote_retrieve_response_code( $response ) { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return (string) $response['body']; }

$base = dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/includes/';
require $base . 'class-bcs-settings.php';
require $base . 'class-bcs-csv-parser.php';
require $base . 'class-bcs-sync-connection.php';
require $base . 'class-bcs-sync-fetcher.php';
require $base . 'class-bcs-sync-fetcher-csv.php';
require $base . 'class-bcs-sync-diff.php';
require $base . 'class-bcs-duplicate-checker.php';
require $base . 'class-bcs-audit-logger.php';
require $base . 'class-bcs-catalog-writer.php';
require $base . 'class-bcs-sync-runner.php';

$fail = 0;
function expect_sync( $label, $condition ) { global $fail; echo ( $condition ? 'PASS' : 'FAIL' ) . " - $label\n"; if ( ! $condition ) { $fail++; } }
function sync_connection( $extra = array() ) {
	return array_merge(
		array(
			'id' => 7,
			'fetcher' => 'csv_export',
			'sheet_config' => array( 'spreadsheet_id' => 'sheet-id', 'gid' => '0' ),
			'column_mapping' => array( 'name' => 'name', 'sku' => 'sku', 'regular_price' => 'regular_price' ),
			'policy' => array( 'on_missing_row' => 'flag', 'conflict_policy' => 'flag_only', 'auto_apply_mode' => 'within_threshold', 'seen_skus' => array() ),
			'thresholds' => array( 'max_create_update_ops' => 50, 'max_create_update_catalog_pct' => 10, 'max_delete_ops' => 10, 'missing_row_flag_pct' => 20, 'price_delta_pct' => 80, 'stock_drop_pct' => 90, 'sku_regex' => '^[A-Za-z0-9._-]{3,64}$' ),
		),
		$extra
	);
}

/* Crypto. */
$encrypted = BCS_Sync_Connection::encrypt( 'service-account-secret' );
expect_sync( 'encryption round-trip succeeds', ! is_wp_error( $encrypted ) && 'service-account-secret' === BCS_Sync_Connection::decrypt( $encrypted ) );
$parts = explode( '.', $encrypted );
$parts[1] = substr( $parts[1], 0, -1 ) . ( 'A' === substr( $parts[1], -1 ) ? 'B' : 'A' );
expect_sync( 'tampered credentials are rejected', is_wp_error( BCS_Sync_Connection::decrypt( implode( '.', $parts ) ) ) );
$connection_secret_id = BCS_Sync_Connection::insert( array( 'name' => 'Encrypted connection', 'credentials' => 'service-account-json', 'sheet_config' => array( 'spreadsheet_id' => 'sheet-id', 'gid' => '0' ) ) );
$connection_secret_raw = ! is_wp_error( $connection_secret_id ) ? BCS_Sync_Connection::get_raw( $connection_secret_id ) : null;
$connection_secret_decoded = ! is_wp_error( $connection_secret_id ) ? BCS_Sync_Connection::get( $connection_secret_id ) : null;
expect_sync( 'connection CRUD encrypts credentials at rest', ! is_wp_error( $connection_secret_id ) && $connection_secret_raw && 'service-account-json' !== (string) $connection_secret_raw->credentials && is_array( $connection_secret_decoded ) && 'service-account-json' === $connection_secret_decoded['credentials'] );

/* Fetcher guards. */
$fetcher = new BCS_Sync_Fetcher_Csv_Export();
$fetch_config = sync_connection();
$GLOBALS['bcs_http_body'] = '<html>login</html>';
$fetch_error = $fetcher->fetch( $fetch_config );
expect_sync( 'HTML response is rejected', is_wp_error( $fetch_error ) && 'fetch_not_csv' === $fetch_error->get_error_code() );
$GLOBALS['bcs_http_body'] = "name,sku,regular_price\n";
$fetch_error = $fetcher->fetch( $fetch_config );
expect_sync( 'zero data rows are rejected', is_wp_error( $fetch_error ) && 'fetch_zero_rows' === $fetch_error->get_error_code() );
$GLOBALS['bcs_http_body'] = "name,sku\nItem,SKU-1\n";
$fetch_error = $fetcher->fetch( $fetch_config );
expect_sync( 'header mapping mismatch is rejected', is_wp_error( $fetch_error ) && 'schema_mismatch' === $fetch_error->get_error_code() );
$GLOBALS['bcs_http_body'] = "name,sku,regular_price\nItem,SKU-1,10\n";
$fetch_ok = $fetcher->test( $fetch_config );
expect_sync( 'valid CSV passes fetch and parse', ! is_wp_error( $fetch_ok ) && 1 === count( $fetch_ok['rows'] ) );

/* Diff fixtures. */
$base_state = array( 'catalog_count' => 100, 'products' => array( 'SKU-1' => array( 'id' => 101, 'sku' => 'SKU-1', 'name' => 'Item', 'regular_price' => '10', 'stock_quantity' => 10, 'sync_hash' => '', 'synced_at' => '2026-01-01 00:00:00', 'post_modified' => '2026-01-01 00:00:00' ) ) );
$reorder_state = $base_state;
$reorder_state['products']['SKU-2'] = array( 'id' => 102, 'sku' => 'SKU-2', 'name' => 'Second item', 'regular_price' => '12', 'stock_quantity' => 2, 'sync_hash' => '', 'synced_at' => '2026-01-01 00:00:00', 'post_modified' => '2026-01-01 00:00:00' );
$reorder = BCS_Sync_Diff::diff( array( array( 'sku' => 'SKU-2', 'name' => 'Second item', 'regular_price' => '12' ), array( 'sku' => 'SKU-1', 'name' => 'Item', 'regular_price' => '10' ) ), sync_connection(), $reorder_state );
expect_sync( 'reordering/key-only unchanged data produces no operation', ! is_wp_error( $reorder ) && 0 === count( $reorder['operations'] ) );
$update = BCS_Sync_Diff::diff( array( array( 'sku' => 'SKU-1', 'name' => 'Item', 'regular_price' => '12' ) ), sync_connection(), $base_state );
expect_sync( 'field update creates one update operation', ! is_wp_error( $update ) && 1 === count( $update['operations'] ) && 'update' === $update['operations'][0]['op'] && '10' === (string) $update['operations'][0]['before']['regular_price'] );
$variation_state = array( 'catalog_count' => 1, 'products' => array( 'SKU-1' => array( 'id' => 101, 'sku' => 'SKU-1', 'name' => 'Item', 'regular_price' => '10', 'variations' => array( 'VAR-1' => array( 'id' => 201, 'sku' => 'VAR-1', 'regular_price' => '10', 'stock_quantity' => 3 ) ) ) ) );
$variation = BCS_Sync_Diff::diff( array( array( 'sku' => 'SKU-1', 'variations' => array( array( 'sku' => 'VAR-1', 'regular_price' => '11' ) ) ) ), sync_connection(), $variation_state );
expect_sync( 'variation price change creates only update_variation', ! is_wp_error( $variation ) && 1 === count( $variation['operations'] ) && 'update_variation' === $variation['operations'][0]['op'] && 101 === (int) $variation['operations'][0]['target_product_id'] );
$new_with_variation = BCS_Sync_Diff::diff( array( array( 'sku' => 'SKU-NEW', 'name' => 'New item', 'variations' => array( array( 'sku' => 'VAR-NEW', 'regular_price' => '10' ) ) ) ), sync_connection(), array( 'catalog_count' => 1, 'products' => array() ) );
expect_sync( 'new product variations stay in the create payload', ! is_wp_error( $new_with_variation ) && 1 === count( $new_with_variation['operations'] ) && 'create' === $new_with_variation['operations'][0]['op'] && 1 === count( $new_with_variation['operations'][0]['payload']['variations'] ) && 0 === $new_with_variation['summary']['quarantined'] );
$delete = BCS_Sync_Diff::diff( array( array( 'sku' => 'SKU-1', 'sync_action' => 'delete' ) ), sync_connection(), $base_state );
expect_sync( 'explicit sync_action delete creates delete operation', ! is_wp_error( $delete ) && 'delete' === $delete['operations'][0]['op'] );
$missing_connection = sync_connection( array( 'policy' => array( 'seen_skus' => array( 'SKU-1', 'SKU-2', 'SKU-3', 'SKU-4', 'SKU-5' ) ) ) );
$missing = BCS_Sync_Diff::diff( array( array( 'sku' => 'SKU-1', 'name' => 'Item', 'regular_price' => '10' ), array( 'sku' => 'SKU-2' ), array( 'sku' => 'SKU-3' ), array( 'sku' => 'SKU-4' ) ), $missing_connection, $base_state );
expect_sync( 'one missing SKU is flagged', ! is_wp_error( $missing ) && 1 === $missing['summary']['flag_missing'] );
$guard = BCS_Sync_Diff::diff( array( array( 'sku' => 'SKU-1', 'name' => 'Item', 'regular_price' => '10' ) ), sync_connection( array( 'policy' => array( 'seen_skus' => array( 'SKU-1', 'SKU-2', 'SKU-3', 'SKU-4', 'SKU-5' ) ) ) ), $base_state );
expect_sync( 'more than 20 percent missing aborts', is_wp_error( $guard ) && 'missing_rows_guard' === $guard->get_error_code() );
$duplicate = BCS_Sync_Diff::diff( array( array( 'sku' => 'SKU-1' ), array( 'sku' => 'sku-1' ) ), sync_connection(), array( 'catalog_count' => 100, 'products' => array() ) );
expect_sync( 'duplicate SKU aborts', is_wp_error( $duplicate ) && 'duplicate_sku' === $duplicate->get_error_code() );
$many_rows = array(); for ( $i = 1; $i <= 51; $i++ ) { $many_rows[] = array( 'sku' => 'SKU-' . $i, 'name' => 'Item ' . $i, 'regular_price' => '10' ); }
$threshold = BCS_Sync_Diff::diff( $many_rows, sync_connection(), array( 'catalog_count' => 100, 'products' => array() ) );
expect_sync( 'run-level threshold waits for approval', ! is_wp_error( $threshold ) && 'awaiting_approval' === $threshold['status'] && 'threshold_run' === $threshold['approval_reason'] );
$delete_rows = array(); for ( $i = 1; $i <= 11; $i++ ) { $delete_rows[] = array( 'sku' => 'SKU-' . $i, 'sync_action' => 'delete' ); }
$delete_state = array( 'catalog_count' => 100, 'products' => array() ); foreach ( $delete_rows as $row ) { $delete_state['products'][ strtolower( $row['sku'] ) ] = array( 'id' => 100 + count( $delete_state['products'] ) + 1, 'sku' => $row['sku'] ); }
$delete_threshold = BCS_Sync_Diff::diff( $delete_rows, sync_connection(), $delete_state );
expect_sync( 'delete threshold waits for approval', ! is_wp_error( $delete_threshold ) && 'awaiting_approval' === $delete_threshold['status'] && 'threshold_delete' === $delete_threshold['approval_reason'] );
$quarantine = BCS_Sync_Diff::diff( array( array( 'sku' => 'SKU-1', 'regular_price' => '100' ) ), sync_connection(), $base_state );
expect_sync( 'large price delta is quarantined', ! is_wp_error( $quarantine ) && 1 === $quarantine['summary']['quarantined'] && 0 === $quarantine['summary']['update'] );
$conflict_state = $base_state; $conflict_state['products']['SKU-1']['post_modified'] = '2026-01-02 00:00:00';
$conflict = BCS_Sync_Diff::diff( array( array( 'sku' => 'SKU-1', 'regular_price' => '12' ) ), sync_connection(), $conflict_state );
expect_sync( 'manual edit becomes flag-only conflict', ! is_wp_error( $conflict ) && 'conflict' === $conflict['operations'][0]['op'] );

/* Variation writer. */
$parent = new WC_Product_Variable(); $parent->set_sku( 'PARENT-1' ); $parent_id = $parent->save();
$variation_object = new WC_Product_Variation(); $variation_object->set_sku( 'VAR-1' ); $variation_object->set_regular_price( '10' ); $variation_object->set_stock_quantity( 2 ); $variation_id = $variation_object->save();
$parent->props['children'] = array( $variation_id ); $parent->save();
$variation_write = BCS_Catalog_Writer::update_variation( $parent_id, 'VAR-1', array( 'regular_price' => '12', 'stock_quantity' => 5 ), array() );
expect_sync( 'update_variation changes scalar price and stock', ! is_wp_error( $variation_write ) && '10' === (string) $variation_write['before']['regular_price'] && '12' === (string) $GLOBALS['bcs_products'][ $variation_id ]->get_regular_price() && 5 === (int) $GLOBALS['bcs_products'][ $variation_id ]->get_stock_quantity() );
$sku_change = BCS_Catalog_Writer::update_variation( $parent_id, 'VAR-1', array( 'sku' => 'NEW-VAR' ), array() );
expect_sync( 'new variation SKU is rejected', is_wp_error( $sku_change ) && 'bcs_variation_sku_change' === $sku_change->get_error_code() );
$soft_deleted_variation = BCS_Catalog_Writer::soft_delete_variation( $parent_id, 'VAR-1' );
expect_sync( 'soft_delete_variation trashes the matching child', true === $soft_deleted_variation && 'trash' === $GLOBALS['bcs_products'][ $variation_id ]->props['status'] );

/* Runner state and audit source. */
$run_insert = $GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array( 'connection_id' => 7, 'run_trigger' => 'manual', 'state' => 'pending', 'started_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$run_id = $GLOBALS['wpdb']->insert_id;
expect_sync( 'valid runner transition succeeds', true === BCS_Sync_Runner::transition( $run_id, 'pending', 'fetching' ) );
expect_sync( 'invalid runner transition is rejected', is_wp_error( BCS_Sync_Runner::transition( $run_id, 'pending', 'done' ) ) );
$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array( 'connection_id' => 777, 'run_trigger' => 'manual', 'state' => 'awaiting_approval', 'diff_hash' => sha1( 'approved-diff' ), 'diff_summary' => wp_json_encode( array( 'summary' => array(), 'operations' => array() ) ), 'token_hash' => null, 'token_expires_at' => null, 'token_used_at' => null, 'approved_by' => null, 'approved_at' => null, 'started_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$approval_run_id = $GLOBALS['wpdb']->insert_id;
expect_sync( 'approval rejects a mismatched diff hash', is_wp_error( BCS_Sync_Runner::approve_run( $approval_run_id, sha1( 'other-diff' ) ) ) );
$approval_token = BCS_Sync_Runner::generate_run_token( $approval_run_id );
$approved = BCS_Sync_Runner::approve_run( $approval_run_id, sha1( 'approved-diff' ), $approval_token, get_current_user_id() );
expect_sync( 'approval transitions awaiting_approval to approved', ! is_wp_error( $approved ) && 'approved' === $approved['state'] );
$GLOBALS['wpdb']->lock_result = 0;
$busy = BCS_Sync_Runner::run( 7, 'manual' );
expect_sync( 'lock_busy is returned when named lock is unavailable', is_wp_error( $busy ) && 'lock_busy' === $busy->get_error_code() );
$GLOBALS['wpdb']->lock_result = 1;
BCS_Audit_Logger::log_row( 'bcs/sync-run', 'update_product', 'ok', 0, 'SKU-1', 101, 'sync', sha1( 'idempotent' ), 77, 0, 'sync', 'sync' );
$last_audit = end( $GLOBALS['wpdb']->tables['wp_bcs_audit'] );
expect_sync( 'sync audit entry stores source=sync', isset( $last_audit['source'] ) && 'sync' === $last_audit['source'] );

/* Stale applying run with matching hash: runner must finish without another write/audit. */
$connection_id = BCS_Sync_Connection::insert( array( 'name' => 'Runner test', 'status' => 'active', 'sheet_config' => array( 'spreadsheet_id' => 'sheet-id', 'gid' => '0' ), 'column_mapping' => array( 'name' => 'name', 'sku' => 'sku', 'regular_price' => 'regular_price' ) ) );
if ( ! is_wp_error( $connection_id ) ) {
	$existing = new WC_Product(); $existing->set_sku( 'RUN-1' ); $existing->set_name( 'Run item' ); $existing->update_meta_data( '_bcs_sync_hash', sha1( 'same' ) ); $existing_id = $existing->save();
	$operation = array( 'op' => 'update', 'sku' => 'RUN-1', 'variation_sku' => null, 'payload' => array( 'sku' => 'RUN-1' ), 'before' => array( 'id' => $existing_id ), 'reason' => 'field_changed', 'sync_hash' => sha1( 'same' ), 'operation_key' => sha1( 'run-key' ), 'target_product_id' => $existing_id );
	$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array( 'connection_id' => $connection_id, 'run_trigger' => 'manual', 'state' => 'applying', 'diff_hash' => sha1( 'run' ), 'diff_summary' => wp_json_encode( array( 'summary' => array(), 'operations' => array( $operation ), 'quarantine' => array(), 'seen_skus' => array() ) ), 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ), 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ) );
	$resume = BCS_Sync_Runner::run( $connection_id, 'manual' );
	expect_sync( 'stale applying run resumes idempotently', ! is_wp_error( $resume ) && 'done' === $resume['status'] );
}

if ( $fail > 0 ) { echo "\n$fail TESTS FAILED\n"; exit( 1 ); }
echo "\nALL SYNC TESTS PASSED\n";
exit( 0 );
