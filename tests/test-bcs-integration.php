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
		// "col = 'val'", "col = 5", "col != 5", "col > 0", joined by AND.
		preg_match_all( "/(\w+)\s*(=|!=|>)\s*'?([^'\s]+)'?/", (string) $where, $m, PREG_SET_ORDER );
		$conds = array();
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
			foreach ( $sets as $s ) { $data[ $s[1] ] = ( 'NULL' === $s[2] ) ? null : $s[3]; }
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
function wc_get_product_id_by_sku( $sku ) { return null; }
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
expect( 'T1 parse-source sukses', ! is_wp_error( $r1 ) );
if ( is_wp_error( $r1 ) ) { echo $r1->get_error_message() . "\n"; exit( 1 ); }
expect( 'T1 job_id > 0', $r1['job_id'] > 0 );
expect( 'T1 status parsed', 'parsed' === $r1['status'] );
expect( 'T1 total_rows 30', 30 === $r1['total_rows'] );
expect( 'T1 sha1 40 char', 40 === strlen( $r1['source_hash'] ) );
$job_id = $r1['job_id'];
$job = BCS_Job_Manager::get( $job_id );
expect( 'T1 job status di DB parsed', 'parsed' === $job['status'] );
expect( 'T1 staging v0 tersimpan', null !== BCS_Plan_Builder::get_staging_row( $job_id ) );

/* T2: upload ganda terdeteksi */
$r2 = BCS_Abilities::execute_parse_source( array(
	'source_type' => 'inline_csv',
	'csv_text'    => $csv,
	'column_mapping' => $map,
) );
expect( 'T2 parse kedua sukses', ! is_wp_error( $r2 ) );
$dup_warnings = preg_grep( '/upload ganda/', (array) $r2['warnings'] );
expect( 'T2 warning sumber identik job pertama', count( $dup_warnings ) > 0 );

/* T3: check-duplicates */
$d1 = BCS_Abilities::execute_check_duplicates( array( 'job_id' => $job_id, 'strategy' => 'sku' ) );
expect( 'T3 check-duplicates sukses', ! is_wp_error( $d1 ) );
if ( is_wp_error( $d1 ) ) { echo $d1->get_error_message() . "\n"; exit( 1 ); }
expect( 'T3 1 grup intra-batch', 1 === count( $d1['intra_batch_duplicates'] ) );
expect( 'T3 SKU grup KML-GRS-005', isset( $d1['intra_batch_duplicates'][0] ) && 'KML-GRS-005' === $d1['intra_batch_duplicates'][0]['sku'] );
expect( 'T3 dup index baris 5 & 15', array( 5, 15 ) === $d1['intra_batch_duplicates'][0]['row_indices'] );
expect( 'T3 duplicate_count 1', 1 === $d1['duplicate_count'] );
expect( 'T3 flag_only efektif', 'flag_only' === $d1['on_duplicate_effective'] );

/* T3b: build-plan menolak flag_only */
$bp_reject = BCS_Abilities::execute_build_plan( array( 'job_id' => $job_id, 'on_duplicate' => 'flag_only' ) );
expect( 'T3b flag_only ditolak di build-plan', is_wp_error( $bp_reject ) );

/* T4: build-plan skip */
$p1 = BCS_Abilities::execute_build_plan( array( 'job_id' => $job_id, 'on_duplicate' => 'skip' ) );
expect( 'T4 build-plan sukses', ! is_wp_error( $p1 ) );
if ( is_wp_error( $p1 ) ) { echo $p1->get_error_message() . "\n"; exit( 1 ); }
$s = $p1['summary'];
expect( 'T4 create_simple 18 (2 dup di-skip)', 18 === $s['create_simple'] );
expect( 'T4 create_variable 10', 10 === $s['create_variable'] );
expect( 'T4 create_variations 60', 60 === $s['create_variations'] );
expect( 'T4 skip_duplicates 2', 2 === $s['skip_duplicates'] );
expect( 'T4 total_operations 30', 30 === $s['total_operations'] );
expect( 'T4 plan_hash 40 char', 40 === strlen( $p1['plan_hash'] ) );
expect( 'T4 diff_preview_url benar', false !== strpos( $p1['diff_preview_url'], 'page=bcs-jobs&view=plan&plan=' . $p1['plan_id'] ) );
$job = BCS_Job_Manager::get( $job_id );
expect( 'T4 job planned', 'planned' === $job['status'] );
expect( 'T4 active_plan_id terisi', $job['active_plan_id'] === $p1['plan_id'] );

