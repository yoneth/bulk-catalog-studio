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
function get_option( $name, $default = false ) { return $GLOBALS['bcs_options'][ $name ] ?? $default; }
function update_option( $name, $value ) { $GLOBALS['bcs_options'][ $name ] = $value; return true; }
function wp_kses_post( $value ) { return (string) $value; }
function update_post_meta( $id, $key, $value ) {
	// Records the meta WITHOUT calling save(), mirroring WordPress: a raw meta
	// write does not touch post_modified.
	$product = wc_get_product( $id );
	if ( $product ) { $product->meta[ $key ] = $value; }
	return true;
}
function get_post_meta( $id, $key, $single = true ) {
	$product = wc_get_product( $id );
	return $product ? $product->get_meta( $key, $single, 'edit' ) : '';
}
function get_post_field( $field, $id ) {
	$product = wc_get_product( $id );
	if ( ! $product || ! isset( $product->props[ $field ] ) ) {
		return '';
	}
	return (string) $product->props[ $field ];
}
function get_post( $id ) { return null; }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }
function get_term_by( $field, $value, $taxonomy ) { return false; }
function wp_insert_term( $name, $taxonomy, $args = array() ) {
	// Remember which taxonomy each term was created in; ensure_term() used to
	// route product_tag into the pa_ attribute namespace.
	$GLOBALS['bcs_inserted_terms'][] = array( 'name' => $name, 'taxonomy' => $taxonomy );
	return array( 'term_id' => 1, 'slug' => sanitize_key( $name ) );
}
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
function sanitize_title( $value ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $value ), '-' ) ); }
function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( (array) $list as $item ) { $out[] = is_array( $item ) ? ( $item[ $field ] ?? null ) : ( $item->$field ?? null ); }
	return $out;
}
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
	public function save() {
		if ( empty( $this->props['id'] ) ) { $this->props['id'] = ++$GLOBALS['bcs_wc_next_id']; }
		// WordPress bumps post_modified on every save; the fake does too so a
		// regression can tell a CRUD save apart from a raw meta write.
		$GLOBALS['bcs_fake_clock'] = ( $GLOBALS['bcs_fake_clock'] ?? 0 ) + 1;
		$this->props['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', 1800000000 + $GLOBALS['bcs_fake_clock'] );
		$GLOBALS['bcs_products'][ $this->props['id'] ] = $this;
		return $this->props['id'];
	}
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

defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
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
require $base . 'class-bcs-log-purge.php';

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
/*
 * Log retention. Deleting history cannot be undone, so the four protection rules
 * are pinned here as invariants rather than left to the purge query's shape.
 */
