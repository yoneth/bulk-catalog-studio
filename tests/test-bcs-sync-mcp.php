<?php
/**
 * BCS Sync MCP/admin phase 5–7 harness.
 *
 * This is deliberately a small outside-WordPress test: it exercises the
 * public contracts with a deterministic wpdb and catalog-writer stub.
 */

error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return (string) $text; }
function esc_attr( $text ) { return (string) $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function esc_url( $text ) { return (string) $text; }
function esc_url_raw( $text ) { return (string) $text; }
function wp_kses_post( $text ) { return (string) $text; }
function wp_unslash( $text ) { return (string) $text; }
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $text ) ); }
function sanitize_text_field( $text ) { return is_scalar( $text ) ? trim( (string) $text ) : ''; }
function sanitize_textarea_field( $text ) { return is_scalar( $text ) ? trim( (string) $text ) : ''; }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 9; }
function current_user_can( $capability ) { return true; }
function get_option( $name, $default = false ) { return $default; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $expiration = 0 ) { return true; }
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) { $args = array(); }
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}
function number_format_i18n( $number ) { return number_format( (float) $number, 0, '.', ',' ); }
function wp_trim_words( $text, $number = 55, $more = '…' ) { return (string) $text; }
function selected( $selected, $current, $echo = true ) { $value = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ( $echo ) { echo $value; } return $value; }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) { $value = '<input type="hidden" name="' . $name . '" value="nonce" />'; if ( $echo ) { echo $value; } return $value; }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function wp_hash_password( $text ) { return 'H$' . md5( $text ); }
function wp_check_password( $text, $hash, $user_id = 0 ) { return hash_equals( (string) $hash, 'H$' . md5( $text ) ); }
function mysql2date( $format, $mysql, $translate = true ) { return (string) $mysql; }
function wp_die( $message, $status = 0 ) { throw new Exception( (string) $message ); }

class Sync_Mcp_Test_WPDB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $tables = array();
	public $lock_result = 1;

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $arg ) {
			$replacement = null === $arg ? 'NULL' : ( is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'" );
			$sql = preg_replace( '/%[ds]/', $replacement, $sql, 1 );
		}
		return $sql;
	}
	private function rows( $table ) { return isset( $this->tables[ $table ] ) ? $this->tables[ $table ] : array(); }
	private function matches( $row, $where ) {
		foreach ( $where as $key => $value ) {
			if ( null === $value ) {
				if ( isset( $row[ $key ] ) && null !== $row[ $key ] && '' !== $row[ $key ] ) { return false; }
			} elseif ( ! isset( $row[ $key ] ) || (string) $row[ $key ] !== (string) $value ) {
				return false;
			}
		}
		return true;
	}
	private function where_from_sql( $sql ) {
		$where = array();
		if ( preg_match_all( "/(id|connection_id|state)\s*=\s*'?([^'\s]+)'?/i", $sql, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) { $where[ $match[1] ] = trim( $match[2], "'" ); }
		}
		if ( false !== stripos( $sql, 'token_used_at IS NULL' ) ) { $where['token_used_at'] = null; }
		return $where;
	}
	public function insert( $table, $data, $formats = null ) {
		$data['id'] = ++$this->insert_id;
		$this->tables[ $table ][] = $data;
		return 1;
	}
	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$rows = $this->rows( $table ); $affected = 0;
		foreach ( $rows as $index => $row ) {
			if ( ! $this->matches( $row, $where ) ) { continue; }
			foreach ( $data as $key => $value ) { $rows[ $index ][ $key ] = $value; }
			$affected++;
		}
		$this->tables[ $table ] = $rows;
		return $affected;
	}
	public function query( $sql ) {
		if ( false !== stripos( $sql, 'GET_LOCK' ) ) { return $this->lock_result; }
		if ( false !== stripos( $sql, 'RELEASE_LOCK' ) ) { return 1; }
		if ( preg_match( '/UPDATE\s+(\S+)\s+SET\s+(.+?)\s+WHERE\s+(.+)$/i', $sql, $matches ) ) {
			$table = trim( $matches[1], '`' ); $data = array();
			if ( preg_match_all( "/(token_used_at|approved_by|approved_at)\s*=\s*(NULL|'[^']*'|\d+)/i", $matches[2], $sets, PREG_SET_ORDER ) ) {
				foreach ( $sets as $set ) { $data[ $set[1] ] = 'NULL' === strtoupper( $set[2] ) ? null : trim( $set[2], "'" ); }
			}
			return $this->update( $table, $data, $this->where_from_sql( $matches[3] ) );
		}
		return 0;
	}
	public function get_row( $sql ) {
		if ( preg_match( '/FROM\s+(\S+)/i', $sql, $match ) ) {
			$table = trim( $match[1], '`' ); $rows = $this->rows( $table ); $where = $this->where_from_sql( $sql );
			if ( false !== stripos( $sql, 'ORDER BY id DESC' ) ) { $rows = array_reverse( $rows ); }
			foreach ( $rows as $row ) { if ( $this->matches( $row, $where ) ) { return (object) $row; } }
		}
		return null;
	}
	public function get_results( $sql, $mode = null ) {
		if ( ! preg_match( '/FROM\s+(\S+)/i', $sql, $match ) ) { return array(); }
		$table = trim( $match[1], '`' ); $rows = $this->rows( $table ); $where = $this->where_from_sql( $sql );
		if ( false !== stripos( $sql, 'ORDER BY id DESC' ) ) { $rows = array_reverse( $rows ); }
		$out = array();
		foreach ( $rows as $row ) { if ( $this->matches( $row, $where ) ) { $out[] = (object) $row; } }
		return $out;
	}
	public function get_var( $sql ) {
		if ( false !== stripos( $sql, 'GET_LOCK' ) ) { return $this->lock_result; }
		if ( false !== stripos( $sql, 'RELEASE_LOCK' ) ) { return 1; }
		return null;
	}
}
$GLOBALS['wpdb'] = new Sync_Mcp_Test_WPDB();

