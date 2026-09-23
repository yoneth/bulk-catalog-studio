<?php
/**
 * BCS settings screen.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Settings_Admin
 *
 * Every value here was previously reachable only by editing the bcs_settings
 * option directly, which meant the sideload whitelist — the guard that decides
 * which hosts the server will fetch from — had no visible controls at all.
 */
class BCS_Settings_Admin {

	const PAGE_SLUG = 'bcs-settings';

	/**
	 * Hook the form handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_save' ) );
	}

	/**
	 * Persist a submitted settings form.
	 *
	 * @return void
	 */
	public static function maybe_save() {
		if ( empty( $_POST['bcs_settings_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked below.
			return;
		}
		if ( 'save_settings' !== sanitize_key( wp_unslash( $_POST['bcs_settings_action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
			wp_die( esc_html__( 'Requires the manage_woocommerce capability.', 'bulk-catalog-studio' ), 403 );
		}
		check_admin_referer( 'bcs_save_settings' );

		$raw = array(
			'allow_sideload'            => isset( $_POST['allow_sideload'] ) ? 1 : 0,
			'sideload_domain_whitelist' => isset( $_POST['sideload_domain_whitelist'] ) ? wp_unslash( $_POST['sideload_domain_whitelist'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_input().
			'sideload_timeout'          => isset( $_POST['sideload_timeout'] ) ? wp_unslash( $_POST['sideload_timeout'] ) : 15, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'audit_retention_days'      => isset( $_POST['audit_retention_days'] ) ? wp_unslash( $_POST['audit_retention_days'] ) : 90, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'sync_runs_retention_days'  => isset( $_POST['sync_runs_retention_days'] ) ? wp_unslash( $_POST['sync_runs_retention_days'] ) : 90, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'purge_batch_size'          => isset( $_POST['purge_batch_size'] ) ? wp_unslash( $_POST['purge_batch_size'] ) : 200, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);

		$clean  = BCS_Settings::sanitize_input( $raw );
		$stored = get_option( 'bcs_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();
		update_option( 'bcs_settings', array_merge( $stored, $clean ) );

		wp_safe_redirect( add_query_arg( 'bcs_settings_saved', '1', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
			wp_die( esc_html__( 'Requires the manage_woocommerce capability.', 'bulk-catalog-studio' ), 403 );
		}

		$settings  = BCS_Settings::all();
		$whitelist = (array) ( isset( $settings['sideload_domain_whitelist'] ) ? $settings['sideload_domain_whitelist'] : array() );

		echo '<div class="wrap"><h1>' . esc_html__( 'Bulk Catalog Studio Settings', 'bulk-catalog-studio' ) . '</h1>';

		if ( isset( $_GET['bcs_settings_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'bulk-catalog-studio' ) . '</p></div>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'bcs_save_settings' );
		echo '<input type="hidden" name="bcs_settings_action" value="save_settings" />';

		echo '<h2>' . esc_html__( 'Product images', 'bulk-catalog-studio' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="bcs-allow-sideload">' . esc_html__( 'Download image URLs', 'bulk-catalog-studio' ) . '</label></th><td>';
		echo '<label><input type="checkbox" id="bcs-allow-sideload" name="allow_sideload" value="1" ' . checked( ! empty( $settings['allow_sideload'] ), true, false ) . ' /> ';
		echo esc_html__( 'Allow the sync to download images from a URL in the sheet', 'bulk-catalog-studio' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. A numeric value in the image column is always treated as an existing attachment ID and needs no download.', 'bulk-catalog-studio' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bcs-whitelist">' . esc_html__( 'Allowed image domains', 'bulk-catalog-studio' ) . '</label></th><td>';
		echo '<textarea id="bcs-whitelist" name="sideload_domain_whitelist" rows="5" class="large-text code">' . esc_textarea( implode( "\n", $whitelist ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One host per line, for example cf.djavaweb.com. Subdomains of a listed host are allowed. Only https is accepted, and IP addresses are always refused. An empty list blocks every download rather than allowing all of them.', 'bulk-catalog-studio' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bcs-timeout">' . esc_html__( 'Download timeout', 'bulk-catalog-studio' ) . '</label></th><td>';
		echo '<input type="number" id="bcs-timeout" name="sideload_timeout" min="1" max="120" value="' . esc_attr( (string) ( isset( $settings['sideload_timeout'] ) ? $settings['sideload_timeout'] : 15 ) ) . '" class="small-text" /> ' . esc_html__( 'seconds', 'bulk-catalog-studio' );
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Log retention', 'bulk-catalog-studio' ) . '</h2>';
		echo '<p>' . esc_html__( 'A scheduled sync writes a run row and several audit rows every time it runs. The daily purge keeps those tables bounded. The latest run of each connection, any run still waiting or in progress, any run holding an unresolved quarantine entry, and the audit rows of a kept run are never deleted, whatever their age.', 'bulk-catalog-studio' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="bcs-runs-retention">' . esc_html__( 'Keep sync runs for', 'bulk-catalog-studio' ) . '</label></th><td>';
		echo '<input type="number" id="bcs-runs-retention" name="sync_runs_retention_days" min="0" max="3650" value="' . esc_attr( (string) ( isset( $settings['sync_runs_retention_days'] ) ? $settings['sync_runs_retention_days'] : 90 ) ) . '" class="small-text" /> ' . esc_html__( 'days', 'bulk-catalog-studio' );
		echo '<p class="description">' . esc_html__( 'Set to 0 to never purge sync runs.', 'bulk-catalog-studio' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bcs-audit-retention">' . esc_html__( 'Keep audit entries for', 'bulk-catalog-studio' ) . '</label></th><td>';
		echo '<input type="number" id="bcs-audit-retention" name="audit_retention_days" min="0" max="3650" value="' . esc_attr( (string) ( isset( $settings['audit_retention_days'] ) ? $settings['audit_retention_days'] : 90 ) ) . '" class="small-text" /> ' . esc_html__( 'days', 'bulk-catalog-studio' );
		echo '<p class="description">' . esc_html__( 'Set to 0 to never purge audit entries.', 'bulk-catalog-studio' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bcs-batch">' . esc_html__( 'Rows per purge batch', 'bulk-catalog-studio' ) . '</label></th><td>';
		echo '<input type="number" id="bcs-batch" name="purge_batch_size" min="1" max="1000" value="' . esc_attr( (string) ( isset( $settings['purge_batch_size'] ) ? $settings['purge_batch_size'] : 200 ) ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Deletions run in small batches so the log tables are never locked for long.', 'bulk-catalog-studio' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'bulk-catalog-studio' ) );
		echo '</form></div>';
	}
}