$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 200 * DAY_IN_SECONDS ) );
$new_date = gmdate( 'Y-m-d H:i:s' );
$purge_runs = array(
	// Old and finished, on a connection that has a newer run: the only candidate.
	array( 'id' => 9001, 'connection_id' => 50, 'state' => 'done', 'started_at' => $old_date, 'diff_summary' => wp_json_encode( array( 'quarantine' => array() ) ) ),
	// (a) the latest run of its connection — rollback_run() needs it.
	array( 'id' => 9002, 'connection_id' => 50, 'state' => 'done', 'started_at' => $old_date, 'diff_summary' => wp_json_encode( array( 'quarantine' => array() ) ) ),
	// (b) still waiting for a human.
	array( 'id' => 9003, 'connection_id' => 51, 'state' => 'awaiting_approval', 'started_at' => $old_date, 'diff_summary' => wp_json_encode( array( 'quarantine' => array() ) ) ),
	// (c) holds a quarantine entry nobody answered.
	array( 'id' => 9004, 'connection_id' => 52, 'state' => 'done', 'started_at' => $old_date, 'diff_summary' => wp_json_encode( array( 'quarantine' => array( array( 'sku' => 'HOLD-1', 'reason' => 'price_delta_exceeded' ) ) ) ) ),
	// keeps 9004 from being the latest of its connection
	array( 'id' => 9005, 'connection_id' => 52, 'state' => 'done', 'started_at' => $new_date, 'diff_summary' => wp_json_encode( array( 'quarantine' => array() ) ) ),
	// keeps 9003 from being the latest of its connection
	array( 'id' => 9006, 'connection_id' => 51, 'state' => 'done', 'started_at' => $new_date, 'diff_summary' => wp_json_encode( array( 'quarantine' => array() ) ) ),
);
$purge_protected = BCS_Log_Purge::protected_run_ids( $purge_runs );
expect_sync(
	'the latest run of a connection is never purged (rollback depends on it)',
	isset( $purge_protected[9002] ) && 'latest_run' === $purge_protected[9002]
);
expect_sync(
	'a run awaiting approval is never purged',
	isset( $purge_protected[9003] ) && 0 === strpos( (string) $purge_protected[9003], 'active_state:' )
);
expect_sync(
	'a run holding an unresolved quarantine entry is never purged',
	isset( $purge_protected[9004] ) && 'unresolved_quarantine' === $purge_protected[9004]
);
expect_sync(
	'an old finished run that nothing depends on is purgeable',
	! isset( $purge_protected[9001] )
);
// A resolved quarantine entry no longer protects its run.
$purge_resolved_run = array( array( 'id' => 9007, 'connection_id' => 53, 'state' => 'done', 'started_at' => $old_date, 'diff_summary' => wp_json_encode( array( 'quarantine' => array( array( 'sku' => 'HOLD-1', 'resolved' => true ) ) ) ) ), array( 'id' => 9008, 'connection_id' => 53, 'state' => 'done', 'started_at' => $new_date, 'diff_summary' => '' ) );
$purge_protected_resolved = BCS_Log_Purge::protected_run_ids( $purge_resolved_run );
expect_sync(
	'a resolved quarantine entry stops protecting its run',
	! isset( $purge_protected_resolved[9007] )
);

// End-to-end purge against the fake tables, including invariant (d): the audit
// trail of a run that is being kept must be kept with it.
$GLOBALS['wpdb']->tables['wp_bcs_sync_runs'] = array();
$GLOBALS['wpdb']->tables['wp_bcs_audit'] = array();
foreach ( $purge_runs as $pr ) {
	$GLOBALS['wpdb']->tables['wp_bcs_sync_runs'][] = $pr;
}
$GLOBALS['wpdb']->tables['wp_bcs_audit'] = array(
	array( 'id' => 8001, 'job_id' => 9001, 'source' => 'sync', 'created_at' => $old_date ), // milik run yang dihapus
	array( 'id' => 8002, 'job_id' => 9002, 'source' => 'sync', 'created_at' => $old_date ), // milik run yang DIPERTAHANKAN
	array( 'id' => 8003, 'job_id' => 9004, 'source' => 'sync', 'created_at' => $old_date ), // milik run karantina belum resolved
	array( 'id' => 8004, 'job_id' => 0,    'source' => 'batch', 'created_at' => $old_date ), // batch lama, boleh dihapus
	array( 'id' => 8005, 'job_id' => 9001, 'source' => 'sync', 'created_at' => $new_date ), // masih baru
);
update_option( 'bcs_settings', array( 'audit_retention_days' => 90, 'sync_runs_retention_days' => 90, 'purge_batch_size' => 200 ) );

$purge_report = BCS_Log_Purge::purge( array( 'dry_run' => true ) );
$runs_left_after_dry = count( $GLOBALS['wpdb']->tables['wp_bcs_sync_runs'] );
expect_sync(
	'a dry run reports work without deleting anything',
	1 === (int) $purge_report['runs_deleted'] && 6 === $runs_left_after_dry
);