class BCS_Settings {
	public static function now_mysql() { return gmdate( 'Y-m-d H:i:s' ); }
	public static function get( $key, $default = null ) { return $default; }
}
class BCS_Sync_Connection {
	public static function defaults() { return array( 'name' => '', 'fetcher' => 'csv_export', 'sheet_config' => array(), 'column_mapping' => array(), 'policy' => array(), 'thresholds' => array(), 'interval_minutes' => 1, 'status' => 'paused' ); }
	public static $connections = array( 4 => array( 'id' => 4, 'name' => 'Stub', 'fetcher' => 'csv_export', 'sheet_config' => array( 'spreadsheet_id' => 'sheet', 'gid' => '0' ), 'status' => 'active', 'interval_minutes' => 1, 'policy' => array(), 'thresholds' => array() ) );
	public static function get( $id ) { return isset( self::$connections[ $id ] ) ? self::$connections[ $id ] : null; }
	public static function list_connections( $status = '' ) { return array_values( self::$connections ); }
	public static function update( $id, array $fields ) { return true; }
	public static function update_seen_skus( $id, array $skus ) { return true; }
}
class BCS_Sync_Diff {
	public static function payload_hash( array $payload ) { return sha1( wp_json_encode( $payload ) ); }
}
class BCS_Duplicate_Checker {
	public static function find_by_sku( $sku ) { return 0; }
}
class WC_Product {
	public $id = 0;
	public $props = array();
	public $meta = array();
	public function __construct( $id = 0, $sku = '' ) { $this->id = (int) $id; $this->props['sku'] = $sku; }
	public function get_id() { return $this->id; }
	public function get_sku( $context = 'view' ) { return isset( $this->props['sku'] ) ? $this->props['sku'] : ''; }
	public function get_name( $context = 'view' ) { return isset( $this->props['name'] ) ? $this->props['name'] : ''; }
	public function get_regular_price( $context = 'view' ) { return isset( $this->props['regular_price'] ) ? $this->props['regular_price'] : ''; }
	public function get_sale_price( $context = 'view' ) { return isset( $this->props['sale_price'] ) ? $this->props['sale_price'] : ''; }
	public function get_stock_quantity( $context = 'view' ) { return isset( $this->props['stock_quantity'] ) ? $this->props['stock_quantity'] : null; }
	public function get_weight( $context = 'view' ) { return isset( $this->props['weight'] ) ? $this->props['weight'] : ''; }
	public function get_meta( $key, $single = true, $context = 'view' ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() { return $this->id; }
	public function get_type() { return 'simple'; }
}
$GLOBALS['bcs_wc_products'] = array();
$GLOBALS['bcs_writer_operations'] = array();
function wc_get_product( $id ) { return isset( $GLOBALS['bcs_wc_products'][ (int) $id ] ) ? $GLOBALS['bcs_wc_products'][ (int) $id ] : null; }
class BCS_Catalog_Writer {
	public static function execute_operation( array $op, $job_id, $plan_id ) { $GLOBALS['bcs_writer_operations'][] = $op; return array( 'product_id' => 501, 'variations_created' => 0 ); }
	public static function set_sync_state( $id, $hash, $connection_id, $synced_at = null ) { return true; }
	public static function update_variation( $product_id, $variation_sku, array $payload, array $options = array() ) { return array( 'variation_id' => 502 ); }
	public static function trash_product( $id ) { return true; }
	public static function clear_sync_conflict( $id ) { return true; }
}
class BCS_Audit_Logger {
	public static function log_call() { return true; }
	public static function log_row() { return true; }
}

require dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/includes/class-bcs-schemas.php';
require dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/includes/class-bcs-sync-runner.php';
require dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/includes/class-bcs-abilities.php';

$fail = 0;
function expect_sync_mcp( $label, $condition ) { global $fail; echo ( $condition ? 'PASS' : 'FAIL' ) . " - $label\n"; if ( ! $condition ) { $fail++; } }

/* Ability registration contracts and strict schemas. */
$reflection = new ReflectionMethod( 'BCS_Abilities', 'definitions' );
if ( PHP_VERSION_ID < 80100 ) {
	$reflection->setAccessible( true );
}
$definitions = $reflection->invoke( null );
$expected = array( 'bcs/sync-list-connections', 'bcs/sync-run', 'bcs/sync-approve-run' );
expect_sync_mcp( 'three sync abilities are defined', count( array_intersect( $expected, array_keys( $definitions ) ) ) === 3 );
foreach ( $expected as $ability ) {
	$input = BCS_Schemas::input( $ability ); $output = BCS_Schemas::output( $ability );
	expect_sync_mcp( $ability . ' input schema is strict', isset( $input['additionalProperties'] ) && false === $input['additionalProperties'] );
	expect_sync_mcp( $ability . ' output schema is strict', isset( $output['additionalProperties'] ) && false === $output['additionalProperties'] );
}
expect_sync_mcp( 'sync abilities append after the original eight', array_slice( BCS_Schemas::ability_names(), -3 ) === $expected );

/* Active run is rejected with lock_busy. */
$GLOBALS['wpdb']->lock_result = 0;
$busy = BCS_Abilities::execute_sync_run( array( 'connection_id' => 4, 'reason' => 'manual' ) );
expect_sync_mcp( 'active connection lock returns lock_busy', is_wp_error( $busy ) && 'lock_busy' === $busy->get_error_code() );
$GLOBALS['wpdb']->lock_result = 1;

/* Full token lifecycle. */
$diff_hash = sha1( 'sync-diff' );
$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array( 'connection_id' => 4, 'run_trigger' => 'manual', 'state' => 'awaiting_approval', 'diff_hash' => $diff_hash, 'diff_summary' => wp_json_encode( array( 'summary' => array(), 'quarantine' => array() ) ), 'token_hash' => null, 'token_expires_at' => null, 'token_used_at' => null, 'approved_by' => null, 'approved_at' => null ) );
$run_id = $GLOBALS['wpdb']->insert_id;
$token = BCS_Sync_Runner::generate_run_token( $run_id );
expect_sync_mcp( 'run token is generated in BCS format', is_string( $token ) && 12 === strlen( $token ) && 1 === preg_match( '/^BCS-[A-Z2-9]{8}$/', $token ) );
expect_sync_mcp( 'wrong run token is rejected', is_wp_error( BCS_Sync_Runner::verify_run_token( $run_id, $diff_hash, 'BCS-ABCDEFG' ) ) );
expect_sync_mcp( 'correct run token verifies', true === BCS_Sync_Runner::verify_run_token( $run_id, $diff_hash, $token ) );
$approved = BCS_Abilities::execute_sync_approve_run( array( 'run_id' => $run_id, 'diff_hash' => $diff_hash, 'confirmation_token' => $token ) );
expect_sync_mcp( 'token approval transitions the run', ! is_wp_error( $approved ) && 'approved' === $approved['status'] );
$replay = BCS_Sync_Runner::approve_run( $run_id, $diff_hash, $token, 9 );
expect_sync_mcp( 'replay is rejected as token_used', is_wp_error( $replay ) && 'bcs_token_used' === $replay->get_error_code() );

