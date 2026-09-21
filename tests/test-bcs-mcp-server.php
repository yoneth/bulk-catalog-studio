<?php
/**
 * Harness uji Gate 3 — custom MCP server BCS (stub adapter + transport/handler).
 * Memverifikasi: daftar 11 ability server, endpoint namespace/route, transport
 * capability (parameter ke-13), versi, dan header Requires Plugins.
 * Bukan bagian plugin — hanya untuk verifikasi self-check.
 */

namespace {
error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );
define( 'BCS_VERSION', '1.0.0' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['bcs_user_can'] = true;
$GLOBALS['bcs_actions']  = array(); // tag => list of callbacks

class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}

function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return (string) $t; }
function esc_attr( $t ) { return (string) $t; }
function current_user_can( $cap ) { return (bool) $GLOBALS['bcs_user_can']; }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['bcs_actions'][ $tag ][] = $cb; return true; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_has_ability( $name ) { return true; } // Semua ability terdaftar (happy path).

}
/* ---- Stub dependency MCP Adapter (class resmi) ---- */
namespace WP\MCP\Core {
	class McpAdapter {
		public function create_server( ...$args ) { return true; }
	}
}
namespace WP\MCP\Transport {
	class HttpTransport {}
}
namespace WP\MCP\Infrastructure\ErrorHandling {
	class ErrorLogMcpErrorHandler {}
}
namespace WP\MCP\Infrastructure\Observability {
	class NullMcpObservabilityHandler {}
}

namespace {
	/* ---- Fake adapter yang merekam create_server ---- */
	class Fake_Mcp_Adapter {
		public $calls = array();
		public function create_server( ...$args ) {
			$this->calls[] = $args;
			return true;
		}
	}

	require dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/includes/class-bcs-schemas.php';
	require dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/includes/class-bcs-mcp-server.php';

	$fail = 0;
	function expect( $label, $cond ) {
		global $fail;
		echo ( $cond ? 'PASS' : 'FAIL' ) . " - $label\n";
		if ( ! $cond ) { $fail++; }
	}

	/* G1: init mendaftarkan hook yang benar */
	BCS_MCP_Server::init();
	expect( 'G1 init registers the mcp_adapter_init hook', isset( $GLOBALS['bcs_actions']['mcp_adapter_init'] ) && 1 === count( $GLOBALS['bcs_actions']['mcp_adapter_init'] ) );
	expect( 'G1 init registers the admin_init dependency check hook', isset( $GLOBALS['bcs_actions']['admin_init'] ) );
	expect( 'G1 init registers the admin_notices hook', isset( $GLOBALS['bcs_actions']['admin_notices'] ) );

	/* G2-G4: konfigurasi create_server */
	$adapter = new Fake_Mcp_Adapter();
	$cb      = $GLOBALS['bcs_actions']['mcp_adapter_init'][0];
	call_user_func( $cb, $adapter );

	expect( 'G2 create_server is called exactly once', 1 === count( $adapter->calls ) );
	if ( 1 !== count( $adapter->calls ) ) { exit( 1 ); }
	$call = $adapter->calls[0];

	expect( 'G2 server id bcs-mcp-server', 'bcs-mcp-server' === $call[0] );
	expect( 'G2 namespace bcs-mcp', 'bcs-mcp' === $call[1] );
	expect( 'G2 route mcp', 'mcp' === $call[2] );
	expect( 'G2 server name is Bulk Catalog Studio MCP', 'Bulk Catalog Studio MCP' === $call[3] );
	expect( 'G2 version equals BCS_VERSION', BCS_VERSION === $call[5] );

	/* G3: transport + handler persis */
	expect( 'G3 transport uses only HttpTransport', 1 === count( $call[6] ) && \WP\MCP\Transport\HttpTransport::class === $call[6][0] );
	expect( 'G3 error handler ErrorLogMcpErrorHandler', \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class === $call[7] );
	expect( 'G3 observability NullMcpObservabilityHandler', \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class === $call[8] );

	/* G4: tepat 11 ability BCS, urutan sama dengan registrar */
	$expected = array(
		'bcs/parse-source',
		'bcs/check-duplicates',
		'bcs/build-plan',
		'bcs/approve-plan',
		'bcs/execute-batch',
		'bcs/job-status',
		'bcs/rollback-batch',
		'bcs/audit-log',
		'bcs/sync-list-connections',
		'bcs/sync-run',
		'bcs/sync-approve-run',
	);
	expect( 'G4 exactly 11 abilities are exposed', 11 === count( $call[9] ) );
	expect( 'G4 ability list matches exactly (including order)', $expected === $call[9] );
	expect( 'G4 ability list matches BCS_Schemas', BCS_Schemas::ability_names() === $call[9] );
	expect( 'G4 resources and prompts are empty', array() === $call[10] && array() === $call[11] );

	/* G5: transport permission callback (parameter ke-13) — capability test */
	expect( 'G5 parameter 13 is callable', is_callable( $call[12] ) );
	$GLOBALS['bcs_user_can'] = true;
	expect( 'G5 transport permission is true with manage_woocommerce', true === call_user_func( $call[12] ) );
	$GLOBALS['bcs_user_can'] = false;
	expect( 'G5 transport permission is false without the capability', false === call_user_func( $call[12] ) );
	$GLOBALS['bcs_user_can'] = true;

	/* G5b: endpoint URL kustom */
	expect( 'G5b endpoint /wp-json/bcs-mcp/mcp', 'https://example.test/wp-json/bcs-mcp/mcp' === BCS_MCP_Server::endpoint_url() );
	expect( 'G5b REST namespace and route are consistent', 'bcs-mcp' === BCS_MCP_Server::rest_namespace() && 'mcp' === BCS_MCP_Server::route() );

	/* G6: notice tidak tampil bila dependency lengkap (flag false) */
	ob_start();
	BCS_MCP_Server::admin_notice_missing_dependency();
	$out = ob_get_clean();
	expect( 'G6 no notice when dependencies are complete', '' === trim( $out ) );

	/* G7 (statis): header Requires Plugins berisi woocommerce + mcp-adapter */
	$bootstrap = file_get_contents( dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/bulk-catalog-studio.php' );
	expect( 'G7 header Requires Plugins: woocommerce, mcp-adapter', false !== strpos( $bootstrap, 'Requires Plugins:     woocommerce, mcp-adapter' ) );

	/* G7 (statis): README mengarah ke endpoint kustom */
	$readme = file_get_contents( dirname( __DIR__ ) . '/plugin/bulk-catalog-studio/README.md' );
	expect( 'G7 README contains the /wp-json/bcs-mcp/mcp endpoint', false !== strpos( $readme, '/wp-json/bcs-mcp/mcp' ) );
	expect( 'G7 README states that the default endpoint is not used', false !== strpos( $readme, 'is NOT used' ) );

	/* G8: subprocess — dependency hilang → tidak fatal, notice admin. */
	$missing = shell_exec( 'php ' . escapeshellarg( __DIR__ . '/test-bcs-mcp-missing-deps.php' ) . ' 2>&1' );
	expect( 'G8 missing-dependency guard runs without fatal and emits a notice', is_string( $missing ) && false !== strpos( (string) $missing, 'MISSING-DEPS-OK' ) );

	echo $fail > 0 ? "\n$fail TESTS FAILED\n" : "\nALL MCP SERVER TESTS PASSED\n";
	exit( $fail > 0 ? 1 : 0 );
}