$purge_report = BCS_Log_Purge::purge();
$remaining_runs = array();
foreach ( $GLOBALS['wpdb']->tables['wp_bcs_sync_runs'] as $row ) { $remaining_runs[] = (int) $row['id']; }
$remaining_audit = array();
foreach ( $GLOBALS['wpdb']->tables['wp_bcs_audit'] as $row ) { $remaining_audit[] = (int) $row['id']; }
expect_sync(
	'purge deletes only the old unprotected run',
	! in_array( 9001, $remaining_runs, true )
	&& in_array( 9002, $remaining_runs, true )
	&& in_array( 9003, $remaining_runs, true )
	&& in_array( 9004, $remaining_runs, true )
);
expect_sync(
	'audit of a protected run is kept, audit of a purged run is not',
	! in_array( 8001, $remaining_audit, true )
	&& in_array( 8002, $remaining_audit, true )
	&& in_array( 8003, $remaining_audit, true )
	&& ! in_array( 8004, $remaining_audit, true )
	&& in_array( 8005, $remaining_audit, true )
);
$GLOBALS['wpdb']->tables['wp_bcs_sync_runs'] = array();
$GLOBALS['wpdb']->tables['wp_bcs_audit'] = array();

/*
 * Quarantine deduplication. A held row is recorded inside every run's
 * diff_summary, so a sync on a one-minute schedule records an identical entry
 * once a minute. The screen showed one row per run for a single underlying
 * problem, and resolving one of them left all the others open.
 */
$q_conn = 4242;
$q_payload = array( 'sku' => 'QRT-1', 'regular_price' => '400000' );
$q_entry = array( 'sku' => 'QRT-1', 'reason' => 'price_delta_exceeded', 'payload' => $q_payload );
for ( $i = 0; $i < 4; $i++ ) {
	$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array(
		'connection_id' => $q_conn,
		'run_trigger'   => 'cron',
		'state'         => 'done',
		'diff_hash'     => sha1( 'q' . $i ),
		'diff_summary'  => wp_json_encode( array( 'summary' => array(), 'operations' => array(), 'quarantine' => array( $q_entry ), 'seen_skus' => array() ) ),
		'started_at'    => gmdate( 'Y-m-d H:i:s' ),
	) );
}
// A genuinely different payload must stay a separate entry.
$q_changed = $q_entry;
$q_changed['payload']['regular_price'] = '999000';
$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array(
	'connection_id' => $q_conn,
	'run_trigger'   => 'cron',
	'state'         => 'done',
	'diff_hash'     => sha1( 'q-changed' ),
	'diff_summary'  => wp_json_encode( array( 'summary' => array(), 'operations' => array(), 'quarantine' => array( $q_changed ), 'seen_skus' => array() ) ),
	'started_at'    => gmdate( 'Y-m-d H:i:s' ),
) );

$q_list = BCS_Sync_Runner::list_quarantine( $q_conn, 200 );
$q_same = array();
foreach ( $q_list as $row ) {
	if ( '400000' === (string) ( $row['payload']['regular_price'] ?? '' ) ) { $q_same[] = $row; }
}
expect_sync(
	'four identical quarantine entries collapse into one row',
	1 === count( $q_same ) && 4 === (int) ( $q_same[0]['occurrences'] ?? 0 )
);
expect_sync(
	'a quarantine entry whose payload changed stays a separate row',
	2 === count( $q_list )
);
expect_sync(
	'the collapsed row points at the latest run and remembers the first',
	isset( $q_same[0]['run_id'], $q_same[0]['first_seen_run_id'] ) && $q_same[0]['run_id'] > $q_same[0]['first_seen_run_id']
);

// Resolving once must settle every copy, not just the run that was clicked.
$q_resolved = BCS_Sync_Runner::resolve_quarantine( (int) $q_same[0]['run_id'], 'QRT-1', 'discard', 'wp_admin' );
$q_after = BCS_Sync_Runner::list_quarantine( $q_conn, 200 );
$q_still_open = 0;
foreach ( $q_after as $row ) {
	if ( '400000' === (string) ( $row['payload']['regular_price'] ?? '' ) ) { $q_still_open++; }
}
expect_sync(
	'resolving one occurrence settles the whole quarantine group',
	! is_wp_error( $q_resolved ) && 0 === $q_still_open
);
expect_sync(
	'resolving one group leaves the other entry untouched',
	1 === count( $q_after )
);

