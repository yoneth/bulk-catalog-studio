<?php
/**
 * Harness uji logika murni BCS di luar WordPress (stub WP minimal).
 * Bukan bagian plugin — hanya untuk verifikasi self-check.
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
	private $code;
	private $message;
	private $errors = array();

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->errors[ $code ] = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_messages() { return $this->errors; }
}

function is_wp_error( $thing ) {
	return ( $thing instanceof WP_Error );
}

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function absint( $maybe ) { return abs( (int) $maybe ); }
function sanitize_text_field( $str ) { return is_scalar( $str ) ? trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) ) : ''; }
function sanitize_textarea_field( $str ) { return is_scalar( $str ) ? trim( (string) $str ) : ''; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_title( $title ) {
	$title = strtolower( (string) $title );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
	return trim( $title, '-' );
}
function wp_kses_post( $content ) { return (string) $content; }
function esc_url_raw( $url ) { return (string) $url; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function get_option( $name, $default = false ) { return $default; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function apply_filters( $tag, $value ) { return $value; }

require __DIR__ . '/../plugin/bulk-catalog-studio/includes/class-bcs-settings.php';
require __DIR__ . '/../plugin/bulk-catalog-studio/includes/class-bcs-csv-parser.php';

$csv = file_get_contents( __DIR__ . '/../plugin/bulk-catalog-studio/tests/fixtures/sample-fashion-30.csv' );
$hash = sha1( $csv );

/* Mapping Indonesia — persis contoh alur spec §4 (fixture memakai header Indonesia). */
$spec_map = array(
	'Nama' => 'name', 'Deskripsi' => 'description', 'Ringkasan' => 'short_description',
	'SKU' => 'sku', 'Harga' => 'regular_price', 'Harga Diskon' => 'sale_price',
	'Stok' => 'stock_quantity', 'Berat(kg)' => 'weight', 'P' => 'dimensions.length',
	'L' => 'dimensions.width', 'T' => 'dimensions.height', 'Kategori' => 'categories',
	'Tag' => 'tags', 'Ukuran' => 'attribute:pa_ukuran', 'Warna' => 'attribute:pa_warna',
	'SKU Variasi' => 'variation_sku', 'Harga Variasi' => 'variation_regular_price',
	'Stok Variasi' => 'variation_stock_quantity', 'URL Gambar' => 'image_url',
	'Alt Gambar' => 'image_alt', 'SEO Title' => 'seo_title', 'SEO Desc' => 'seo_description',
);

/* Uji 1: parse fixture dengan mapping spec §4 */
$parsed = BCS_CSV_Parser::parse( $csv, ',', true, $spec_map, $hash );
if ( is_wp_error( $parsed ) ) {
	echo "FAIL parse: " . $parsed->get_error_message() . "\n";
	exit( 1 );
}

$rows = $parsed['rows'];
$n_var = 0;
$n_simple = 0;
$n_combos = 0;
foreach ( $rows as $row ) {
	if ( ! empty( $row['variations'] ) ) {
		$n_var++;
		$n_combos += count( $row['variations'] );
	} else {
		$n_simple++;
	}
}

echo "rows=" . count( $rows ) . " simple=$n_simple variable=$n_var combos=$n_combos\n";
echo "warnings=" . count( $parsed['warnings'] ) . " errors=" . count( $parsed['errors'] ) . "\n";

$fail = 0;
function expect( $label, $cond ) {
	global $fail;
	echo ( $cond ? 'PASS' : 'FAIL' ) . " - $label\n";
	if ( ! $cond ) { $fail++; }
}

expect( '30 baris terparse', count( $rows ) === 30 );
expect( '20 simple', $n_simple === 20 );
expect( '10 variable', $n_var === 10 );
expect( '60 variasi', $n_combos === 60 );
expect( 'ada warning stok kosong', count( preg_grep( '/stok kosong/', $parsed['warnings'] ) ) > 0 );
expect( 'ada warning duplikat upload? tidak — cek 0 error', count( $parsed['errors'] ) === 0 );

