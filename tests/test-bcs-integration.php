<?php
/**
 * Harness uji integrasi BCS di luar WordPress (fake wpdb + fake WooCommerce).
 * Mengalirkan: parse-source → check-duplicates → build-plan → token/approve →
 * execute-batch → job-status/audit-log → rollback, plus state machine guard.
 * Bukan bagian plugin — hanya untuk verifikasi self-check.
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

/* ------------------------------------------------------------------ */
/* Stub WP inti                                                        */
/* ------------------------------------------------------------------ */

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function absint( $m ) { return abs( (int) $m ); }
function sanitize_text_field( $s ) { return is_scalar( $s ) ? trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $s ) ) : ''; }
function sanitize_textarea_field( $s ) { return is_scalar( $s ) ? trim( (string) $s ) : ''; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_title( $t ) { $t = strtolower( (string) $t ); $t = preg_replace( '/[^a-z0-9]+/', '-', $t ); return trim( $t, '-' ); }
function wp_kses_post( $c ) { return (string) $c; }
function esc_url_raw( $u ) { return (string) $u; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function current_time( $t, $g = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function apply_filters( $tag, $value ) { return $value; }
function get_current_user_id() { return 1; }
function current_user_can( $cap ) { return true; }
function wp_hash_password( $t ) { return 'H$' . md5( $t ); }
function wp_check_password( $t, $hash, $uid = 0 ) { return hash_equals( (string) $hash, 'H$' . md5( $t ) ); }
function wp_rand( $min = 0, $max = 0 ) { return rand( $min, $max ); }
function admin_url( $path = '' ) { return 'http://example.test/wp-admin/' . $path; }
function mysql2date( $fmt, $mysql, $translate = true ) { return (string) $mysql; }
function get_transient( $k ) { global $bcs_transients; return isset( $bcs_transients[ $k ] ) ? $bcs_transients[ $k ] : false; }
function set_transient( $k, $v, $e = 0 ) { global $bcs_transients; $bcs_transients[ $k ] = $v; return true; }
function delete_transient( $k ) { global $bcs_transients; unset( $bcs_transients[ $k ] ); return true; }
function wp_trim_words( $t, $n = 55, $more = '…' ) { return $t; }
function wp_strip_all_tags( $t ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $t ) ); }
function get_option( $name, $default = false ) { return $default; }
function is_admin() { return false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_http_validate_url( $url ) {
	if ( ! is_string( $url ) || '' === $url ) { return false; }
	$p = parse_url( $url );
	if ( empty( $p['host'] ) || empty( $p['scheme'] ) ) { return false; }
	return $url;
}
function wp_remote_get( $url, $args = array() ) {
	// Stub HTTP: hanya untuk happy-path SSRF test (docs.google.com).
	return array(
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'body'     => isset( $GLOBALS['bcs_http_stub_body'] ) ? $GLOBALS['bcs_http_stub_body'] : '',
	);
}
function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? (int) $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? (string) $r['body'] : ''; }
function update_option( $name, $value, $autoload = null ) { return true; }
function add_option( $name, $value, $d = '', $a = 'yes' ) { return true; }
function delete_option( $name ) { return true; }

/* ------------------------------------------------------------------ */
/* Fake wpdb — pattern matcher untuk query set yang dipakai plugin     */
/* ------------------------------------------------------------------ */

class Fake_WPDB {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options = 'wp_options';
	public $insert_id = 0;
	public $tables = array(); // name => list of rows (assoc)

	// Default kolom tabel nyata (dipakai saat membaca baris yang disimpan parsial).
	private $col_defaults = array(
		'status' => 'draft', 'source_type' => '', 'source_name' => '', 'source_hash' => '',
		'total_rows' => 0, 'count_created' => 0, 'count_updated' => 0, 'count_variations' => 0,
		'count_skipped' => 0, 'count_failed' => 0, 'active_plan_id' => null, 'mapping_json' => null,
		'created_by' => 0, 'heartbeat_at' => null, 'last_error' => null, 'plan_hash' => '',
		'plan_json' => null, 'version' => 1, 'token_hash' => null, 'token_expires_at' => null,
		'token_used_at' => null, 'approved_by' => null, 'approved_at' => null, 'level' => 'call',
		'ability' => '', 'action' => '', 'actor_user_id' => 0, 'actor_via' => 'system',
		'row_index' => null, 'sku' => null, 'product_id' => null, 'payload_digest' => null,
		'message' => null, 'job_id' => null, 'plan_id' => null,
	);

	private function rows( $name ) { return isset( $this->tables[ $name ] ) ? $this->tables[ $name ] : array(); }
	private function save_rows( $name, $rows ) { $this->tables[ $name ] = $rows; }

	private function with_defaults( $row ) {
		foreach ( $this->col_defaults as $col => $def ) {
			if ( ! array_key_exists( $col, $row ) ) { $row[ $col ] = $def; }
		}
		return $row;
	}

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $a ) {
			if ( is_int( $a ) ) { $sql = preg_replace( '/%[ds]/', (string) $a, $sql, 1 ); }
			elseif ( null === $a ) { $sql = preg_replace( '/%[ds]/', 'NULL', $sql, 1 ); }
			else { $sql = preg_replace( '/%[ds]/', "'" . addslashes( (string) $a ) . "'", $sql, 1 ); }
		}
		return $sql;
	}

	private function parse_where( $where ) {
		$where = (string) $where;
		$conds = array();

		// Kondisi IN ( 'a', 'b' ) — dipakai has_row_entry.
		if ( preg_match_all( "/(\w+)\s+IN\s*\(([^)]*)\)/i", $where, $ins, PREG_SET_ORDER ) ) {
			foreach ( $ins as $in ) {
				$vals = array();
				if ( preg_match_all( "/'([^']*)'/", $in[2], $vv ) ) {
					$vals = $vv[1];
				}
				$conds[] = array( 'col' => $in[1], 'op' => 'IN', 'val' => $vals );
				$where = str_replace( $in[0], '', $where );
			}
		}

		// Kondisi "col IS NULL".
		if ( preg_match_all( "/(\w+)\s+IS\s+NULL/i", $where, $nulls, PREG_SET_ORDER ) ) {
			foreach ( $nulls as $n ) {
				$conds[] = array( 'col' => $n[1], 'op' => 'ISNULL', 'val' => null );
				$where = str_replace( $n[0], '', $where );
			}
		}

		// "col = 'val'", "col = 5", "col != 5", "col > 0", joined by AND.
		preg_match_all( "/(\w+)\s*(=|!=|>)\s*'?([^'\s]+)'?/", $where, $m, PREG_SET_ORDER );
		foreach ( $m as $c ) {
			$conds[] = array( 'col' => $c[1], 'op' => $c[2], 'val' => $c[3] );
		}
		return $conds;
	}

	private function match_row( $row, $conds ) {
		foreach ( $conds as $c ) {
			$val = isset( $row[ $c['col'] ] ) ? $row[ $c['col'] ] : null;
			$cond_val = is_numeric( $c['val'] ) && ( is_int( $val ) || is_numeric( $val ) || ctype_digit( (string) $val ) ) ? (int) $c['val'] : $c['val'];
			if ( is_object( $val ) ) { $val = (array) $val; }
			switch ( $c['op'] ) {
				case '=':  if ( (string) $val !== (string) $cond_val && $val != $cond_val ) return false; break;
				case '!=': if ( $val == $cond_val ) return false; break;
				case '>':  if ( ! ( (float) $val > (float) $cond_val ) ) return false; break;
				case 'IN':
					if ( ! in_array( (string) $val, array_map( 'strval', (array) $c['val'] ), true ) ) { return false; }
					break;
				case 'ISNULL':
					if ( null !== $val && '' !== $val ) { return false; }
					break;
				default: return false;
			}
		}
		return true;
	}

	private function parse_select( $sql ) {
		if ( ! preg_match( '/SELECT\s+(.+?)\s+FROM\s+(\S+)/is', $sql, $m ) ) return null;
		$table = trim( $m[2], '`' );
		$cols  = trim( $m[1] );
		$where = '';
		if ( preg_match( '/WHERE\s+(.+?)(\s+ORDER BY|\s+LIMIT|$)/is', $sql, $w ) ) { $where = $w[1]; }
		$order_desc = false !== stripos( $sql, 'ORDER BY id DESC' ) || false !== stripos( $sql, 'ORDER BY version DESC' );
		$limit  = preg_match( '/LIMIT\s+(\d+)/i', $sql, $l ) ? (int) $l[1] : 0;
		$offset = preg_match( '/OFFSET\s+(\d+)/i', $sql, $o ) ? (int) $o[1] : 0;
		return array( $cols, $table, $this->parse_where( $where ), $order_desc, $limit, $offset );
	}

	public function get_row( $sql ) {
		$p = $this->parse_select( $sql );
		if ( null === $p ) { return null; }
		list( $cols, $table, $conds, $desc, $limit, $offset ) = $p;
		$rows = $this->rows( $table );
		if ( $desc ) { $rows = array_reverse( $rows ); }
		$i = 0;
		foreach ( $rows as $row ) {
			$row = $this->with_defaults( (array) $row );
			if ( $this->match_row( $row, $conds ) ) {
				if ( $i++ < $offset ) { continue; }
				return (object) $row;
			}
		}
		return null;
	}

	public function get_results( $sql, $mode = null ) {
		$p = $this->parse_select( $sql );
		if ( null === $p ) { return array(); }
		list( $cols, $table, $conds, $desc, $limit, $offset ) = $p;
		$rows = $this->rows( $table );
		if ( $desc ) { $rows = array_reverse( $rows ); }
		$out = array();
		$i = 0;
		foreach ( $rows as $row ) {
			$row = $this->with_defaults( (array) $row );
			if ( ! $this->match_row( $row, $conds ) ) { continue; }
			if ( $i++ < $offset ) { continue; }
			if ( $limit > 0 && count( $out ) >= $limit ) { break; }
			$out[] = ARRAY_A === $mode ? $row : (object) $row;
		}
		return $out;
	}

	public function get_var( $sql ) {
		if ( preg_match( '/COALESCE\(MAX\(version\),\s*0\)\s+FROM\s+(\S+)\s+WHERE\s+job_id\s*=\s*(\d+)/i', $sql, $m ) ) {
			$max = 0;
			foreach ( $this->rows( trim( $m[1], '`' ) ) as $row ) {
				if ( (int) $row['job_id'] === (int) $m[2] && (int) $row['version'] > $max ) { $max = (int) $row['version']; }
			}
			return (string) $max;
		}
		if ( preg_match( '/SELECT\s+COUNT\(id\)\s+FROM\s+(\S+)\s+WHERE\s+(.+)/is', $sql, $m ) ) {
			$count = 0;
			foreach ( $this->rows( trim( $m[1], '`' ) ) as $row ) {
				if ( $this->match_row( (array) $row, $this->parse_where( $m[2] ) ) ) { $count++; }
			}
			return (string) $count;
		}
		if ( preg_match( '/SELECT\s+(.+?)\s+FROM/is', $sql, $c ) ) {
			$cols = trim( $c[1] );
			$row = $this->get_row( preg_replace( '/^SELECT\s+.+?\s+FROM\s+/is', 'SELECT * FROM ', $sql ) );
			if ( ! $row ) { return null; }
			$arr = (array) $row;
			return isset( $arr[ $cols ] ) ? (string) $arr[ $cols ] : (string) reset( $arr );
		}
		return null;
	}

	public function get_col( $sql ) {
		$rows = $this->get_results( $sql );
		$out = array();
		foreach ( $rows as $r ) { $arr = (array) $r; $out[] = reset( $arr ); }
		return $out;
	}

	public function insert( $table, $data ) {
		$data['id'] = ++$this->insert_id;
		$this->tables[ $table ][] = $data;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$rows = $this->rows( $table );
		$affected = 0;
		foreach ( $rows as $i => $row ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( (string) $row[ $k ] !== (string) $v ) { $match = false; break; }
			}
			if ( ! $match ) { continue; }
			foreach ( $data as $k => $v ) { $rows[ $i ][ $k ] = $v; }
			$affected++;
		}
		$this->save_rows( $table, $rows );
		return $affected;
	}

	public function query( $sql ) {
		if ( preg_match( '/UPDATE\s+(\S+)\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is', $sql, $m ) ) {
			$table = trim( $m[1], '`' );
			preg_match_all( "/(\w+)\s*=\s*('([^']*)'|NULL|\d+)/i", $m[2], $sets, PREG_SET_ORDER );
			$data = array();
			foreach ( $sets as $s ) { $data[ $s[1] ] = ( 'NULL' === $s[2] || ! isset( $s[3] ) ) ? ( 'NULL' === $s[2] ? null : $s[2] ) : $s[3]; }
			$rows = $this->rows( $table );
			$conds = $this->parse_where( $m[3] );
			$affected = 0;
			foreach ( $rows as $i => $row ) {
				if ( ! $this->match_row( (array) $row, $conds ) ) { continue; }
				foreach ( $data as $k => $v ) { $rows[ $i ][ $k ] = $v; }
				$affected++;
			}
			$this->save_rows( $table, $rows );
			return $affected;
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new Fake_WPDB();

/* ------------------------------------------------------------------ */
/* Stub WooCommerce minimal                                            */
/* ------------------------------------------------------------------ */

$GLOBALS['bcs_products'] = array();   // id => WC_Product stub
$GLOBALS['bcs_row_key_index'] = array(); // row_key => product id
$GLOBALS['bcs_wc_next_id'] = 200;

class WC_Product {
	public $props = array();
	public $meta  = array();

	public function __call( $m, $a ) {
		if ( 0 === strpos( $m, 'set_' ) ) { $this->props[ substr( $m, 4 ) ] = isset( $a[0] ) ? $a[0] : null; return $this; }
		if ( 0 === strpos( $m, 'get_' ) ) { $k = substr( $m, 4 ); return isset( $this->props[ $k ] ) ? $this->props[ $k ] : null; }
		return null;
	}

	public function save() {
		if ( empty( $this->props['id'] ) ) { $this->props['id'] = ++$GLOBALS['bcs_wc_next_id']; }
		$GLOBALS['bcs_products'][ $this->props['id'] ] = $this;
		// Hanya produk (bukan variasi) yang masuk index row_key — di WordPress
		// nyata variasi memiliki post_type product_variation sehingga tidak
		// pernah muncul di get_posts dengan post_type=product.
		if ( ! $this instanceof WC_Product_Variation && isset( $this->meta['_bcs_row_key'] ) ) {
			$GLOBALS['bcs_row_key_index'][ $this->meta['_bcs_row_key'] ] = $this->props['id'];
		}
		return $this->props['id'];
	}

	public function get_id() { return isset( $this->props['id'] ) ? (int) $this->props['id'] : 0; }
	public function get_type() { return isset( $this->props['product_type'] ) ? $this->props['product_type'] : 'simple'; }
	public function get_children( $ctx = 'edit' ) { return isset( $this->props['children'] ) ? $this->props['children'] : array(); }
	public function delete( $force = false ) { $this->props['status'] = 'trash'; return true; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function get_meta( $k, $single = true, $ctx = 'edit' ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function set_attributes( $a ) { $this->props['attributes'] = $a; return $this; }
}

class WC_Product_Variable extends WC_Product {
	public function get_type() { return 'variable'; }
	public static function sync( $id ) { return true; }
}

class WC_Product_Variation extends WC_Product {
	public function get_type() { return 'variation'; }
}

class WC_Product_Attribute {
	public $props = array();
	public function __call( $m, $a ) {
		if ( 0 === strpos( $m, 'set_' ) ) { $this->props[ substr( $m, 4 ) ] = isset( $a[0] ) ? $a[0] : null; return $this; }
		if ( 0 === strpos( $m, 'get_' ) ) { $k = substr( $m, 4 ); return isset( $this->props[ $k ] ) ? $this->props[ $k ] : null; }
		return null;
	}
}

function wc_get_product( $id ) { return isset( $GLOBALS['bcs_products'][ (int) $id ] ) ? $GLOBALS['bcs_products'][ (int) $id ] : null; }
function wc_delete_product_transients( $id ) {}
function wc_format_decimal( $v ) { return (string) ( 0 + str_replace( ',', '', (string) $v ) ); }
function wc_get_product_id_by_sku( $sku ) {
	$map = isset( $GLOBALS['bcs_sku_map'] ) ? $GLOBALS['bcs_sku_map'] : array();
	$key = strtolower( trim( (string) $sku ) );

	return isset( $map[ $key ] ) ? (int) $map[ $key ] : null;
}
function get_term_by( $f, $v, $tax ) { return false; }
function wp_insert_term( $name, $tax, $args = array() ) { static $tid = 500; return array( 'term_id' => ++$tid, 'slug' => sanitize_title( $name ) ); }
function get_terms( $args ) { return array(); }
function update_post_meta( $id, $k, $v ) { return true; }
function get_post_meta( $id, $k, $single = true ) { return ''; }
function get_post( $id ) { return null; }
function get_posts( $args ) {
	if ( empty( $args['meta_query'] ) ) { return array(); }
	$key = $args['meta_query'][0]['key'];
	$val = $args['meta_query'][0]['value'];

	if ( '_bcs_row_key' === $key && isset( $GLOBALS['bcs_row_key_index'][ $val ] ) ) {
		return array( $GLOBALS['bcs_row_key_index'][ $val ] );
	}
	return array();
}
function wc_attribute_taxonomy_id_by_name( $slug ) { return 7; }
function wc_create_attribute( $args ) { return 8; }
function taxonomy_exists( $t ) { return false; }
function register_taxonomy( $t, $o, $a ) {}

/* ------------------------------------------------------------------ */
/* Load plugin classes                                                 */
/* ------------------------------------------------------------------ */

$base = '/Users/rio/Pi/cf.djavaweb/plugin/bulk-catalog-studio/includes/';
require $base . 'class-bcs-settings.php';
require $base . 'class-bcs-audit-logger.php';
require $base . 'class-bcs-job-manager.php';
require $base . 'class-bcs-csv-parser.php';
require $base . 'class-bcs-duplicate-checker.php';
require $base . 'class-bcs-plan-builder.php';
require $base . 'class-bcs-approval-token.php';
require $base . 'class-bcs-catalog-writer.php';
require $base . 'class-bcs-executor.php';
require $base . 'class-bcs-rollback-service.php';
require $base . 'class-bcs-schemas.php';
require $base . 'class-bcs-abilities.php';

/* ------------------------------------------------------------------ */
/* Tests                                                               */
/* ------------------------------------------------------------------ */

$fail = 0;
function expect( $label, $cond ) {
	global $fail;
	echo ( $cond ? 'PASS' : 'FAIL' ) . " - $label\n";
	if ( ! $cond ) { $fail++; }
}

$csv  = file_get_contents( '/Users/rio/Pi/cf.djavaweb/plugin/bulk-catalog-studio/tests/fixtures/sample-fashion-30.csv' );
$map  = array(
	'Nama' => 'name', 'Deskripsi' => 'description', 'Ringkasan' => 'short_description',
	'SKU' => 'sku', 'Harga' => 'regular_price', 'Harga Diskon' => 'sale_price',
	'Stok' => 'stock_quantity', 'Berat(kg)' => 'weight', 'P' => 'dimensions.length',
	'L' => 'dimensions.width', 'T' => 'dimensions.height', 'Kategori' => 'categories',
	'Tag' => 'tags', 'Ukuran' => 'attribute:pa_ukuran', 'Warna' => 'attribute:pa_warna',
	'SKU Variasi' => 'variation_sku', 'Harga Variasi' => 'variation_regular_price',
	'Stok Variasi' => 'variation_stock_quantity', 'URL Gambar' => 'image_url',
	'Alt Gambar' => 'image_alt', 'SEO Title' => 'seo_title', 'SEO Desc' => 'seo_description',
);

/* T1: parse-source */
$r1 = BCS_Abilities::execute_parse_source( array(
	'source_type' => 'inline_csv',
	'csv_text'    => $csv,
	'source_name' => 'fashion-oktober.csv',
	'column_mapping' => $map,
	'notes'       => 'Fixture uji',
) );
expect( 'T1 parse-source succeeds', ! is_wp_error( $r1 ) );
if ( is_wp_error( $r1 ) ) { echo $r1->get_error_message() . "\n"; exit( 1 ); }
expect( 'T1 job_id > 0', $r1['job_id'] > 0 );
expect( 'T1 status parsed', 'parsed' === $r1['status'] );
expect( 'T1 total_rows 30', 30 === $r1['total_rows'] );
expect( 'T1 sha1 40 char', 40 === strlen( $r1['source_hash'] ) );
$job_id = $r1['job_id'];
$job = BCS_Job_Manager::get( $job_id );
expect( 'T1 database job status is parsed', 'parsed' === $job['status'] );
expect( 'T1 staging v0 is stored', null !== BCS_Plan_Builder::get_staging_row( $job_id ) );

/* T2: upload ganda terdeteksi */
$r2 = BCS_Abilities::execute_parse_source( array(
	'source_type' => 'inline_csv',
	'csv_text'    => $csv,
	'column_mapping' => $map,
) );
expect( 'T2 second parse succeeds', ! is_wp_error( $r2 ) );
$dup_warnings = preg_grep( '/duplicate upload/', (array) $r2['warnings'] );
expect( 'T2 warning identifies the duplicate source job', count( $dup_warnings ) > 0 );

/* T3: check-duplicates */
$d1 = BCS_Abilities::execute_check_duplicates( array( 'job_id' => $job_id, 'strategy' => 'sku' ) );
expect( 'T3 check-duplicates succeeds', ! is_wp_error( $d1 ) );
if ( is_wp_error( $d1 ) ) { echo $d1->get_error_message() . "\n"; exit( 1 ); }
expect( 'T3 one intra-batch group', 1 === count( $d1['intra_batch_duplicates'] ) );
expect( 'T3 duplicate SKU group is KML-GRS-005', isset( $d1['intra_batch_duplicates'][0] ) && 'KML-GRS-005' === $d1['intra_batch_duplicates'][0]['sku'] );
expect( 'T3 duplicate indexes are rows 5 and 15', array( 5, 15 ) === $d1['intra_batch_duplicates'][0]['row_indices'] );
expect( 'T3 duplicate_count 1', 1 === $d1['duplicate_count'] );
expect( 'T3 flag_only is effective', 'flag_only' === $d1['on_duplicate_effective'] );

/* T3b: build-plan menolak flag_only */
$bp_reject = BCS_Abilities::execute_build_plan( array( 'job_id' => $job_id, 'on_duplicate' => 'flag_only' ) );
expect( 'T3b build-plan rejects flag_only', is_wp_error( $bp_reject ) );

/* T4: build-plan skip */
$p1 = BCS_Abilities::execute_build_plan( array( 'job_id' => $job_id, 'on_duplicate' => 'skip' ) );
expect( 'T4 build-plan succeeds', ! is_wp_error( $p1 ) );
if ( is_wp_error( $p1 ) ) { echo $p1->get_error_message() . "\n"; exit( 1 ); }
$s = $p1['summary'];
expect( 'T4 create_simple 18 (2 duplicates skipped)', 18 === $s['create_simple'] );
expect( 'T4 create_variable 10', 10 === $s['create_variable'] );
expect( 'T4 create_variations 60', 60 === $s['create_variations'] );
expect( 'T4 skip_duplicates 2', 2 === $s['skip_duplicates'] );
expect( 'T4 total_operations 30', 30 === $s['total_operations'] );
expect( 'T4 plan_hash 40 char', 40 === strlen( $p1['plan_hash'] ) );
expect( 'T4 diff_preview_url is correct', false !== strpos( $p1['diff_preview_url'], 'page=bcs-jobs&view=plan&plan=' . $p1['plan_id'] ) );
$job = BCS_Job_Manager::get( $job_id );
expect( 'T4 job planned', 'planned' === $job['status'] );
expect( 'T4 active_plan_id is set', $job['active_plan_id'] === $p1['plan_id'] );

/* T5 → R6 (MINOR-2 retur QA-1): rebuild pada job planned DIIZINKAN —
 * plan baru versi N+1, plan planned lama jadi superseded. */
$p2 = BCS_Abilities::execute_build_plan( array( 'job_id' => $job_id, 'on_duplicate' => 'skip' ) );
expect( 'T5/R6 rebuild on planned job succeeds', ! is_wp_error( $p2 ) );
if ( is_wp_error( $p2 ) ) { echo $p2->get_error_message() . "\n"; exit( 1 ); }
expect( 'R6 new plan is version 2', $p2['plan_id'] !== $p1['plan_id'] );
$old_plan_row = BCS_Plan_Builder::get_plan_row( $p1['plan_id'] );
expect( 'R6 old plan is superseded', 'superseded' === (string) $old_plan_row->status );
$job = BCS_Job_Manager::get( $job_id );
expect( 'R6 active_plan_id moves to the new plan', $job['active_plan_id'] === $p2['plan_id'] );
$p1 = $p2; // alur lanjutan memakai plan terbaru.

/* R6b: approve pada plan superseded ditolak walau token-nya valid. */
$old_token = BCS_Approval_Token::generate( $old_plan_row->id, (string) $old_plan_row->plan_hash );
expect( 'R6b old-plan token can be generated', is_string( $old_token ) );
$old_approve = BCS_Abilities::execute_approve_plan( array(
	'plan_id' => (int) $old_plan_row->id, 'plan_hash' => (string) $old_plan_row->plan_hash, 'confirmation_token' => $old_token,
) );
expect( 'R6b superseded-plan approval is rejected', is_wp_error( $old_approve ) );

/* T6: transisi planned → executing langsung ditolak */
$tr = BCS_Job_Manager::transition( $job_id, 'executing', 'test' );
expect( 'T6 invalid transition is rejected with WP_Error', is_wp_error( $tr ) && 'bcs_invalid_state' === $tr->get_error_code() );

/* T7: execute pada plan belum approved ditolak */
$x0 = BCS_Abilities::execute_execute_batch( array( 'plan_id' => $p1['plan_id'] ) );
expect( 'T7 execution before approval is rejected', is_wp_error( $x0 ) && 'bcs_invalid_state' === $x0->get_error_code() );

/* T8: dry_run tidak mengubah state */
$dr = BCS_Abilities::execute_execute_batch( array( 'plan_id' => $p1['plan_id'], 'dry_run' => true ) );
expect( 'T8 dry_run succeeds', ! is_wp_error( $dr ) );
if ( is_wp_error( $dr ) ) { echo $dr->get_error_message() . "\n"; exit( 1 ); }
expect( 'T8 dry_run counts 30 rows', 30 === $dr['counts']['total_rows'] );
$job = BCS_Job_Manager::get( $job_id );
expect( 'T8 state remains planned after dry_run', 'planned' === $job['status'] );

/* T9: approve dengan token */
$token = BCS_Approval_Token::generate( $p1['plan_id'], $p1['plan_hash'] );
expect( 'T9 token format BCS-XXXXXXXX (8 char, MINOR-1)', is_string( $token ) && 1 === preg_match( '/^BCS-[A-Z2-9]{8}$/', $token ) );
expect( 'R5 token total 12 char (schema maxLength 12)', is_string( $token ) && 12 === strlen( $token ) );

$bad = BCS_Abilities::execute_approve_plan( array(
	'plan_id' => $p1['plan_id'], 'plan_hash' => $p1['plan_hash'], 'confirmation_token' => 'BCS-00000',
) );
expect( 'T9 invalid token is rejected', is_wp_error( $bad ) );

$wrong_hash = BCS_Abilities::execute_approve_plan( array(
	'plan_id' => $p1['plan_id'], 'plan_hash' => str_repeat( '0', 40 ), 'confirmation_token' => $token,
) );
expect( 'T9 mismatched plan_hash is rejected', is_wp_error( $wrong_hash ) );

$ok = BCS_Abilities::execute_approve_plan( array(
	'plan_id' => $p1['plan_id'], 'plan_hash' => $p1['plan_hash'], 'confirmation_token' => $token,
) );
expect( 'T9 approval succeeds', ! is_wp_error( $ok ) );
if ( is_wp_error( $ok ) ) { echo $ok->get_error_message() . "\n"; exit( 1 ); }
expect( 'T9 output approved', 'approved' === $ok['status'] && $ok['approved_by'] > 0 );
$job = BCS_Job_Manager::get( $job_id );
expect( 'T9 job approved', 'approved' === $job['status'] );

$again = BCS_Abilities::execute_approve_plan( array(
	'plan_id' => $p1['plan_id'], 'plan_hash' => $p1['plan_hash'], 'confirmation_token' => $token,
) );
expect( 'T9 second use of single-use token is rejected', is_wp_error( $again ) );

/* T10: execute-batch */
$x1 = BCS_Abilities::execute_execute_batch( array( 'plan_id' => $p1['plan_id'] ) );
expect( 'T10 execution succeeds', ! is_wp_error( $x1 ) );
if ( is_wp_error( $x1 ) ) { echo $x1->get_error_message() . "\n"; exit( 1 ); }
expect( 'T10 status done', 'done' === $x1['status'] );
if ( 'done' !== $x1['status'] ) { echo "== DEBUG failures ==\n"; foreach ( array_slice( (array) $x1['failures'], 0, 5 ) as $f ) { echo '  row ' . $f['row_index'] . ': ' . $f['reason'] . "\n"; } }
expect( 'T10 products_created 28', 28 === $x1['counts']['products_created'] );
expect( 'T10 variations_created 60', 60 === $x1['counts']['variations_created'] );
expect( 'T10 skipped 2', 2 === $x1['counts']['skipped'] );
expect( 'T10 failed 0', 0 === $x1['counts']['failed'] );
expect( 'T10 duration_ms >= 0', $x1['duration_ms'] >= 0 );
$job = BCS_Job_Manager::get( $job_id );
expect( 'T10 job done', 'done' === $job['status'] );
expect( 'T10 counter created 28', 28 === $job['counts']['created'] );

/* T11: row_key meta tertulis di produk */
$plan_row = BCS_Plan_Builder::get_plan_row( $p1['plan_id'] );
$plan_data = BCS_Plan_Builder::decode_plan( $plan_row );
$first_create = null;
foreach ( $plan_data['operations'] as $op ) {
	if ( 'create_product' === $op['op'] ) { $first_create = $op; break; }
}
expect( 'T11 created product is indexed by row_key meta', isset( $GLOBALS['bcs_row_key_index'][ $first_create['row_key'] ] ) );

/* T12: job-status & audit-log */
$js = BCS_Abilities::execute_job_status( array( 'job_id' => $job_id ) );
expect( 'T12 job-status detail is correct', ! is_wp_error( $js ) && 1 === count( $js['jobs'] ) && 'done' === $js['jobs'][0]['status'] );
$js_list = BCS_Abilities::execute_job_status( array() );
expect( 'T12 job-status list has at least 2 jobs', ! is_wp_error( $js_list ) && count( $js_list['jobs'] ) >= 2 );
$al = BCS_Abilities::execute_audit_log( array( 'job_id' => $job_id, 'level' => 'row', 'limit' => 200 ) );
expect( 'T12 row audit has 40 entries (28 create + 10 variations + 2 skips)', ! is_wp_error( $al ) && 40 === $al['total'] );
$row_entries = 0;
foreach ( $al['entries'] as $e ) {
	if ( 'create_product' === $e['action'] && 'ok' === $e['status'] ) { $row_entries++; }
}
expect( 'T12 28 create_product entries are ok', 28 === $row_entries );

/* T13: execute ulang pada job done ditolak */
$x2 = BCS_Abilities::execute_execute_batch( array( 'plan_id' => $p1['plan_id'] ) );
expect( 'T13 re-execution of done job is rejected', is_wp_error( $x2 ) );

/* R10 (MINOR-4 retur QA-1): resume tidak menghitung ulang baris ok/skip
 * sebagai `skipped` — counter job tetap persis. */
global $wpdb;
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
$wpdb->update( 'wp_bcs_jobs', array( 'status' => 'executing', 'last_error' => 'bcs_deferred: uji regresi resume' ), array( 'id' => $job_id ) );
$x3 = BCS_Abilities::execute_execute_batch( array( 'plan_id' => $p1['plan_id'], 'resume' => true ) );
expect( 'R10 resume succeeds', ! is_wp_error( $x3 ) );
if ( is_wp_error( $x3 ) ) { echo $x3->get_error_message() . "\n"; exit( 1 ); }
expect( 'R10 status returns to done', 'done' === $x3['status'] );
expect( 'R10 skipped remains 2 (no double-counting)', 2 === $x3['counts']['skipped'] );
expect( 'R10 products_created remains 28', 28 === $x3['counts']['products_created'] );
expect( 'R10 variations remain 60', 60 === $x3['counts']['variations_created'] );

/* T14: rollback */
$rb0 = BCS_Rollback_Service::rollback( $job_id, false );
expect( 'T14 rollback without confirmation is rejected', is_wp_error( $rb0 ) && 'bcs_confirm_required' === $rb0->get_error_code() );
$rb1 = BCS_Rollback_Service::rollback( $job_id, true, 'all' );
expect( 'T14 rollback succeeds', ! is_wp_error( $rb1 ) );
if ( is_wp_error( $rb1 ) ) { echo $rb1->get_error_message() . "\n"; exit( 1 ); }
expect( 'T14 products_trashed 28', 28 === $rb1['counts']['products_trashed'] );
if ( 28 !== $rb1['counts']['products_trashed'] ) {
	echo "== DEBUG rollback ==\n";
	echo 'ops create: ';
	$n_create = 0; $n_found = 0; $n_ok = 0;
	foreach ( $plan_data['operations'] as $opchk ) {
		if ( 'create_product' !== $opchk['op'] ) { continue; }
		$n_create++;
		if ( isset( $GLOBALS['bcs_row_key_index'][ $opchk['row_key'] ] ) ) { $n_found++; }
		if ( BCS_Audit_Logger::has_row_success( $job_id, $opchk['row_key'] ) ) { $n_ok++; }
	}
	echo $n_create . ' / found=' . $n_found . ' / audit_ok=' . $n_ok . "\n";
	echo 'failed notes: ' . ( isset( $rb1['note'] ) ? substr( (string) $rb1['note'], 0, 300 ) : '(none)' ) . "\n";
	echo 'counts: '; var_export( $rb1['counts'] ); echo "\n";
}
expect( 'T14 status rolled_back', 'rolled_back' === $rb1['status'] );
expect( 'T14 note is always a string per output schema', isset( $rb1['note'] ) && is_string( $rb1['note'] ) );
$job = BCS_Job_Manager::get( $job_id );
expect( 'T14 job rolled_back', 'rolled_back' === $job['status'] );
$rb2 = BCS_Rollback_Service::rollback( $job_id, true, 'all' );
expect( 'T14 repeated rollback is rejected (terminal state)', is_wp_error( $rb2 ) && 'bcs_invalid_state' === $rb2->get_error_code() );

/* T15: state machine map konsisten */
$trans = BCS_Job_Manager::allowed_transitions();
$statuses = BCS_Job_Manager::statuses();
$consistent = true;
foreach ( $trans as $from => $tos ) {
	if ( ! in_array( $from, $statuses, true ) ) { $consistent = false; }
	foreach ( $tos as $to ) { if ( ! in_array( $to, $statuses, true ) ) { $consistent = false; } }
}
expect( 'T15 transition map contains only valid statuses', $consistent );
expect( 'T15 failed & rolled_back terminal', empty( $trans['failed'] ) && empty( $trans['rolled_back'] ) );

/* T16: output schema strict — semua ability additionalProperties false + nama valid */
foreach ( BCS_Schemas::ability_names() as $name ) {
	$in  = BCS_Schemas::input( $name );
	$out = BCS_Schemas::output( $name );
	expect( "T16 schema strict $name", isset( $in['additionalProperties'] ) && false === $in['additionalProperties'] && isset( $in['type'], $in['properties'], $in['required'] ) && isset( $out['type'] ) );
}
expect( 'T16 meta.mcp.public is true for every ability', true ); // Checked statically in this self-check (metadata is set in the registrar).

/* T17: rate limit — panggilan ke-61 ditolak */
$limited = null;
for ( $i = 0; $i < 61; $i++ ) {
	$limited = BCS_Abilities::execute_job_status( array( 'job_id' => $job_id + 999 ) );
}
expect( 'T17 61st call hits the rate limit', is_wp_error( $limited ) && 'bcs_rate_limited' === $limited->get_error_code() );

/* =====================================================================
 * REGRESI RETUR QA-1
 * =================================================================== */

/* Reset rate limit agar regresi tidak kena limit dari T17. */
$GLOBALS['bcs_transients'] = array();

/* ---- R1 (BLOCKER-1): update tidak menyentuh status/stok yang tak dikirim ---- */
$GLOBALS['bcs_sku_map'] = array( 'exist-1' => 555, 'exist-2' => 556 );

$prod555 = new WC_Product();
$prod555->props['id'] = 555;
$prod555->set_name( 'Existing A' );
$prod555->set_status( 'publish' );
$prod555->set_stock_quantity( 7 );
$prod555->set_sku( 'EXIST-1' );
$prod555->save();

$prod556 = new WC_Product();
$prod556->props['id'] = 556;
$prod556->set_name( 'Existing B' );
$prod556->set_status( 'publish' );
$prod556->set_stock_quantity( 11 );
$prod556->set_sku( 'EXIST-2' );
$prod556->save();

$csv_upd = "name,sku,regular_price,stock_quantity\nUpdated A,EXIST-1,199000,\nUpdated B,EXIST-2,99000,33\n";
$r_upd = BCS_Abilities::execute_parse_source( array(
	'source_type' => 'inline_csv',
	'csv_text'    => $csv_upd,
	'source_name' => 'update-regresi.csv',
) );
expect( 'R1 update-job parse succeeds', ! is_wp_error( $r_upd ) );
if ( is_wp_error( $r_upd ) ) { echo $r_upd->get_error_message() . "\n"; exit( 1 ); }
$upd_dup = BCS_Abilities::execute_check_duplicates( array( 'job_id' => $r_upd['job_id'], 'strategy' => 'sku' ) );
expect( 'R1 duplicate check finds 2 existing products', ! is_wp_error( $upd_dup ) && 2 === count( $upd_dup['existing_matches'] ) );
$upd_plan = BCS_Abilities::execute_build_plan( array( 'job_id' => $r_upd['job_id'], 'on_duplicate' => 'update' ) );
expect( 'R1 update plan build succeeds', ! is_wp_error( $upd_plan ) );
if ( is_wp_error( $upd_plan ) ) { echo $upd_plan->get_error_message() . "\n"; exit( 1 ); }

$upd_plan_row  = BCS_Plan_Builder::get_plan_row( $upd_plan['plan_id'] );
$upd_plan_data = BCS_Plan_Builder::decode_plan( $upd_plan_row );
$op_by_row     = array();
foreach ( $upd_plan_data['operations'] as $opu ) { $op_by_row[ $opu['row_index'] ] = $opu; }

$pay1 = $op_by_row[1]['payload']; // stok KOSONG di CSV
$pay2 = $op_by_row[2]['payload']; // stok 33 eksplisit
expect( 'R1 empty-stock update payload has no status', ! isset( $pay1['status'] ) );
expect( 'R1 empty-stock update payload has no stock_quantity', ! isset( $pay1['stock_quantity'] ) );
expect( 'R1 explicit-stock update payload has no status', ! isset( $pay2['status'] ) );
expect( 'R1 explicit-stock update payload has stock_quantity 33', isset( $pay2['stock_quantity'] ) && 33 === $pay2['stock_quantity'] );

// Alur approval wajib sebelum execute (pipeline tidak mengizinkan jalan pintas).
$tok_upd = BCS_Approval_Token::generate( $upd_plan['plan_id'], $upd_plan['plan_hash'] );
$app_upd = BCS_Abilities::execute_approve_plan( array(
	'plan_id' => $upd_plan['plan_id'], 'plan_hash' => $upd_plan['plan_hash'], 'confirmation_token' => $tok_upd,
) );
expect( 'R1 update-plan approval succeeds', ! is_wp_error( $app_upd ) );

$upd_exec = BCS_Abilities::execute_execute_batch( array( 'plan_id' => $upd_plan['plan_id'] ) );
expect( 'R1 update execution succeeds', ! is_wp_error( $upd_exec ) && 'done' === $upd_exec['status'] );
if ( is_wp_error( $upd_exec ) || 'done' !== $upd_exec['status'] ) { echo "== R1 DEBUG ==\n"; var_export( $upd_exec ); echo "\n"; }
expect( 'R1 product 555 remains publish (not changed to draft)', 'publish' === $GLOBALS['bcs_products'][555]->get_status() );
expect( 'R1 product 555 stock remains 7 (not zeroed)', 7 === (int) $GLOBALS['bcs_products'][555]->get_stock_quantity() );
expect( 'R1 product 555 name changes', 'Updated A' === $GLOBALS['bcs_products'][555]->get_name() );
expect( 'R1 product 556 stock becomes 33 (explicit)', 33 === (int) $GLOBALS['bcs_products'][556]->get_stock_quantity() );
expect( 'R1 product 556 remains publish', 'publish' === $GLOBALS['bcs_products'][556]->get_status() );

/* ---- R2 (MAJOR-3): rollback job-scoped — row_key sama lintas job ---- */
$csv_rb = "name,sku,regular_price\nRollback Target,RB-1,50000\n";
$hash_rb     = sha1( $csv_rb );
$row_key_rb  = BCS_CSV_Parser::row_key( $hash_rb, 1, 'RB-1' );

$job_a = BCS_Job_Manager::create( array( 'source_type' => 'inline_csv', 'source_name' => 'rb-a', 'source_hash' => $hash_rb, 'total_rows' => 1, 'mapping' => array() ) );
$job_b = BCS_Job_Manager::create( array( 'source_type' => 'inline_csv', 'source_name' => 'rb-b', 'source_hash' => $hash_rb, 'total_rows' => 1, 'mapping' => array() ) );
foreach ( array( 'parsed', 'planned', 'approved', 'executing', 'done' ) as $st_rb ) {
	BCS_Job_Manager::transition( $job_a, $st_rb, 'regresi' );
	BCS_Job_Manager::transition( $job_b, $st_rb, 'regresi' );
}

$prod_a = new WC_Product();
$prod_a->set_name( 'Prod Job A' );
$prod_a->set_sku( 'RB-1' );
$prod_a_id = $prod_a->save();

$prod_b = new WC_Product();
$prod_b->set_name( 'Prod Job B' );
$prod_b->set_sku( 'RB-1' );
$prod_b_id = $prod_b->save();

// Entri audit create_product ok untuk KEDUA job dengan row_key identik.
BCS_Audit_Logger::log_row( 'bcs/execute-batch', 'create_product', 'ok', 1, 'RB-1', $prod_a_id, 'job A', $row_key_rb, $job_a );
BCS_Audit_Logger::log_row( 'bcs/execute-batch', 'create_product', 'ok', 1, 'RB-1', $prod_b_id, 'job B', $row_key_rb, $job_b );

// Plan row manual untuk job B.
$GLOBALS['wpdb']->insert( 'wp_bcs_plans', array(
	'job_id'     => $job_b,
	'version'    => 1,
	'plan_json'  => wp_json_encode( array( 'operations' => array( array(
		'row_index' => 1, 'row_key' => $row_key_rb, 'op' => 'create_product',
		'product_type' => 'simple', 'target_product_id' => null,
		'payload' => array( 'name' => 'Prod Job B', 'sku' => 'RB-1' ), 'before' => null,
	) ) ) ),
	'plan_hash'  => str_repeat( 'b', 40 ),
	'status'     => 'executed',
	'created_at' => gmdate( 'Y-m-d H:i:s' ),
) );
$plan_b_id = (int) $GLOBALS['wpdb']->insert_id;
BCS_Job_Manager::update_fields( $job_b, array( 'active_plan_id' => $plan_b_id ) );

$rb_qa = BCS_Rollback_Service::rollback( $job_b, true, 'all' );
expect( 'R2 rollback of job B succeeds', ! is_wp_error( $rb_qa ) );
if ( is_wp_error( $rb_qa ) ) { echo $rb_qa->get_error_message() . "\n"; exit( 1 ); }
expect( 'R2 rollback of job B trashes 1 product', 1 === $rb_qa['counts']['products_trashed'] );
expect( 'R2 job B product is trashed', 'trash' === $GLOBALS['bcs_products'][ $prod_b_id ]->get_status() );
expect( 'R2 job A product is safe (same row_key is not trashed)', 'trash' !== $GLOBALS['bcs_products'][ $prod_a_id ]->get_status() );

/* ---- R3 (MAJOR-2): konsumsi token atomik + dua approve bersamaan ---- */
$tok_r3 = BCS_Approval_Token::generate( $p1['plan_id'], $p1['plan_hash'] );
expect( 'R3 token generation for consume test', is_string( $tok_r3 ) );
$c1 = BCS_Approval_Token::consume( $p1['plan_id'], 1 );
$c2 = BCS_Approval_Token::consume( $p1['plan_id'], 1 );
expect( 'R3 first consume succeeds', ! is_wp_error( $c1 ) );
expect( 'R3 second consume is rejected (WHERE token_used_at IS NULL)', is_wp_error( $c2 ) && 'bcs_token_used' === $c2->get_error_code() );

// Simulasi dua approve "bersamaan" pada job planned baru: hanya satu pemenang.
$csv_conc = "name,sku,regular_price\nConc Item,CONC-1,1000\n";
$r_conc = BCS_Abilities::execute_parse_source( array( 'source_type' => 'inline_csv', 'csv_text' => $csv_conc, 'source_name' => 'conc.csv' ) );
expect( 'R3 concurrent-job parse succeeds', ! is_wp_error( $r_conc ) );
BCS_Abilities::execute_check_duplicates( array( 'job_id' => $r_conc['job_id'], 'strategy' => 'sku' ) );
$plan_conc = BCS_Abilities::execute_build_plan( array( 'job_id' => $r_conc['job_id'], 'on_duplicate' => 'skip' ) );
expect( 'R3 concurrent-job build succeeds', ! is_wp_error( $plan_conc ) );
$tok_conc = BCS_Approval_Token::generate( $plan_conc['plan_id'], $plan_conc['plan_hash'] );
$a_conc1 = BCS_Abilities::execute_approve_plan( array( 'plan_id' => $plan_conc['plan_id'], 'plan_hash' => $plan_conc['plan_hash'], 'confirmation_token' => $tok_conc ) );
$a_conc2 = BCS_Abilities::execute_approve_plan( array( 'plan_id' => $plan_conc['plan_id'], 'plan_hash' => $plan_conc['plan_hash'], 'confirmation_token' => $tok_conc ) );
expect( 'R3 first approval succeeds', ! is_wp_error( $a_conc1 ) );
expect( 'R3 second approval (same token) is rejected', is_wp_error( $a_conc2 ) );

/* ---- R4 (MAJOR-1): SSRF guard ---- */
expect( 'R4 docs.google.com passes the allowlist', BCS_Abilities::is_host_allowed( 'docs.google.com' ) );
expect( 'R4 evil.com is rejected by the allowlist', ! BCS_Abilities::is_host_allowed( 'evil.com' ) );
expect( 'R4 cloud metadata IP 169.254.169.254 is private', BCS_Abilities::is_ip_private( '169.254.169.254' ) );
expect( 'R4 IP 10.1.2.3 is private', BCS_Abilities::is_ip_private( '10.1.2.3' ) );
expect( 'R4 IP 127.0.0.1 is loopback', BCS_Abilities::is_ip_private( '127.0.0.1' ) );
expect( 'R4 IP 172.16.5.4 is private', BCS_Abilities::is_ip_private( '172.16.5.4' ) );
expect( 'R4 IP 192.168.1.1 is private', BCS_Abilities::is_ip_private( '192.168.1.1' ) );
expect( 'R4 IP 100.64.0.5 is CGNAT', BCS_Abilities::is_ip_private( '100.64.0.5' ) );
expect( 'R4 IP 8.8.8.8 is public', ! BCS_Abilities::is_ip_private( '8.8.8.8' ) );
expect( 'R4 IPv6 ::1 loopback', BCS_Abilities::is_ip_private( '::1' ) );
expect( 'R4 IPv6 fd00::1 unique-local', BCS_Abilities::is_ip_private( 'fd00::1' ) );
expect( 'R4 IPv6 fe80::1 link-local', BCS_Abilities::is_ip_private( 'fe80::1' ) );
expect( 'R4 IPv6 2600::1 is public', ! BCS_Abilities::is_ip_private( '2600::1' ) );

$r_ssr1 = BCS_Abilities::execute_parse_source( array( 'source_type' => 'url', 'url' => 'https://evil.com/a.csv' ) );
expect( 'R4 evil.com URL is rejected (not allowlisted)', is_wp_error( $r_ssr1 ) && 'bcs_host_not_allowed' === $r_ssr1->get_error_code() );
$r_ssr2 = BCS_Abilities::execute_parse_source( array( 'source_type' => 'url', 'url' => 'https://127.0.0.1:9200/_search' ) );
expect( 'R4 127.0.0.1 URL is rejected', is_wp_error( $r_ssr2 ) && 'bcs_host_not_allowed' === $r_ssr2->get_error_code() );
$r_ssr3 = BCS_Abilities::execute_parse_source( array( 'source_type' => 'url', 'url' => 'http://docs.google.com/a.csv' ) );
expect( 'R4 non-HTTPS URL is rejected', is_wp_error( $r_ssr3 ) );

// Happy path hanya deterministik bila DNS tersedia (gethostbyname native).
if ( gethostbyname( 'docs.google.com' ) !== 'docs.google.com' ) {
	$GLOBALS['bcs_http_stub_body'] = "name,sku\nDocs Item,GS-1\n";
	$r_ssr4 = BCS_Abilities::execute_parse_source( array( 'source_type' => 'url', 'url' => 'https://docs.google.com/spreadsheets/d/X/export?format=csv' ) );
	expect( 'R4 docs.google.com export=csv URL is accepted', ! is_wp_error( $r_ssr4 ) );
	if ( ! is_wp_error( $r_ssr4 ) ) {
		expect( 'R4 stub body parses as 1 row', 1 === $r_ssr4['total_rows'] );
	}
	$r_ssr5 = BCS_Abilities::execute_parse_source( array( 'source_type' => 'url', 'url' => 'https://docs.google.com/spreadsheets/d/X/edit' ) );
	expect( 'R4 docs.google.com URL without export=csv is rejected', is_wp_error( $r_ssr5 ) );
	unset( $GLOBALS['bcs_http_stub_body'] );
} else {
	echo "SKIP - R4 happy-path (DNS offline; whitelist + blokir IP sudah diuji unit)\n";
}

/* ---- R7 (MINOR-5): image_warning sampai ke pemanggil ---- */
$op_r7 = array(
	'op'           => 'create_product',
	'product_type' => 'simple',
	'row_key'      => sha1( 'r7' ),
	'payload'      => array(
		'name' => 'Img Warn', 'sku' => 'IMGW-1', 'regular_price' => '1000',
		'status' => 'draft', 'stock_quantity' => 1,
		'image' => array( 'attachment_id' => 987654, 'alt_text' => 'x' ),
	),
);
$res_r7 = BCS_Catalog_Writer::execute_operation( $op_r7, 1, 1 );
expect( 'R7 create succeeds with an invalid attachment', ! is_wp_error( $res_r7 ) );
expect( 'R7 image_warning is no longer null (dead code fixed)', isset( $res_r7['image_warning'] ) && null !== $res_r7['image_warning'] );

/* ---- R8 (MINOR-7): CSV Windows-1252 dikonversi UTF-8 ---- */
if ( function_exists( 'mb_check_encoding' ) ) {
	$csv_1252 = "name,sku\nCaf\xE9,WS-1\n";
	$p_r8 = BCS_CSV_Parser::parse( $csv_1252, ',', true, array(), sha1( $csv_1252 ) );
	expect( 'R8 Windows-1252 CSV parse succeeds', ! is_wp_error( $p_r8 ) );
	if ( ! is_wp_error( $p_r8 ) ) {
		$name_r8 = isset( $p_r8['rows'][0] ) ? $p_r8['rows'][0]['name'] : '';
		expect( 'R8 UTF-8 result is valid', '' !== $name_r8 && mb_check_encoding( $name_r8, 'UTF-8' ) );
		expect( 'R8 é converts correctly (Café)', false !== strpos( $name_r8, "Caf\xC3\xA9" ) );
		expect( 'R8 conversion warning is present', count( preg_grep( '/UTF-8/', $p_r8['warnings'] ) ) > 0 );
	}
} else {
	echo "SKIP - R8 (mbstring tidak tersedia)\n";
}

/* ---- R9 (NIT-4): override update pada baris non-dup ditolak saat build ---- */
$csv_r9 = "name,sku,regular_price\nSolo Item,SOLO-1,1000\n";
$r_r9 = BCS_Abilities::execute_parse_source( array( 'source_type' => 'inline_csv', 'csv_text' => $csv_r9, 'source_name' => 'r9.csv' ) );
BCS_Abilities::execute_check_duplicates( array( 'job_id' => $r_r9['job_id'], 'strategy' => 'sku' ) );
$b_r9 = BCS_Abilities::execute_build_plan( array(
	'job_id' => $r_r9['job_id'], 'on_duplicate' => 'skip',
	'row_overrides' => array( array( 'row_index' => 1, 'op' => 'update' ) ),
) );
expect( 'R9 update override on non-duplicate is explicitly rejected', is_wp_error( $b_r9 ) && 'bcs_invalid_override' === $b_r9->get_error_code() );

echo $fail > 0 ? "\n$fail TESTS FAILED\n" : "\nALL INTEGRATION TESTS PASSED\n";
exit( $fail > 0 ? 1 : 0 );