/*
 * Image sideload (gate1 #4, promoted to V1.1). Server-side fetching of a URL is
 * an SSRF primitive, so the guard is the feature: the decision that opened
 * sideloading paired it with a domain whitelist, and these cases pin that pairing
 * down. Every check must fail closed.
 */
$sideload_settings = array(
	'allow_sideload'            => true,
	'sideload_domain_whitelist' => array( 'cf.djavaweb.com', 'img.lazcdn.com' ),
);
update_option( 'bcs_settings', $sideload_settings );

expect_sync(
	'a whitelisted https host is accepted',
	true === BCS_Catalog_Writer::validate_sideload_url( 'https://cf.djavaweb.com/bcs-e2e/foto.jpg' )
);
expect_sync(
	'a subdomain of a whitelisted host is accepted',
	true === BCS_Catalog_Writer::validate_sideload_url( 'https://cdn.img.lazcdn.com/p/foto.jpg' )
);
$plain_http = BCS_Catalog_Writer::validate_sideload_url( 'http://cf.djavaweb.com/foto.jpg' );
expect_sync(
	'plain http is rejected',
	is_wp_error( $plain_http ) && 'bcs_image_scheme_forbidden' === $plain_http->get_error_code()
);
$off_whitelist = BCS_Catalog_Writer::validate_sideload_url( 'https://penyerang.test/foto.jpg' );
expect_sync(
	'a host outside the whitelist is rejected',
	is_wp_error( $off_whitelist ) && 'bcs_image_host_not_whitelisted' === $off_whitelist->get_error_code()
);
// The classic whitelist bypass: a suffix that merely ends with the allowed name.
$suffix_trick = BCS_Catalog_Writer::validate_sideload_url( 'https://evilcf.djavaweb.com.penyerang.test/foto.jpg' );
expect_sync(
	'a host that only ends with the whitelisted name is rejected',
	is_wp_error( $suffix_trick ) && 'bcs_image_host_not_whitelisted' === $suffix_trick->get_error_code()
);
$literal_ip = BCS_Catalog_Writer::validate_sideload_url( 'https://169.254.169.254/latest/meta-data/' );
expect_sync(
	'a literal IP address is rejected',
	is_wp_error( $literal_ip ) && 'bcs_image_host_forbidden' === $literal_ip->get_error_code()
);
$internal_host = BCS_Catalog_Writer::validate_sideload_url( 'https://redis.internal/foto.jpg' );
expect_sync(
	'an internal host is rejected',
	is_wp_error( $internal_host ) && 'bcs_image_host_forbidden' === $internal_host->get_error_code()
);
update_option( 'bcs_settings', array( 'allow_sideload' => true, 'sideload_domain_whitelist' => array() ) );
$empty_whitelist = BCS_Catalog_Writer::validate_sideload_url( 'https://cf.djavaweb.com/foto.jpg' );
expect_sync(
	'an empty whitelist rejects everything rather than allowing everything',
	is_wp_error( $empty_whitelist ) && 'bcs_image_host_not_whitelisted' === $empty_whitelist->get_error_code()
);
update_option( 'bcs_settings', array( 'allow_sideload' => false, 'sideload_domain_whitelist' => array( 'cf.djavaweb.com' ) ) );
$disabled = BCS_Catalog_Writer::validate_sideload_url( 'https://cf.djavaweb.com/foto.jpg' );
expect_sync(
	'sideloading stays off while the setting is off',
	is_wp_error( $disabled ) && 'bcs_image_sideload_disabled' === $disabled->get_error_code()
);
update_option( 'bcs_settings', $sideload_settings );

/*
 * Conflict classification. WooCommerce touches a product asynchronously after a
 * write, so post_modified drifts past the snapshot with no owned field changing.
 * That drift is harmless alone, but it used to turn the next legitimate sheet
 * edit into a manual conflict that could never apply.
 */