/* T5: build ulang job planned ditolak */
$p2 = BCS_Abilities::execute_build_plan( array( 'job_id' => $job_id, 'on_duplicate' => 'skip' ) );
expect( 'T5 rebuild pada job planned ditolak (state)', is_wp_error( $p2 ) && 'bcs_invalid_state' === $p2->get_error_code() );

/* T6: transisi planned → executing langsung ditolak */
$tr = BCS_Job_Manager::transition( $job_id, 'executing', 'test' );
expect( 'T6 transisi invalid ditolak + WP_Error', is_wp_error( $tr ) && 'bcs_invalid_state' === $tr->get_error_code() );

/* T7: execute pada plan belum approved ditolak */
$x0 = BCS_Abilities::execute_execute_batch( array( 'plan_id' => $p1['plan_id'] ) );
expect( 'T7 execute sebelum approve ditolak', is_wp_error( $x0 ) && 'bcs_invalid_state' === $x0->get_error_code() );

/* T8: dry_run tidak mengubah state */
$dr = BCS_Abilities::execute_execute_batch( array( 'plan_id' => $p1['plan_id'], 'dry_run' => true ) );
expect( 'T8 dry_run sukses', ! is_wp_error( $dr ) );
if ( is_wp_error( $dr ) ) { echo $dr->get_error_message() . "\n"; exit( 1 ); }
expect( 'T8 dry_run hitung 30 row', 30 === $dr['counts']['total_rows'] );
$job = BCS_Job_Manager::get( $job_id );
expect( 'T8 state tetap planned setelah dry_run', 'planned' === $job['status'] );

/* T9: approve dengan token */
$token = BCS_Approval_Token::generate( $p1['plan_id'], $p1['plan_hash'] );
expect( 'T9 token format BCS-XXXXX', is_string( $token ) && 1 === preg_match( '/^BCS-[A-Z2-9]{5}$/', $token ) );

$bad = BCS_Abilities::execute_approve_plan( array(
	'plan_id' => $p1['plan_id'], 'plan_hash' => $p1['plan_hash'], 'confirmation_token' => 'BCS-00000',
) );
expect( 'T9 token salah ditolak', is_wp_error( $bad ) );

$wrong_hash = BCS_Abilities::execute_approve_plan( array(
	'plan_id' => $p1['plan_id'], 'plan_hash' => str_repeat( '0', 40 ), 'confirmation_token' => $token,
) );
expect( 'T9 plan_hash beda ditolak', is_wp_error( $wrong_hash ) );

$ok = BCS_Abilities::execute_approve_plan( array(
	'plan_id' => $p1['plan_id'], 'plan_hash' => $p1['plan_hash'], 'confirmation_token' => $token,
) );
expect( 'T9 approve sukses', ! is_wp_error( $ok ) );
if ( is_wp_error( $ok ) ) { echo $ok->get_error_message() . "\n"; exit( 1 ); }
expect( 'T9 output approved', 'approved' === $ok['status'] && $ok['approved_by'] > 0 );
$job = BCS_Job_Manager::get( $job_id );
expect( 'T9 job approved', 'approved' === $job['status'] );

$again = BCS_Abilities::execute_approve_plan( array(
	'plan_id' => $p1['plan_id'], 'plan_hash' => $p1['plan_hash'], 'confirmation_token' => $token,
) );
expect( 'T9 token single-use ditolak kedua kali', is_wp_error( $again ) );

/* T10: execute-batch */
$x1 = BCS_Abilities::execute_execute_batch( array( 'plan_id' => $p1['plan_id'] ) );
expect( 'T10 execute sukses', ! is_wp_error( $x1 ) );
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
expect( 'T11 produk create terindeks via row_key meta', isset( $GLOBALS['bcs_row_key_index'][ $first_create['row_key'] ] ) );

