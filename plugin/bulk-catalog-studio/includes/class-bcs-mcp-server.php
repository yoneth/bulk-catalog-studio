<?php
/**
 * Custom MCP server BCS (Gate 3 — retur dependency Novamira).
 *
 * Endpoint default MCP Adapter (`/wp-json/mcp/mcp-adapter-default-server`) di
 * server target ter-intercept Novamira (serverInfo `Novamira (legacy alias)`).
 * Plugin ini karenanya mendaftarkan MCP server sendiri via pola resmi
 * `mcp_adapter_init` + `create_server()` (riset §3.3) sehingga:
 *
 * - Endpoint utama menjadi `/wp-json/bcs-mcp/mcp` — nol ketergantungan pada
 *   route Novamira.
 * - 8 ability BCS tampil sebagai tool INDIVIDUAL di `tools/list` (perilaku
 *   custom server — riset §3.1), bukan hanya via meta-tools.
 * - Transport permission: `current_user_can( 'manage_woocommerce' )`.
 *
 * Guard dependency: bila MCP Adapter / transport / handler tidak tersedia,
 * registrasi dilewati dan admin notice ditampilkan hanya untuk admin — tanpa
 * fatal (plugin tetap berjalan; ability tetap terdaftar untuk kemampuan
 * WordPress internal).
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_MCP_Server
 */
class BCS_MCP_Server {

	/**
	 * Flag dependency adapter hilang — dipakai admin notice.
	 *
	 * @var bool
	 */
	private static $dependency_missing = false;

	/**
	 * Pesan alasan dependency hilang (untuk notice).
	 *
	 * @var string
	 */
	private static $dependency_missing_reason = '';

	/**
	 * Daftarkan hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'mcp_adapter_init', array( __CLASS__, 'register_server' ) );
		add_action( 'admin_init', array( __CLASS__, 'check_dependencies' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice_missing_dependency' ) );
	}

	/* ---------------------------------------------------------------------
	 * Konfigurasi terpusat (dipakai registrasi + test harness).
	 * ------------------------------------------------------------------- */

	/**
	 * Server ID.
	 *
	 * @return string
	 */
	public static function server_id() {
		return 'bcs-mcp-server';
	}

	/**
	 * REST namespace endpoint kustom.
	 *
	 * @return string
	 */
	public static function rest_namespace() {
		return 'bcs-mcp';
	}

	/**
	 * REST route.
	 *
	 * @return string
	 */
	public static function route() {
		return 'mcp';
	}

	/**
	 * Nama server.
	 *
	 * @return string
	 */
	public static function server_name() {
		return __( 'Bulk Catalog Studio MCP', 'bulk-catalog-studio' );
	}

	/**
	 * Deskripsi server.
	 *
	 * @return string
	 */
	public static function description() {
		return __( 'Custom MCP server Bulk Catalog Studio — 8 ability bcs/* sebagai tool individual pada endpoint /wp-json/bcs-mcp/mcp (tanpa route default yang ter-intercept Novamira).', 'bulk-catalog-studio' );
	}

	/**
	 * Versi server = versi plugin.
	 *
	 * @return string
	 */
	public static function server_version() {
		return defined( 'BCS_VERSION' ) ? BCS_VERSION : '1.0.0';
	}

	/**
	 * Class transport (satu-satunya: HTTP, MCP 2025-06-18).
	 *
	 * @return string
	 */
	public static function transport_class() {
		return \WP\MCP\Transport\HttpTransport::class;
	}

	/**
	 * Class error handler.
	 *
	 * @return string
	 */
	public static function error_handler_class() {
		return \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class;
	}

	/**
	 * Class observability handler.
	 *
	 * @return string
	 */
	public static function observability_handler_class() {
		return \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class;
	}

	/**
	 * 8 ability BCS yang diekspos (urutan sama dengan registrar).
	 *
	 * @return array
	 */
	public static function ability_names() {
		return BCS_Schemas::ability_names();
	}