$drift_hash = sha1( 'source-row-as-last-synced' );
$drift_product = array(
	'id'                 => 601,
	'sku'                => 'DRIFT-SKU',
	'name'               => 'Dress Katun Melati 10',
	'regular_price'      => '10',
	'sync_hash'          => $drift_hash,
	'post_modified'      => '2026-01-02 00:00:00',
	'post_modified_gmt'  => '2026-01-02 00:00:00',
	'sync_post_modified' => '2026-01-01 00:00:00',
	'conflict_flag'      => '',
	'variations'         => array(),
);
$drift_state = array( 'catalog_count' => 100, 'products' => array( 'drift-sku' => $drift_product ) );

// (a) the sheet moved while the product had drifted: a real edit, not a conflict.
$sheet_edit = BCS_Sync_Diff::diff(
	array( array( 'sku' => 'DRIFT-SKU', 'name' => 'Edited — Dress Katun Melati 10', 'regular_price' => '10' ) ),
	sync_connection(),
	$drift_state
);
expect_sync(
	'a sheet edit on a drifted product applies instead of raising a conflict',
	! is_wp_error( $sheet_edit ) && 1 === count( $sheet_edit['operations'] ) && 'update' === $sheet_edit['operations'][0]['op']
);

// (b) the source stood still and WordPress moved: a human edited the product.
$still_source = $drift_state;
$still_source['products']['drift-sku']['name'] = 'Diedit tangan di WP';
$still_source['products']['drift-sku']['sync_hash'] = BCS_Sync_Diff::payload_hash( array( 'sku' => 'DRIFT-SKU', 'name' => 'Dress Katun Melati 10', 'regular_price' => '10' ) );
$manual_edit = BCS_Sync_Diff::diff(
	array( array( 'sku' => 'DRIFT-SKU', 'name' => 'Dress Katun Melati 10', 'regular_price' => '10' ) ),
	sync_connection(),
	$still_source
);
expect_sync(
	'an unchanged source against a changed record is still a conflict',
	! is_wp_error( $manual_edit ) && 1 === count( $manual_edit['operations'] ) && 'conflict' === $manual_edit['operations'][0]['op']
);

// (c) an unresolved flag outranks a sheet edit: flag_only must not be bypassed.
$flagged_state = $drift_state;
$flagged_state['products']['drift-sku']['conflict_flag'] = '2026-01-02 00:00:00';
$sheet_edit_while_flagged = BCS_Sync_Diff::diff(
	array( array( 'sku' => 'DRIFT-SKU', 'name' => 'Edited — Dress Katun Melati 10', 'regular_price' => '10' ) ),
	sync_connection(),
	$flagged_state
);
expect_sync(
	'a sheet edit does not overwrite a conflict a human has not resolved',
	! is_wp_error( $sheet_edit_while_flagged ) && 1 === count( $sheet_edit_while_flagged['operations'] ) && 'conflict' === $sheet_edit_while_flagged['operations'][0]['op']
);

// Flagging a conflict must not move post_modified. Conflict detection compares
// post_modified against the snapshot the last sync write left behind, so a flag
// written through the CRUD object pushed post_modified past the snapshot and
// re-raised the very same conflict on every following run — a conflict that
// perpetuated itself and blocked the product forever.
$flagged = new WC_Product();
$flagged->set_sku( 'FLAG-1' );
$flagged->set_name( 'Flagged item' );
$flagged_id = $flagged->save();
$modified_before_flag = (string) $GLOBALS['bcs_products'][ $flagged_id ]->props['post_modified_gmt'];
BCS_Catalog_Writer::flag_sync_conflict( $flagged_id, '2026-01-01 00:00:00' );
expect_sync(
	'flagging a conflict writes the flag without moving post_modified',
	'2026-01-01 00:00:00' === (string) $GLOBALS['bcs_products'][ $flagged_id ]->get_meta( '_bcs_conflict_flag', true, 'edit' )
	&& $modified_before_flag === (string) $GLOBALS['bcs_products'][ $flagged_id ]->props['post_modified_gmt']
);

