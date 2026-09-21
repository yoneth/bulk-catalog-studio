<?php
/**
 * Plugin Name:          Bulk Catalog Studio
 * Plugin URI:           https://cf.djavaweb.com/
 * Description:          AI Bulk Catalog Studio (BCS) — mengubah CSV / Google Sheet export / rows hasil prompt menjadi produk WooCommerce native melalui WordPress Abilities API + MCP Adapter. Pipeline: parse → duplicate-check → build-plan → human approval (token one-time) → execute → rollback. Semua tulisan produk memakai WC_Product CRUD, tanpa wp_insert_post.
 * Version:              1.0.0
 * Requires at least:    6.9
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               cf.djavaweb.com
 * Author URI:           https://cf.djavaweb.com/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          bulk-catalog-studio
 * Domain Path:          /languages
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/*
 * Konstanta plugin. Semua identifier memakai prefix bcs_ sesuai standar proyek.
 */
define( 'BCS_VERSION', '1.0.0' );
define( 'BCS_DB_VERSION', '1.0.0' );
define( 'BCS_PLUGIN_FILE', __FILE__ );
define( 'BCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BCS_MIN_PHP', '7.4' );

require_once BCS_PLUGIN_DIR . 'includes/class-bcs-settings.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-audit-logger.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-job-manager.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-csv-parser.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-duplicate-checker.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-plan-builder.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-approval-token.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-catalog-writer.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-executor.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-rollback-service.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-install.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-schemas.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-abilities.php';
require_once BCS_PLUGIN_DIR . 'admin/class-bcs-admin.php';

/**
 * Aktivasi plugin: buat 3 tabel via dbDelta + default settings.
 * Tidak menyentuh data produk sama sekali.
 *
 * @return void
 */
function bcs_activate() {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	BCS_Install::activate();
}
register_activation_hook( __FILE__, 'bcs_activate' );

/**
 * Deaktivasi plugin: TIDAK menghapus apa pun.
 * Spesifikasi §3.6: tabel bcs_* dan settings dipertahankan agar "dinonaktifkan
 * tanpa kehilangan data" (AC-7). Hanya hook eksekusi yang otomatis lepas karena
 * plugin tidak lagi di-load.
 *
 * @return void
 */
function bcs_deactivate() {
	// Sengaja kosong — data harus bertahan (spec §3.6, AC-7).
}
register_deactivation_hook( __FILE__, 'bcs_deactivate' );

/**
 * Upgrade skema DB bila versi berubah (dipanggil di admin untuk keamanan).
 *
 * @return void
 */
function bcs_maybe_upgrade() {
	if ( get_option( 'bcs_db_version' ) !== BCS_DB_VERSION ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		BCS_Install::activate();
	}
}
add_action( 'admin_init', 'bcs_maybe_upgrade' );

/**
 * Muat text domain plugin.
 *
 * @return void
 */
function bcs_load_textdomain() {
	load_plugin_textdomain(
		'bulk-catalog-studio',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'bcs_load_textdomain' );

/*
 * ---------------------------------------------------------------------------
 * Registrasi Abilities API.
 *
 * PENTING (riset docs/research/abilities-mcp-api.md gotcha #1):
 * - Hook kategori yang benar adalah `wp_abilities_api_categories_init`
 *   (dieksekusi SEBELUM `wp_abilities_api_init`).
 * - Hook ability yang benar adalah `wp_abilities_api_init`
 *   (core memvalidasi doing_action() dengan nama persis ini).
 * Registrasi di luar hook itu mengembalikan null secara senyap.
 * ---------------------------------------------------------------------------
 */
add_action( 'wp_abilities_api_categories_init', array( 'BCS_Abilities', 'register_category' ) );
add_action( 'wp_abilities_api_init', array( 'BCS_Abilities', 'register_abilities' ) );

/*
 * Halaman admin.
 */
add_action( 'plugins_loaded', array( 'BCS_Admin', 'init' ) );