$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array( 'connection_id' => 4, 'run_trigger' => 'manual', 'state' => 'awaiting_approval', 'diff_hash' => $diff_hash, 'diff_summary' => '{}' , 'token_hash' => null, 'token_expires_at' => null, 'token_used_at' => null ) );
$wrong_hash_run = $GLOBALS['wpdb']->insert_id;
$wrong_token = BCS_Sync_Runner::generate_run_token( $wrong_hash_run );
expect_sync_mcp( 'wrong diff_hash is rejected explicitly', is_wp_error( BCS_Sync_Runner::verify_run_token( $wrong_hash_run, sha1( 'other' ), $wrong_token ) ) && 'diff_hash_mismatch' === BCS_Sync_Runner::verify_run_token( $wrong_hash_run, sha1( 'other' ), $wrong_token )->get_error_code() );

/* Quarantine apply and discard both mark the persisted item resolved. */
$quarantine = array(
	'summary' => array( 'quarantined' => 2 ),
	'quarantine' => array(
		array( 'sku' => 'BAD-1', 'reason' => 'price_delta_exceeded', 'payload' => array( 'sku' => 'BAD-1', 'name' => 'One' ), 'before' => array( 'id' => 401 ) ),
		array( 'sku' => 'BAD-2', 'reason' => 'price_delta_exceeded', 'payload' => array( 'sku' => 'BAD-2', 'name' => 'Two' ), 'before' => array( 'id' => 402 ) ),
	),
);
$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array( 'connection_id' => 4, 'run_trigger' => 'manual', 'state' => 'done', 'diff_hash' => sha1( 'q' ), 'diff_summary' => wp_json_encode( $quarantine ) ) );
$qrun = $GLOBALS['wpdb']->insert_id;
$discard = BCS_Sync_Runner::resolve_quarantine( $qrun, 'BAD-1', 'discard' );
$apply   = BCS_Sync_Runner::resolve_quarantine( $qrun, 'BAD-2', 'apply' );
$stored  = BCS_Sync_Runner::get_run( $qrun );
$stored_diff = json_decode( $stored['diff_summary'], true );
expect_sync_mcp( 'discard quarantine item is resolved', ! is_wp_error( $discard ) && ! empty( $stored_diff['quarantine'][0]['resolved'] ) );
expect_sync_mcp( 'apply quarantine item is resolved', ! is_wp_error( $apply ) && ! empty( $stored_diff['quarantine'][1]['resolved'] ) );