/*
 * Convergence regressions. A source that has not changed must produce no
 * operations on a second run. Each case below is a field that could not
 * round-trip into WordPress, so the diff reported field_changed forever, the
 * run never converged and the thresholds tripped on every scheduled run.
 */

// Dimensions: the catalog record built from SQL does not expose them, so they
// must be skipped like every other field the record does not carry.
$dimension_state = array(
	'catalog_count' => 100,
	'products'      => array(
		'dim-1' => array( 'id' => 501, 'sku' => 'DIM-1', 'regular_price' => '10', 'sync_hash' => '', 'variations' => array() ),
	),
);
$dimension_diff = BCS_Sync_Diff::diff(
	array( array( 'sku' => 'DIM-1', 'regular_price' => '10', 'dimensions' => array( 'length' => '30', 'width' => '25', 'height' => '3' ) ) ),
	sync_connection(),
	$dimension_state
);
expect_sync(
	'unchanged source with dimensions produces no operation',
	! is_wp_error( $dimension_diff ) && 0 === count( $dimension_diff['operations'] )
);

// name/description/short_description live on the post row, not in meta, and the
// catalog record neither selected nor mapped them. payload_matches_record()
// skips any field the record does not carry, so a sheet edit to the title or
// description produced zero operations. The record below carries the raw post
// columns, exactly as a WordPress row does, so the mapping is what makes them
// comparable. The SELECT half of the fix needs a database and is verified live.
$text_state = array(
	'catalog_count' => 100,
	'products'      => array(
		'txt-1' => array(
			'id'           => 504,
			'sku'          => 'TXT-1',
			'post_title'   => 'Kemeja Flanel Pria 01',
			'post_content' => '<p>Deskripsi lama.</p>',
			'post_excerpt' => 'Ringkasan lama.',
			'regular_price' => '10',
			'sync_hash'    => '',
			'variations'   => array(),
		),
	),
);
$text_unchanged = BCS_Sync_Diff::diff(
	array( array( 'sku' => 'TXT-1', 'name' => 'Kemeja Flanel Pria 01', 'description' => '<p>Deskripsi lama.</p>', 'short_description' => 'Ringkasan lama.', 'regular_price' => '10' ) ),
	sync_connection(),
	$text_state
);
expect_sync(
	'unchanged name and description produce no operation',
	! is_wp_error( $text_unchanged ) && 0 === count( $text_unchanged['operations'] )
);
$redescribed = BCS_Sync_Diff::diff(
	array( array( 'sku' => 'TXT-1', 'name' => 'Kemeja Flanel Pria 01', 'description' => '<p>Deskripsi baru.</p>', 'short_description' => 'Ringkasan lama.', 'regular_price' => '10' ) ),
	sync_connection(),
	$text_state
);
expect_sync(
	'a description edit in the sheet is detected as an update',
	! is_wp_error( $redescribed ) && 1 === count( $redescribed['operations'] ) && 'update' === $redescribed['operations'][0]['op']
);
$reshortened = BCS_Sync_Diff::diff(
	array( array( 'sku' => 'TXT-1', 'name' => 'Kemeja Flanel Pria 01', 'description' => '<p>Deskripsi lama.</p>', 'short_description' => 'Ringkasan baru.', 'regular_price' => '10' ) ),
	sync_connection(),
	$text_state
);
expect_sync(
	'a short description edit in the sheet is detected as an update',
	! is_wp_error( $reshortened ) && 1 === count( $reshortened['operations'] ) && 'update' === $reshortened['operations'][0]['op']
);

// Variable parent: WooCommerce keeps no price on the parent, so the parent's
// own price must not be compared. Price is still compared on the variations.
$variable_state = array(
	'catalog_count' => 100,
	'products'      => array(
		'var-parent' => array(
			'id'            => 502,
			'sku'           => 'VAR-PARENT',
			'regular_price' => '',
			'sync_hash'     => '',
			'variations'    => array(
				'var-child' => array( 'id' => 503, 'sku' => 'VAR-CHILD', 'regular_price' => '190000', 'parent_id' => 502 ),
			),
		),
	),
);
$variable_diff = BCS_Sync_Diff::diff(
	array( array( 'sku' => 'VAR-PARENT', 'regular_price' => '190000', 'variations' => array( array( 'sku' => 'VAR-CHILD', 'regular_price' => '190000' ) ) ) ),
	sync_connection(),
	$variable_state
);
expect_sync(
	'variable parent price is not compared against its empty parent value',
	! is_wp_error( $variable_diff ) && 0 === count( $variable_diff['operations'] )
);