/* Uji 2: duplicate SKU intra-batch terdeteksi */
$by_sku = array();
foreach ( $rows as $row ) {
	$by_sku[ strtolower( $row['sku'] ) ][] = $row['row_index'];
}
$dup_skus = array_filter( $by_sku, function ( $idx ) { return count( $idx ) > 1; } );
expect( '1 grup SKU duplikat intra-batch (KML-GRS-005)', isset( $dup_skus['kml-grs-005'] ) );
expect( 'duplikat di baris 5 & 15', isset( $dup_skus['kml-grs-005'] ) && array( 5, 15 ) === $dup_skus['kml-grs-005'] );

/* Uji 3: variasi paralel konsisten */
$first_var = null;
foreach ( $rows as $row ) {
	if ( ! empty( $row['variations'] ) ) { $first_var = $row; break; }
}
expect( 'baris variable pertama ditemukan', null !== $first_var );
if ( null === $first_var ) { exit( 1 ); }
expect( 'variasi punya 6 kombinasi', count( $first_var['variations'] ) === 6 );
expect( 'variasi pertama sku benar', 'DRS-KTN-001-S-MRH' === $first_var['variations'][0]['sku'] );
expect( 'variasi pertama atribut', 'S' === $first_var['variations'][0]['attributes']['pa_ukuran'] && 'Merah' === $first_var['variations'][0]['attributes']['pa_warna'] );
expect( 'variasi terakhir atribut', 'L' === $first_var['variations'][5]['attributes']['pa_ukuran'] && 'Biru' === $first_var['variations'][5]['attributes']['pa_warna'] );
expect( 'variasi harga L naik 500', '190500' === $first_var['variations'][5]['regular_price'] );

/* Uji 4: row_key stabil persis spec §5.4 */
$rk1 = BCS_CSV_Parser::row_key( 'abc', 11, '  DRS-X ' );
$rk2 = BCS_CSV_Parser::row_key( 'abc', 11, 'drs-x' );
expect( 'row_key case/trim insensitive', $rk1 === $rk2 );
expect( 'row_key sha1 40 char', strlen( $rk1 ) === 40 );

/* Uji 5: batas baris — 600 baris ditolak (AC-12) */
$big = "name,sku\n";
for ( $i = 1; $i <= 600; $i++ ) {
	$big .= "Produk $i,SKU-$i\n";
}
$parsed_big = BCS_CSV_Parser::parse( $big, ',', true, array(), sha1( $big ) );
expect( '600 baris ditolak WP_Error', is_wp_error( $parsed_big ) && 'bcs_too_many_rows' === $parsed_big->get_error_code() );

/* Uji 6: delimiter titik-koma dan tab */
$semi = "name;sku;regular_price\nA;S-1;1000\nB;S-2;2000\n";
$p1 = BCS_CSV_Parser::parse( $semi, ';', true, array(), sha1( $semi ) );
expect( 'delimiter ; jalan', ! is_wp_error( $p1 ) && 2 === count( $p1['rows'] ) );

$tab = "name\tsku\tregular_price\nC\tT-1\t3000\n";
$p2 = BCS_CSV_Parser::parse( $tab, 'tab', true, array(), sha1( $tab ) );
expect( 'delimiter tab jalan', ! is_wp_error( $p2 ) && '3000' === $p2['rows'][0]['regular_price'] );