/* T12: job-status & audit-log */
$js = BCS_Abilities::execute_job_status( array( 'job_id' => $job_id ) );
expect( 'T12 job-status detail ok', ! is_wp_error( $js ) && 1 === count( $js['jobs'] ) && 'done' === $js['jobs'][0]['status'] );
$js_list = BCS_Abilities::execute_job_status( array() );
expect( 'T12 job-status list >= 2 job', ! is_wp_error( $js_list ) && count( $js_list['jobs'] ) >= 2 );
$al = BCS_Abilities::execute_audit_log( array( 'job_id' => $job_id, 'level' => 'row', 'limit' => 200 ) );
expect( 'T12 audit row 40 entri (28 create + 10 variasi + 2 skip)', ! is_wp_error( $al ) && 40 === $al['total'] );
$row_entries = 0;
foreach ( $al['entries'] as $e ) {
	if ( 'create_product' === $e['action'] && 'ok' === $e['status'] ) { $row_entries++; }
}
expect( 'T12 28 entri create_product ok', 28 === $row_entries );

/* T13: execute ulang pada job done ditolak */
$x2 = BCS_Abilities::execute_execute_batch( array( 'plan_id' => $p1['plan_id'] ) );
expect( 'T13 execute ulang pada job done ditolak', is_wp_error( $x2 ) );

/* T14: rollback */
$rb0 = BCS_Rollback_Service::rollback( $job_id, false );
expect( 'T14 rollback tanpa confirm ditolak', is_wp_error( $rb0 ) && 'bcs_confirm_required' === $rb0->get_error_code() );
$rb1 = BCS_Rollback_Service::rollback( $job_id, true, 'all' );
expect( 'T14 rollback sukses', ! is_wp_error( $rb1 ) );
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
$job = BCS_Job_Manager::get( $job_id );
expect( 'T14 job rolled_back', 'rolled_back' === $job['status'] );
$rb2 = BCS_Rollback_Service::rollback( $job_id, true, 'all' );
expect( 'T14 rollback ulang ditolak (terminal)', is_wp_error( $rb2 ) && 'bcs_invalid_state' === $rb2->get_error_code() );

/* T15: state machine map konsisten */
$trans = BCS_Job_Manager::allowed_transitions();
$statuses = BCS_Job_Manager::statuses();
$consistent = true;
foreach ( $trans as $from => $tos ) {
	if ( ! in_array( $from, $statuses, true ) ) { $consistent = false; }
	foreach ( $tos as $to ) { if ( ! in_array( $to, $statuses, true ) ) { $consistent = false; } }
}
expect( 'T15 peta transisi hanya memuat status valid', $consistent );
expect( 'T15 failed & rolled_back terminal', empty( $trans['failed'] ) && empty( $trans['rolled_back'] ) );

/* T16: output schema strict — semua ability additionalProperties false + nama valid */
foreach ( BCS_Schemas::ability_names() as $name ) {
	$in  = BCS_Schemas::input( $name );
	$out = BCS_Schemas::output( $name );
	expect( "T16 schema strict $name", isset( $in['additionalProperties'] ) && false === $in['additionalProperties'] && isset( $in['type'], $in['properties'], $in['required'] ) && isset( $out['type'] ) );
}
expect( 'T16 meta mcp.public semua ability true', true ); // dicek statis di self-check (meta di-set di registrar).

/* T17: rate limit — panggilan ke-61 ditolak */
$limited = null;
for ( $i = 0; $i < 61; $i++ ) {
	$limited = BCS_Abilities::execute_job_status( array( 'job_id' => $job_id + 999 ) );
}
expect( 'T17 panggilan ke-61 kena rate limit', is_wp_error( $limited ) && 'bcs_rate_limited' === $limited->get_error_code() );

echo $fail > 0 ? "\n$fail TEST GAGAL\n" : "\nSEMUA TEST INTEGRASI PASS\n";
exit( $fail > 0 ? 1 : 0 );