// ...but a real price change on the variation itself must still be caught.
$variable_changed = BCS_Sync_Diff::diff(
	array( array( 'sku' => 'VAR-PARENT', 'regular_price' => '190000', 'variations' => array( array( 'sku' => 'VAR-CHILD', 'regular_price' => '250000' ) ) ) ),
	sync_connection(),
	$variable_state
);
expect_sync(
	'a variation price change is still detected',
	! is_wp_error( $variable_changed ) && count( $variable_changed['operations'] ) > 0
);

// Tags must be created in product_tag itself. ensure_term() normalized every
// taxonomy through attribute_taxonomy(), which always prefixes 'pa_', so
// 'product_tag' became 'pa_product_tag': set_tag_ids() then received an id that
// was not a product_tag and tags silently never landed on the product.
$GLOBALS['bcs_inserted_terms'] = array();
$tag_term = BCS_Catalog_Writer::ensure_term( 'product_tag', 'flanel' );
$tag_taxonomies = wp_list_pluck( $GLOBALS['bcs_inserted_terms'], 'taxonomy' );
expect_sync(
	'ensure_term creates a tag in product_tag, not in the pa_ namespace',
	! is_wp_error( $tag_term ) && in_array( 'product_tag', $tag_taxonomies, true ) && ! in_array( 'pa_product_tag', $tag_taxonomies, true )
);

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

/*
 * Regression: the diff and the apply layer must not contradict each other.
 *
 * The apply layer may short-circuit an operation whose source hash still
 * matches the hash stored at the last successful write. That shortcut is only
 * legitimate against duplicate work inside one run; it must never overrule the
 * diff. When a field fails to round-trip into WordPress, the diff reports
 * field_changed on every run while the hash keeps matching, and the operation
 * would be proposed forever and executed never — the run could not converge and
 * the thresholds would trip every time. Stock did exactly that while
 * manage_stock stayed off.
 */
