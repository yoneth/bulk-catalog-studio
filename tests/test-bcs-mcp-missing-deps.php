<?php
/**
 * Subprocess test Gate 3 — dependency MCP Adapter HILANG.
 * Sengaja TIDAK mendefinisikan class WP\MCP\*: register_server harus
 * menolak registrasi tanpa fatal, mendaftarkan admin notice, dan tidak
 * memanggil create_server. Cetak MISSING-DEPS-OK bila semua assertion lulus.
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/' );
define( 'BCS_VERSION', '1.0.0' );

$GLOBALS['bcs_user_can'] = true;
$GLOBALS['bcs_actions']  = array();

function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return (string) $t; }
function current_user_can( $cap ) { return (bool) $GLOBALS['bcs_user_can']; }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['bcs_actions'][ $tag ][] = $cb; return true; }
function wp_has_ability( $name ) { return true; }

class Fake_Adapter_No_Dep {
	public $calls = 0;
	public function create_server( ...$args ) { $this->calls++; return true; }
}

$fail = 0;
function expect( $label, $cond ) {
	global $fail;
	echo ( $cond ? 'PASS' : 'FAIL' ) . " - $label\n";
	if ( ! $cond ) { $fail++; }
}

require dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/includes/class-bcs-schemas.php';
require dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/includes/class-bcs-mcp-server.php';

$adapter = new Fake_Adapter_No_Dep();

// register_server TANPA class WP\MCP\* — harus return false tanpa fatal.
$result = BCS_MCP_Server::register_server( $adapter );
expect( 'M1 register_server returns false when a dependency is missing', false === $result );
expect( 'M1 create_server is not called', 0 === $adapter->calls );

// Hook admin_notices terdaftar via init().
BCS_MCP_Server::init();
expect( 'M1 admin_init dependency check is registered', isset( $GLOBALS['bcs_actions']['admin_init'] ) );
expect( 'M1 admin_notices hook is registered', isset( $GLOBALS['bcs_actions']['admin_notices'] ) );

// Notice dirender untuk admin (capability activate_plugins = true).
ob_start();
BCS_MCP_Server::admin_notice_missing_dependency();
$out = ob_get_clean();
expect( 'M1 warning notice appears for admins', false !== strpos( $out, 'notice-warning' ) );
expect( 'M1 notice mentions the MCP Adapter class', false !== strpos( $out, 'MCP Adapter' ) );

// Notice TIDAK tampil untuk non-admin.
$GLOBALS['bcs_user_can'] = false;
ob_start();
BCS_MCP_Server::admin_notice_missing_dependency();
$out2 = ob_get_clean();
expect( 'M1 notice is hidden from non-admins', '' === trim( $out2 ) );
$GLOBALS['bcs_user_can'] = true;

echo $fail > 0 ? "MISSING-DEPS-FAIL ($fail)\n" : "MISSING-DEPS-OK\n";
exit( $fail > 0 ? 1 : 0 );