/* Uji 7: mapping Indonesia persis contoh spec §4 */
$src = "Nama,Deskripsi,SKU,Harga,Stok,Berat(kg),P,L,T,Kategori,Tag,Ukuran,Warna,SKU Variasi,Harga Variasi,Stok Variasi,URL Gambar,Alt Gambar,SEO Title,SEO Desc\nSabun,Desc,SB-1,12000,30,0.1,10,5,3,Mandi,sabun,S|M,Putih|Biru,SB-1-PT|SB-1-BR,12000|12000,10|20,99,Alt,X,Y\n";
$map = array(
	'Nama' => 'name', 'Deskripsi' => 'description', 'SKU' => 'sku', 'Harga' => 'regular_price',
	'Stok' => 'stock_quantity', 'Berat(kg)' => 'weight', 'P' => 'dimensions.length',
	'L' => 'dimensions.width', 'T' => 'dimensions.height', 'Kategori' => 'categories',
	'Tag' => 'tags', 'Ukuran' => 'attribute:pa_ukuran', 'Warna' => 'attribute:pa_warna',
	'SKU Variasi' => 'variation_sku', 'Harga Variasi' => 'variation_regular_price',
	'Stok Variasi' => 'variation_stock_quantity', 'URL Gambar' => 'image_url',
	'Alt Gambar' => 'image_alt', 'SEO Title' => 'seo_title', 'SEO Desc' => 'seo_description',
);
$p3 = BCS_CSV_Parser::parse( $src, ',', true, $map, sha1( $src ) );
if ( is_wp_error( $p3 ) ) {
	echo "FAIL mapping spec: " . $p3->get_error_message() . "\n";
	$fail++;
} else {
	$r = $p3['rows'][0];
	expect( 'mapping spec: nama', 'Sabun' === $r['name'] );
	expect( 'mapping spec: harga', '12000' === $r['regular_price'] );
	expect( 'mapping spec: dimensi length', '10' === $r['dimensions']['length'] );
	expect( 'mapping spec: dimensi width', '5' === $r['dimensions']['width'] );
	expect( 'mapping spec: dimensi height', '3' === $r['dimensions']['height'] );
	expect( 'mapping spec: kategori', array( 'Mandi' ) === $r['categories'] );
	expect( 'mapping spec: 2 variasi', 2 === count( $r['variations'] ) );
	expect( 'mapping spec: variasi warna biru', 'Biru' === $r['variations'][1]['attributes']['pa_warna'] );
	expect( 'mapping spec: image_url numerik jadi attachment id', 99 === $r['image_id'] );
}

/* Uji 8: harga format Indonesia */
$map_h = array( 'Nama' => 'name', 'SKU' => 'sku', 'Harga' => 'regular_price' );
$h1 = BCS_CSV_Parser::parse( "Nama,SKU,Harga\nX,SK-1,\"189.000\"\n", ',', true, $map_h, 'h1' );
expect( 'harga 189.000 → 189000', ! is_wp_error( $h1 ) && '189000' === $h1['rows'][0]['regular_price'] );
$h2 = BCS_CSV_Parser::parse( "Nama,SKU,Harga\nX,SK-1,\"1.899,50\"\n", ',', true, $map_h, 'h2' );
expect( 'harga 1.899,50 → 1899.5', ! is_wp_error( $h2 ) && '1899.5' === $h2['rows'][0]['regular_price'] );
$h3 = BCS_CSV_Parser::parse( "Nama,SKU,Harga\nX,SK-1,seribu\n", ',', true, $map_h, 'h3' );
expect( 'harga non-numerik → warning + null', ! is_wp_error( $h3 ) && null === $h3['rows'][0]['regular_price'] && count( preg_grep( '/non-numerik/', $h3['warnings'] ) ) > 0 );

/* Uji 9: cartesian product saat kolom variasi eksplisit tidak ada */
$cart = "Nama,SKU,Harga,Ukuran,Warna,SKU Variasi,Harga Variasi,Stok Variasi\nY,YY-1,50000,S|M,Merah|Biru,YY-1-A|YY-1-B|YY-1-C|YY-1-D,50000|50000|50000|50000,1|2|3|4\n";
$map2 = array( 'Nama' => 'name', 'SKU' => 'sku', 'Harga' => 'regular_price', 'Ukuran' => 'attribute:pa_ukuran', 'Warna' => 'attribute:pa_warna', 'SKU Variasi' => 'variation_sku', 'Harga Variasi' => 'variation_regular_price', 'Stok Variasi' => 'variation_stock_quantity' );
$p4 = BCS_CSV_Parser::parse( $cart, ',', true, $map2, 'cart' );
expect( 'cartesian 2x2 = 4 variasi', ! is_wp_error( $p4 ) && 4 === count( $p4['rows'][0]['variations'] ) );
expect( 'cartesian combo[3] = L? tidak — (M,Biru)', ! is_wp_error( $p4 ) && 'M' === $p4['rows'][0]['variations'][3]['attributes']['pa_ukuran'] );

echo $fail > 0 ? "\n$fail TEST GAGAL\n" : "\nSEMUA TEST PASS\n";
exit( $fail > 0 ? 1 : 0 );