/* Conflict resolution uses the persisted source payload for Sheet wins. */
$conflict_product = new WC_Product( 701, 'CONFLICT-1' );
$conflict_product->props['name'] = 'WP value';
$conflict_product->meta['_bcs_connection_id'] = 4;
$conflict_product->meta['_bcs_conflict_flag'] = '2026-01-01 00:00:00';
$GLOBALS['bcs_wc_products'][701] = $conflict_product;
$source_payload = array( 'sku' => 'CONFLICT-1', 'name' => 'Sheet value', 'regular_price' => '99' );
$source_operation = array( 'op' => 'conflict', 'sku' => 'CONFLICT-1', 'variation_sku' => null, 'payload' => $source_payload, 'before' => array( 'id' => 701 ), 'reason' => 'manual_edit_conflict', 'sync_hash' => sha1( wp_json_encode( $source_payload ) ), 'target_product_id' => 701 );
$GLOBALS['wpdb']->insert( 'wp_bcs_sync_runs', array( 'connection_id' => 4, 'run_trigger' => 'manual', 'state' => 'done', 'diff_hash' => sha1( 'conflict-run' ), 'diff_summary' => wp_json_encode( array( 'operations' => array( $source_operation ), 'summary' => array( 'conflict' => 1 ) ) ) ) );
$conflict_result = BCS_Sync_Runner::resolve_conflict( 701, 'sheet_wins' );
$captured_operation = end( $GLOBALS['bcs_writer_operations'] );
expect_sync_mcp( 'Sheet wins reapplies the persisted conflict payload', ! is_wp_error( $conflict_result ) && is_array( $captured_operation ) && 'Sheet value' === $captured_operation['payload']['name'] );
$keep_result = BCS_Sync_Runner::resolve_conflict( 701, 'keep_wp' );
expect_sync_mcp( 'Keep WP version resolves the conflict without a source write', ! is_wp_error( $keep_result ) && 'keep_wp' === $keep_result['resolution'] );

/* Admin render smoke: all four tab labels and a table render without fatal. */
require dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/admin/class-bcs-admin.php';
$_GET = array( 'view' => 'connections' );
ob_start();
BCS_Sync_Admin::render();
$admin_html = ob_get_clean();
expect_sync_mcp( 'admin smoke renders Connections tab and table', false !== strpos( $admin_html, 'Connections' ) && false !== strpos( $admin_html, '<table' ) );
$_GET = array( 'view' => 'runs' );
ob_start();
BCS_Sync_Admin::render();
$runs_html = ob_get_clean();
expect_sync_mcp( 'admin smoke renders Runs tab', false !== strpos( $runs_html, 'Runs' ) && false !== strpos( $runs_html, '<table' ) );
$_GET = array( 'view' => 'quarantine' );
ob_start();
BCS_Sync_Admin::render();
$quarantine_html = ob_get_clean();
expect_sync_mcp( 'admin smoke renders Quarantine tab', false !== strpos( $quarantine_html, 'Quarantine' ) && false !== strpos( $quarantine_html, '<table' ) );
$_GET = array( 'view' => 'conflicts' );
ob_start();
BCS_Sync_Admin::render();
$conflicts_html = ob_get_clean();
expect_sync_mcp( 'admin smoke renders Conflicts tab', false !== strpos( $conflicts_html, 'Conflicts' ) && false !== strpos( $conflicts_html, '<table' ) );

if ( $fail > 0 ) { echo "\n$fail TESTS FAILED\n"; exit( 1 ); }
echo "\nALL SYNC MCP TESTS PASSED\n";
exit( 0 );