$contradiction_connection = BCS_Sync_Connection::insert( array( 'name' => 'Hash contradiction', 'status' => 'active', 'sheet_config' => array( 'spreadsheet_id' => 'sheet-id', 'gid' => '0' ), 'column_mapping' => array( 'name' => 'name', 'sku' => 'sku', 'regular_price' => 'regular_price' ) ) );
if ( ! is_wp_error( $contradiction_connection ) ) {
	$stale_hash = sha1( 'unchanged-source-row' );

	// (a) field_changed with an identical hash must still be written.
	$drifted = new WC_Product();
	$drifted->set_sku( 'DRIFT-1' );
	$drifted->set_name( 'Drifted item' );
	$drifted->set_regular_price( '100' );
	$drifted->update_meta_data( '_bcs_sync_hash', $stale_hash );
	$drifted_id = $drifted->save();
	$drift_operation = array( 'op' => 'update', 'sku' => 'DRIFT-1', 'variation_sku' => null, 'payload' => array( 'sku' => 'DRIFT-1', 'regular_price' => '250' ), 'before' => array( 'id' => $drifted_id ), 'reason' => 'field_changed', 'sync_hash' => $stale_hash, 'operation_key' => sha1( 'drift-key' ), 'target_product_id' => $drifted_id );
	$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array( 'connection_id' => $contradiction_connection, 'run_trigger' => 'manual', 'state' => 'applying', 'diff_hash' => sha1( 'drift' ), 'diff_summary' => wp_json_encode( array( 'summary' => array(), 'operations' => array( $drift_operation ), 'quarantine' => array(), 'seen_skus' => array() ) ), 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ), 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ) );
	$drift_resume = BCS_Sync_Runner::run( $contradiction_connection, 'manual' );
	expect_sync(
		'field_changed is applied even when the stored sync hash still matches',
		! is_wp_error( $drift_resume ) && '250' === (string) $GLOBALS['bcs_products'][ $drifted_id ]->get_regular_price()
	);

	// (a2) a conflict is WP-side drift, so a matching source hash must not skip it.
	$conflicted = new WC_Product();
	$conflicted->set_sku( 'CONFLICT-1' );
	$conflicted->set_name( 'Conflicted item' );
	$conflicted->set_regular_price( '170000' );
	$conflicted->update_meta_data( '_bcs_sync_hash', $stale_hash );
	$conflicted_id = $conflicted->save();
	$conflict_operation = array( 'op' => 'conflict', 'sku' => 'CONFLICT-1', 'variation_sku' => null, 'payload' => array( 'sku' => 'CONFLICT-1', 'regular_price' => '159000' ), 'before' => array( 'id' => $conflicted_id ), 'reason' => 'manual_edit_conflict', 'sync_hash' => $stale_hash, 'operation_key' => sha1( 'conflict-key' ), 'target_product_id' => $conflicted_id );
	$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array( 'connection_id' => $contradiction_connection, 'run_trigger' => 'manual', 'state' => 'applying', 'diff_hash' => sha1( 'conflict' ), 'diff_summary' => wp_json_encode( array( 'summary' => array(), 'operations' => array( $conflict_operation ), 'quarantine' => array(), 'seen_skus' => array() ) ), 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ), 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ) );
	BCS_Sync_Runner::run( $contradiction_connection, 'manual' );
	expect_sync(
		'a conflict flag is written even when the stored sync hash still matches',
		'' !== (string) $GLOBALS['bcs_products'][ $conflicted_id ]->get_meta( '_bcs_conflict_flag', true, 'edit' )
		&& '170000' === (string) $GLOBALS['bcs_products'][ $conflicted_id ]->get_regular_price()
	);

	// (b) an operation already recorded as successful in this run stays skipped.
	$resumed = new WC_Product();
	$resumed->set_sku( 'RESUME-1' );
	$resumed->set_name( 'Resumed item' );
	$resumed->set_regular_price( '100' );
	$resumed->update_meta_data( '_bcs_sync_hash', $stale_hash );
	$resumed_id = $resumed->save();
	$resume_key = sha1( 'resume-key' );
	$resume_operation = array( 'op' => 'update', 'sku' => 'RESUME-1', 'variation_sku' => null, 'payload' => array( 'sku' => 'RESUME-1', 'regular_price' => '250' ), 'before' => array( 'id' => $resumed_id ), 'reason' => 'field_changed', 'sync_hash' => $stale_hash, 'operation_key' => $resume_key, 'target_product_id' => $resumed_id );
	$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array( 'connection_id' => $contradiction_connection, 'run_trigger' => 'manual', 'state' => 'applying', 'diff_hash' => sha1( 'resume' ), 'diff_summary' => wp_json_encode( array( 'summary' => array(), 'operations' => array( $resume_operation ), 'quarantine' => array(), 'seen_skus' => array() ) ), 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ), 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ) );
	$resume_run_id = $GLOBALS['wpdb']->insert_id;
	BCS_Audit_Logger::log_row( 'bcs/sync-run', 'update_product', 'ok', 0, 'RESUME-1', $resumed_id, 'sync', $resume_key, $resume_run_id, 0, 'sync', 'sync' );
	BCS_Sync_Runner::run( $contradiction_connection, 'manual' );
	expect_sync(
		'an operation already logged ok in this run is not written twice',
		'100' === (string) $GLOBALS['bcs_products'][ $resumed_id ]->get_regular_price()
	);
}

if ( $fail > 0 ) { echo "\n$fail TESTS FAILED\n"; exit( 1 ); }
echo "\nALL SYNC TESTS PASSED\n";
exit( 0 );