	/**
	 * Capability transport (parameter ke-13).
	 *
	 * @return string
	 */
	public static function transport_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * Endpoint URL lengkap server kustom.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return home_url( '/wp-json/' . self::rest_namespace() . '/' . self::route() );
	}

	/**
	 * Callback permission transport (anonim, PHP 7.4 kompatibel).
	 *
	 * @return callable
	 */
	public static function transport_permission_callback() {
		return function () {
			return current_user_can( 'manage_woocommerce' );
		};
	}

	/* ---------------------------------------------------------------------
	 * Registrasi.
	 * ------------------------------------------------------------------- */

	/**
	 * Periksa dependency pada setiap request admin.
	 *
	 * Hook `mcp_adapter_init` tidak pernah dijalankan ketika mcp-adapter tidak
	 * aktif. Pemeriksaan terpisah ini memastikan admin tetap menerima notice
	 * non-fatal dalam kondisi tersebut.
	 *
	 * @return bool
	 */
	public static function check_dependencies() {
		return self::dependencies_met();
	}

	/**
	 * Callback `mcp_adapter_init` — buat server kustom.
	 *
	 * @param object $adapter Instance WP\MCP\Core\McpAdapter.
	 * @return bool True bila create_server dipanggil.
	 */
	public static function register_server( $adapter ) {
		// Guard seluruh class dependency SEBELUM registrasi (Gate 3 #6).
		if ( ! self::dependencies_met() ) {
			return false;
		}

		// Guard: 8 ability harus sudah terdaftar (hook wp_abilities_api_init
		// berjalan lebih dulu; registrasi manual di luar hook itu ditolak core).
		if ( ! self::abilities_registered() ) {
			self::$dependency_missing         = true;
			self::$dependency_missing_reason = __( 'Abilities API belum berisi 8 ability bcs/* — server MCP kustom tidak didaftarkan.', 'bulk-catalog-studio' );

			return false;
		}

		$result = $adapter->create_server(
			self::server_id(),
			self::rest_namespace(),
			self::route(),
			self::server_name(),
			self::description(),
			self::server_version(),
			array( self::transport_class() ),
			self::error_handler_class(),
			self::observability_handler_class(),
			self::ability_names(),
			array(),
			array(),
			self::transport_permission_callback()
		);

		return true === $result || null === $result || is_object( $result );
	}

	/**
	 * Semua class dependency tersedia?
	 *
	 * @return bool
	 */
	public static function dependencies_met() {
		$required = array(
			'\WP\MCP\Core\McpAdapter',
			self::transport_class(),
			self::error_handler_class(),
			self::observability_handler_class(),
		);

		foreach ( $required as $class ) {
			if ( ! class_exists( $class ) ) {
				self::$dependency_missing         = true;
				self::$dependency_missing_reason = sprintf(
					/* translators: %s: nama class */
					__( 'Class MCP Adapter tidak ditemukan: %1$s. Aktifkan plugin mcp-adapter agar endpoint /wp-json/bcs-mcp/mcp aktif.', 'bulk-catalog-studio' ),
					$class
				);

				return false;
			}
		}

		return true;
	}

	/**
	 * Apakah 8 ability BCS sudah terdaftar di registry Abilities API?
	 *
	 * @return bool
	 */
	public static function abilities_registered() {
		if ( ! function_exists( 'wp_has_ability' ) ) {
			return false;
		}

		foreach ( self::ability_names() as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Admin notice dependency hilang — hanya untuk admin, tanpa fatal.
	 *
	 * @return void
	 */
	public static function admin_notice_missing_dependency() {
		if ( ! self::$dependency_missing || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s:</strong> %2$s</p></div>',
			esc_html__( 'Bulk Catalog Studio MCP', 'bulk-catalog-studio' ),
			esc_html( (string) self::$dependency_missing_reason )
		);
	}
}
