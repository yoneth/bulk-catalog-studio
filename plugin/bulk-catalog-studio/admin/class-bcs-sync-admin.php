<?php
/**
 * BCS Sync admin screen: connections, runs, quarantine, and conflicts.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Sync_Admin
 */
class BCS_Sync_Admin {

	const PAGE_SLUG = 'bcs-sync';

	/**
	 * Register admin-only hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Render the Sync page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_products' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
			wp_die( esc_html__( 'You do not have access to the Sync page.', 'bulk-catalog-studio' ), 403 );
		}

		$view    = self::view();
		$context = self::handle_post();

		self::styles();
		echo '<div class="wrap bcs-wrap bcs-sync-wrap">';
		echo '<h1>' . esc_html__( 'Sync', 'bulk-catalog-studio' ) . '</h1>';
		echo '<p>' . esc_html__( 'Monitor one-way catalog sync connections, review guarded runs, and resolve manual exceptions.', 'bulk-catalog-studio' ) . '</p>';

		self::query_notice();
		self::tabs( $view );

		if ( 'runs' === $view['view'] ) {
			self::render_runs( $view['connection_id'], $context );
		} elseif ( 'quarantine' === $view['view'] ) {
			self::render_quarantine( $view['connection_id'] );
		} elseif ( 'conflicts' === $view['view'] ) {
			self::render_conflicts();
		} else {
			self::render_connections( $context );
		}

		echo '</div>';
	}

	/**
	 * Parse and constrain the tab/filter query parameters.
	 *
	 * @return array
	 */
	private static function view() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'connections'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $view, array( 'connections', 'runs', 'quarantine', 'conflicts' ), true ) ) {
			$view = 'connections';
		}
		$connection_id = isset( $_GET['connection_id'] ) ? absint( wp_unslash( $_GET['connection_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id       = isset( $_GET['edit_connection'] ) ? absint( wp_unslash( $_GET['edit_connection'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array(
			'view'            => $view,
			'connection_id'   => $connection_id,
			'edit_connection' => $edit_id,
		);
	}

	/**
	 * Build a Sync page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	private static function page_url( array $args = array() ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Handle all Sync POST actions before emitting markup.
	 *
	 * @return array Context for the current render.
	 */
	private static function handle_post() {
		$context = array();
		if ( empty( $_POST['bcs_sync_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- action-specific nonce is checked below.
			return $context;
		}

		$action = sanitize_key( wp_unslash( $_POST['bcs_sync_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
			wp_die( esc_html__( 'Requires the manage_woocommerce capability.', 'bulk-catalog-studio' ), 403 );
		}

		if ( 'save_connection' === $action ) {
			$connection_id = isset( $_POST['connection_id'] ) ? absint( wp_unslash( $_POST['connection_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'bcs_save_sync_connection_' . $connection_id );
			$mapping    = isset( $_POST['column_mapping'] ) ? json_decode( wp_unslash( $_POST['column_mapping'] ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and validated as an array immediately.
			$policy     = isset( $_POST['policy'] ) ? json_decode( wp_unslash( $_POST['policy'] ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and validated as an array immediately.
			$thresholds = isset( $_POST['thresholds'] ) ? json_decode( wp_unslash( $_POST['thresholds'] ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and validated as an array immediately.
			if ( ! is_array( $mapping ) || ! is_array( $policy ) || ! is_array( $thresholds ) ) {
				self::redirect_error( __( 'Column mapping, policy, and thresholds must be valid JSON objects.', 'bulk-catalog-studio' ), 'connections' );
			}
			$fetcher = isset( $_POST['fetcher'] ) ? sanitize_key( wp_unslash( $_POST['fetcher'] ) ) : 'csv_export'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( 'sheets_api' === $fetcher ) {
				$sheet_config = array(
					'spreadsheet_id' => isset( $_POST['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'range'          => isset( $_POST['range'] ) ? sanitize_text_field( wp_unslash( $_POST['range'] ) ) : 'A1:Z10000', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				);
			} else {
				$sheet_config = array(
					'url' => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'gid' => isset( $_POST['gid'] ) ? sanitize_text_field( wp_unslash( $_POST['gid'] ) ) : '0', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				);
			}
			$args         = array(
				'name'             => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'fetcher'          => $fetcher,
				'sheet_config'     => $sheet_config,
				'column_mapping'   => $mapping,
				'policy'           => $policy,
				'thresholds'       => $thresholds,
				'interval_minutes' => isset( $_POST['interval_minutes'] ) ? absint( wp_unslash( $_POST['interval_minutes'] ) ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'status'           => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'paused', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			);
			if ( 'csv_export' === $fetcher ) {
				// Do not retain an unused service-account secret when migrating a
				// legacy sheets_api connection to the operational CSV fetcher.
				$args['credentials'] = null;
			}
			if ( 'sheets_api' === $fetcher && isset( $_POST['credentials'] ) && '' !== trim( (string) wp_unslash( $_POST['credentials'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is sanitized as textarea JSON below.
				$args['credentials'] = sanitize_textarea_field( wp_unslash( $_POST['credentials'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			$result = $connection_id ? BCS_Sync_Connection::update( $connection_id, $args ) : BCS_Sync_Connection::insert( $args );
			if ( is_wp_error( $result ) ) {
				self::redirect_error( $result->get_error_message(), 'connections' );
			}
			self::redirect_notice( $connection_id ? __( 'Sync connection updated.', 'bulk-catalog-studio' ) : __( 'Sync connection added.', 'bulk-catalog-studio' ), 'connections' );
		}

		if ( 'run_connection' === $action ) {
			$connection_id = isset( $_POST['connection_id'] ) ? absint( wp_unslash( $_POST['connection_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'bcs_run_sync_connection_' . $connection_id );
			$result = BCS_Sync_Runner::run( $connection_id, 'manual', 'wp_admin' );
			if ( is_wp_error( $result ) ) {
				self::redirect_error( $result->get_error_message(), 'runs' );
			}
			$status = isset( $result['status'] ) ? (string) $result['status'] : 'done';
			/* translators: %1$d: sync run ID, %2$s: run status. */
			self::redirect_notice( sprintf( __( 'Sync run #%1$d started with status %2$s.', 'bulk-catalog-studio' ), (int) $result['run_id'], $status ), 'runs' );
		}

		if ( 'delete_connection' === $action ) {
			$connection_id = isset( $_POST['connection_id'] ) ? absint( wp_unslash( $_POST['connection_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'bcs_delete_sync_connection_' . $connection_id );
			$connection = BCS_Sync_Connection::get( $connection_id );
			if ( is_wp_error( $connection ) || ! $connection ) {
				self::redirect_error( is_wp_error( $connection ) ? $connection->get_error_message() : __( 'Sync connection not found.', 'bulk-catalog-studio' ), 'connections' );
			}
			if ( 'active' === $connection['status'] ) {
				self::redirect_error( __( 'Pause the sync connection before deleting it.', 'bulk-catalog-studio' ), 'connections' );
			}
			$latest = BCS_Sync_Runner::latest_run( $connection_id );
			if ( $latest && in_array( $latest['state'], array( 'running', 'pending', 'fetching', 'diffing', 'approved', 'applying' ), true ) ) {
				self::redirect_error( __( 'This connection has an active sync run and cannot be deleted yet.', 'bulk-catalog-studio' ), 'connections' );
			}
			$deleted = BCS_Sync_Connection::delete( $connection_id );
			if ( is_wp_error( $deleted ) ) {
				self::redirect_error( $deleted->get_error_message(), 'connections' );
			}
			self::redirect_notice( __( 'Sync connection deleted. Run history was retained.', 'bulk-catalog-studio' ), 'connections' );
		}

		if ( 'pause_resume' === $action || 'test_connection' === $action ) {
			$connection_id = isset( $_POST['connection_id'] ) ? absint( wp_unslash( $_POST['connection_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'bcs_sync_' . $action . '_' . $connection_id );
			$connection = BCS_Sync_Connection::get( $connection_id );
			if ( is_wp_error( $connection ) || ! $connection ) {
				self::redirect_error( is_wp_error( $connection ) ? $connection->get_error_message() : __( 'Sync connection not found.', 'bulk-catalog-studio' ), 'connections' );
			}

			if ( 'pause_resume' === $action ) {
				$status  = 'active' === $connection['status'] ? 'paused' : 'active';
				$updated = BCS_Sync_Connection::update( $connection_id, array( 'status' => $status ) );
				if ( is_wp_error( $updated ) ) {
					self::redirect_error( $updated->get_error_message(), 'connections' );
				}
				self::redirect_notice( 'active' === $status ? __( 'Sync connection resumed.', 'bulk-catalog-studio' ) : __( 'Sync connection paused.', 'bulk-catalog-studio' ), 'connections' );
			}

			// Test is fetch + parse only. It deliberately does not call the runner.
			$fetcher = BCS_Sync_Fetcher_Factory::make( $connection['fetcher'] );
			if ( is_wp_error( $fetcher ) ) {
				$context['test_result'] = array(
					'error'         => $fetcher->get_error_message(),
					'connection_id' => $connection_id,
				);
				return $context;
			}
			$tested = $fetcher->test( $connection );
			if ( is_wp_error( $tested ) ) {
				$context['test_result'] = array(
					'error'         => $tested->get_error_message(),
					'connection_id' => $connection_id,
				);
			} else {
				$context['test_result'] = array(
					'result'        => $tested,
					'connection_id' => $connection_id,
				);
			}
			return $context;
		}

		if ( 'generate_run_token' === $action ) {
			$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'bcs_generate_sync_token_' . $run_id );
			$token = BCS_Sync_Runner::generate_run_token( $run_id );
			if ( is_wp_error( $token ) ) {
				self::redirect_error( $token->get_error_message(), 'runs' );
			}
			$context['generated_token']  = $token;
			$context['generated_run_id'] = $run_id;
			return $context;
		}

		if ( 'approve_run' === $action ) {
			$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'bcs_approve_sync_run_' . $run_id );
			$hash     = isset( $_POST['diff_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['diff_hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token    = isset( $_POST['confirmation_token'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['confirmation_token'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$approved = BCS_Sync_Runner::approve_run( $run_id, $hash, $token, get_current_user_id() );
			if ( is_wp_error( $approved ) ) {
				self::redirect_error( $approved->get_error_message(), 'runs' );
			}
			self::redirect_notice( __( 'Sync run approved. The next worker invocation can apply it.', 'bulk-catalog-studio' ), 'runs' );
		}

		if ( 'resolve_quarantine' === $action ) {
			$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'bcs_resolve_quarantine_' . $run_id );
			$sku       = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$operation = isset( $_POST['quarantine_action'] ) ? sanitize_key( wp_unslash( $_POST['quarantine_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$result    = BCS_Sync_Runner::resolve_quarantine( $run_id, $sku, $operation, 'wp_admin' );
			if ( is_wp_error( $result ) ) {
				self::redirect_error( $result->get_error_message(), 'quarantine' );
			}
			self::redirect_notice( __( 'Quarantine item resolved.', 'bulk-catalog-studio' ), 'quarantine' );
		}

		if ( 'resolve_conflict' === $action ) {
			$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'bcs_resolve_conflict_' . $product_id );
			$resolution = isset( $_POST['resolution'] ) ? sanitize_key( wp_unslash( $_POST['resolution'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$result     = BCS_Sync_Runner::resolve_conflict( $product_id, $resolution, 'wp_admin' );
			if ( is_wp_error( $result ) ) {
				self::redirect_error( $result->get_error_message(), 'conflicts' );
			}
			self::redirect_notice( __( 'Sync conflict resolved.', 'bulk-catalog-studio' ), 'conflicts' );
		}

		return $context;
	}

	/**
	 * Redirect after a successful action.
	 *
	 * @param string $message Notice.
	 * @param string $view View.
	 * @return void
	 */
	private static function redirect_notice( $message, $view ) {
		$url = self::page_url(
			array(
				'view'       => $view,
				'bcs_notice' => $message,
			)
		);
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Redirect after a failed action.
	 *
	 * @param string $message Error.
	 * @param string $view View.
	 * @return void
	 */
	private static function redirect_error( $message, $view ) {
		$url = self::page_url(
			array(
				'view'      => $view,
				'bcs_error' => $message,
			)
		);
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Render query-string action notices.
	 *
	 * @return void
	 */
	private static function query_notice() {
		if ( isset( $_GET['bcs_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['bcs_notice'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_GET['bcs_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['bcs_error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Render four Sync tabs.
	 *
	 * @param array $view Current view.
	 * @return void
	 */
	private static function tabs( array $view ) {
		echo '<nav class="nav-tab-wrapper bcs-sync-tabs">';
		$tabs = array(
			'connections' => __( 'Connections', 'bulk-catalog-studio' ),
			'runs'        => __( 'Runs', 'bulk-catalog-studio' ),
			'quarantine'  => __( 'Quarantine', 'bulk-catalog-studio' ),
			'conflicts'   => __( 'Conflicts', 'bulk-catalog-studio' ),
		);
		foreach ( $tabs as $slug => $label ) {
			$class = 'nav-tab' . ( $slug === $view['view'] ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::page_url( array( 'view' => $slug ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Connections table.
	 *
	 * @param array $context Current action context.
	 * @return void
	 */
	private static function render_connections( array $context ) {
		$connections = BCS_Sync_Connection::list_connections();
		if ( is_wp_error( $connections ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $connections->get_error_message() ) . '</p></div>';
			return;
		}
		if ( isset( $context['test_result'] ) ) {
			$test = $context['test_result'];
			if ( isset( $test['error'] ) ) {
				echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Connection test failed:', 'bulk-catalog-studio' ) . '</strong> ' . esc_html( $test['error'] ) . '</p></div>';
			} else {
				$rows = isset( $test['result']['rows'] ) ? count( (array) $test['result']['rows'] ) : 0;
				/* translators: %d: number of fetched rows. */
				$message = sprintf( __( 'Connection test passed: %d row(s) fetched. No catalog changes were made.', 'bulk-catalog-studio' ), $rows );
				echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
			}
		}

		echo '<h2>' . esc_html__( 'Connections', 'bulk-catalog-studio' ) . '</h2>';
		echo '<table class="widefat striped bcs-sync-table"><thead><tr>';
		foreach ( array( 'Name', 'Fetcher', 'Sheet reference', 'Status', 'Interval', 'Last run', 'Actions' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( empty( $connections ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No sync connections configured.', 'bulk-catalog-studio' ) . '</td></tr>';
		}
		foreach ( (array) $connections as $connection ) {
			$id       = isset( $connection['id'] ) ? absint( $connection['id'] ) : 0;
			$last_run = $id ? BCS_Sync_Runner::latest_run( $id ) : null;
			echo '<tr>';
			echo '<td><strong>' . esc_html( isset( $connection['name'] ) ? $connection['name'] : '' ) . '</strong><br><span class="bcs-muted">#' . esc_html( (string) $id ) . '</span></td>';
			echo '<td>' . esc_html( isset( $connection['fetcher'] ) ? $connection['fetcher'] : '' ) . '</td>';
			echo '<td><code>' . esc_html( self::sheet_ref( $connection ) ) . '</code></td>';
			echo '<td>' . wp_kses_post( self::badge( isset( $connection['status'] ) ? $connection['status'] : 'paused' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( isset( $connection['interval_minutes'] ) ? $connection['interval_minutes'] : 1 ) ) . ' ' . esc_html__( 'min', 'bulk-catalog-studio' ) . '</td>';
			echo '<td>';
			if ( $last_run ) {
				echo wp_kses_post( self::badge( $last_run['state'] ) ) . '<br><span class="bcs-muted">#' . esc_html( (string) $last_run['id'] ) . '</span>';
			} else {
				echo esc_html__( 'Never', 'bulk-catalog-studio' );
			}
			echo '</td><td class="bcs-actions">';
			$toggle = 'active' === ( isset( $connection['status'] ) ? $connection['status'] : '' ) ? __( 'Pause', 'bulk-catalog-studio' ) : __( 'Resume', 'bulk-catalog-studio' );
			self::action_form( 'pause_resume', $id, 'bcs_sync_pause_resume_' . $id, $toggle, array(), 'secondary' );
			if ( 'active' === ( isset( $connection['status'] ) ? $connection['status'] : '' ) ) {
				self::action_form( 'run_connection', $id, 'bcs_run_sync_connection_' . $id, __( 'Run now', 'bulk-catalog-studio' ), array(), 'primary' );
			}
			self::action_form( 'test_connection', $id, 'bcs_sync_test_connection_' . $id, __( 'Test connection', 'bulk-catalog-studio' ), array(), 'secondary' );
			self::delete_form( $id );
			echo ' <a class="button button-small" href="' . esc_url(
				self::page_url(
					array(
						'view'            => 'connections',
						'edit_connection' => $id,
					)
				)
			) . '">' . esc_html__( 'Edit', 'bulk-catalog-studio' ) . '</a>';
			echo ' <a class="button button-small" href="' . esc_url(
				self::page_url(
					array(
						'view'          => 'runs',
						'connection_id' => $id,
					)
				)
			) . '">' . esc_html__( 'Runs', 'bulk-catalog-studio' ) . '</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_connection_form( isset( $_GET['edit_connection'] ) ? absint( wp_unslash( $_GET['edit_connection'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Add/edit connection form. Credentials are never echoed back.
	 *
	 * @param int $edit_id Connection ID.
	 * @return void
	 */
	private static function render_connection_form( $edit_id = 0 ) {
		$connection = $edit_id ? BCS_Sync_Connection::get( $edit_id ) : null;
		if ( $edit_id && ( is_wp_error( $connection ) || ! $connection ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The selected sync connection could not be loaded.', 'bulk-catalog-studio' ) . '</p></div>';
			$connection = null;
			$edit_id    = 0;
		}
		$connection = is_array( $connection ) ? $connection : array();
		$defaults   = BCS_Sync_Connection::defaults();
		$connection = array_merge( $defaults, $connection );
		$config     = is_array( $connection['sheet_config'] ) ? $connection['sheet_config'] : array();
		$mapping    = wp_json_encode( $connection['column_mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$policy     = wp_json_encode( $connection['policy'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$thresholds = wp_json_encode( $connection['thresholds'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		echo '<h2>' . esc_html( $edit_id ? __( 'Edit connection', 'bulk-catalog-studio' ) : __( 'Add connection', 'bulk-catalog-studio' ) ) . '</h2>';
		echo '<form method="post" class="bcs-connection-form">';
		wp_nonce_field( 'bcs_save_sync_connection_' . (int) $edit_id );
		echo '<input type="hidden" name="bcs_sync_action" value="save_connection" /><input type="hidden" name="connection_id" value="' . esc_attr( (string) $edit_id ) . '" />';
		echo '<table class="form-table"><tbody>';
		self::form_row( 'name', __( 'Name', 'bulk-catalog-studio' ), '<input class="regular-text" type="text" name="name" value="' . esc_attr( $connection['name'] ) . '" required />' );
		$fetcher = '<select name="fetcher"><option value="csv_export"' . selected( $connection['fetcher'], 'csv_export', false ) . '>csv_export</option><option value="sheets_api"' . selected( $connection['fetcher'], 'sheets_api', false ) . '>sheets_api</option></select> <span class="description">' . esc_html__( 'sheets_api uses a read-only service account; credentials are encrypted at rest.', 'bulk-catalog-studio' ) . '</span>';
		self::form_row( 'fetcher', __( 'Fetcher', 'bulk-catalog-studio' ), $fetcher );
		self::form_row( 'url', __( 'CSV export URL', 'bulk-catalog-studio' ), '<input class="large-text" type="url" name="url" value="' . esc_attr( isset( $config['url'] ) ? $config['url'] : '' ) . '" placeholder="https://docs.google.com/.../export?format=csv" /> <span class="description">' . esc_html__( 'Used by csv_export.', 'bulk-catalog-studio' ) . '</span>' );
		self::form_row( 'gid', __( 'Sheet GID', 'bulk-catalog-studio' ), '<input class="small-text" type="text" name="gid" value="' . esc_attr( isset( $config['gid'] ) ? $config['gid'] : '0' ) . '" /> <span class="description">' . esc_html__( 'Used by csv_export.', 'bulk-catalog-studio' ) . '</span>' );
		self::form_row( 'spreadsheet_id', __( 'Spreadsheet ID', 'bulk-catalog-studio' ), '<input class="large-text" type="text" name="spreadsheet_id" value="' . esc_attr( isset( $config['spreadsheet_id'] ) ? $config['spreadsheet_id'] : '' ) . '" placeholder="1AbC..." /> <span class="description">' . esc_html__( 'Used by sheets_api.', 'bulk-catalog-studio' ) . '</span>' );
		self::form_row( 'range', __( 'API range', 'bulk-catalog-studio' ), '<input class="regular-text" type="text" name="range" value="' . esc_attr( isset( $config['range'] ) ? $config['range'] : 'A1:Z10000' ) . '" placeholder="Products!A1:Z10000" /> <span class="description">' . esc_html__( 'A1 notation used by sheets_api.', 'bulk-catalog-studio' ) . '</span>' );
		self::form_row( 'credentials', __( 'Service-account JSON', 'bulk-catalog-studio' ), '<textarea class="large-text code" name="credentials" rows="6" placeholder="Paste JSON only when adding or rotating a sheets_api key."></textarea> <span class="description">' . esc_html__( 'Never echoed back; leave blank to keep the existing encrypted key.', 'bulk-catalog-studio' ) . '</span>' );
		self::form_row( 'mapping', __( 'Column mapping JSON', 'bulk-catalog-studio' ), '<textarea class="large-text code" name="column_mapping" rows="4">' . esc_html( (string) $mapping ) . '</textarea>' );
		self::form_row( 'policy', __( 'Policy JSON', 'bulk-catalog-studio' ), '<textarea class="large-text code" name="policy" rows="4">' . esc_html( (string) $policy ) . '</textarea>' );
		self::form_row( 'thresholds', __( 'Thresholds JSON', 'bulk-catalog-studio' ), '<textarea class="large-text code" name="thresholds" rows="5">' . esc_html( (string) $thresholds ) . '</textarea>' );
		self::form_row( 'interval', __( 'Interval (minutes)', 'bulk-catalog-studio' ), '<input class="small-text" type="number" min="1" name="interval_minutes" value="' . esc_attr( (string) $connection['interval_minutes'] ) . '" />' );
		$status = '<select name="status"><option value="active"' . selected( $connection['status'], 'active', false ) . '>active</option><option value="paused"' . selected( $connection['status'], 'paused', false ) . '>paused</option><option value="error_state"' . selected( $connection['status'], 'error_state', false ) . '>error_state</option></select>';
		self::form_row( 'status', __( 'Status', 'bulk-catalog-studio' ), $status );
		echo '</tbody></table><p><button class="button button-primary" type="submit">' . esc_html( $edit_id ? __( 'Save connection', 'bulk-catalog-studio' ) : __( 'Add connection', 'bulk-catalog-studio' ) ) . '</button></p></form>';
	}

	/**
	 * Render one table form row.
	 *
	 * @param string $id Field ID.
	 * @param string $label Label.
	 * @param string $control Control markup.
	 * @return void
	 */
	private static function form_row( $id, $label, $control ) {
		echo '<tr><th scope="row"><label for="bcs-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>' . wp_kses_post( $control ) . '</td></tr>';
	}

	/**
	 * Runs history table and approval boxes.
	 *
	 * @param int   $connection_id Filter.
	 * @param array $context Action context.
	 * @return void
	 */
	private static function render_runs( $connection_id, array $context ) {
		$connections = BCS_Sync_Connection::list_connections();
		$runs        = BCS_Sync_Runner::list_runs( $connection_id, 100 );
		echo '<h2>' . esc_html__( 'Run history', 'bulk-catalog-studio' ) . '</h2>';
		echo '<form method="get" class="bcs-filter-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" /><input type="hidden" name="view" value="runs" />';
		echo '<label for="bcs-connection-filter">' . esc_html__( 'Connection:', 'bulk-catalog-studio' ) . '</label> ';
		echo '<select id="bcs-connection-filter" name="connection_id"><option value="0">' . esc_html__( 'All connections', 'bulk-catalog-studio' ) . '</option>';
		foreach ( (array) $connections as $connection ) {
			$id = absint( $connection['id'] );
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $connection_id, $id, false ) . '>' . esc_html( $connection['name'] ) . '</option>';
		}
		echo '</select> <button class="button" type="submit">' . esc_html__( 'Filter', 'bulk-catalog-studio' ) . '</button></form>';

		echo '<table class="widefat striped bcs-sync-table"><thead><tr>';
		foreach ( array( 'Run', 'Connection', 'State', 'Diff summary', 'Duration', 'Error code', 'Approval' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( empty( $runs ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No sync runs found.', 'bulk-catalog-studio' ) . '</td></tr>';
		}
		foreach ( (array) $runs as $run ) {
			$diff = self::decode( $run['diff_summary'] );
			echo '<tr>';
			echo '<td>#' . esc_html( (string) $run['id'] ) . '<br><span class="bcs-muted">' . esc_html( (string) $run['trigger'] ) . '</span></td>';
			echo '<td>#' . esc_html( (string) $run['connection_id'] ) . '</td>';
			echo '<td>' . wp_kses_post( self::badge( $run['state'] ) ) . '</td>';
			echo '<td>' . esc_html( self::summary_text( $diff ) ) . '<br><code class="bcs-hash">' . esc_html( (string) $run['diff_hash'] ) . '</code></td>';
			echo '<td>' . esc_html( self::duration( $run ) ) . '</td>';
			echo '<td>' . esc_html( $run['error_code'] ? $run['error_code'] : '—' ) . '</td>';
			echo '<td>';
			if ( 'awaiting_approval' === $run['state'] ) {
				$shown = isset( $context['generated_run_id'] ) && (int) $context['generated_run_id'] === (int) $run['id'] ? $context['generated_token'] : null;
				self::approval_box( $run, $shown );
			} else {
				echo '<span class="bcs-muted">—</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Quarantine table.
	 *
	 * @param int $connection_id Filter.
	 * @return void
	 */
	private static function render_quarantine( $connection_id ) {
		$items = BCS_Sync_Runner::list_quarantine( $connection_id, 200 );
		echo '<h2>' . esc_html__( 'Quarantine', 'bulk-catalog-studio' ) . '</h2>';
		echo '<p>' . esc_html__( 'These source rows were held by field-level or ownership guards. Applying one is always a manual action.', 'bulk-catalog-studio' ) . '</p>';
		echo '<table class="widefat striped bcs-sync-table"><thead><tr><th>' . esc_html__( 'SKU', 'bulk-catalog-studio' ) . '</th><th>' . esc_html__( 'Reason', 'bulk-catalog-studio' ) . '</th><th>' . esc_html__( 'Payload', 'bulk-catalog-studio' ) . '</th><th>' . esc_html__( 'Run', 'bulk-catalog-studio' ) . '</th><th>' . esc_html__( 'Actions', 'bulk-catalog-studio' ) . '</th></tr></thead><tbody>';
		if ( empty( $items ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No unresolved quarantine items.', 'bulk-catalog-studio' ) . '</td></tr>';
		}
		foreach ( (array) $items as $item ) {
			$run_id = absint( $item['run_id'] );
			$sku    = isset( $item['variation_sku'] ) && $item['variation_sku'] ? $item['variation_sku'] : ( isset( $item['sku'] ) ? $item['sku'] : '' );
			echo '<tr><td><code>' . esc_html( $sku ) . '</code></td><td>' . esc_html( isset( $item['reason'] ) ? $item['reason'] : '' ) . '</td><td><code>' . esc_html( self::json_preview( isset( $item['payload'] ) ? $item['payload'] : array() ) ) . '</code></td><td>#' . esc_html( (string) $run_id ) . '</td><td>';
			self::quarantine_form( $run_id, $sku, 'apply', __( 'Apply anyway', 'bulk-catalog-studio' ), 'primary' );
			self::quarantine_form( $run_id, $sku, 'discard', __( 'Discard', 'bulk-catalog-studio' ), 'secondary' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Conflicts table.
	 *
	 * @return void
	 */
	private static function render_conflicts() {
		$posts = function_exists( 'get_posts' ) ? get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Cap the admin exception list.
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The conflict flag is the indexed manual-review filter.
					array(
						'key'     => '_bcs_conflict_flag',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		) : array();
		echo '<h2>' . esc_html__( 'Conflicts', 'bulk-catalog-studio' ) . '</h2>';
		echo '<p>' . esc_html__( 'A conflict means the WooCommerce record changed after the last sync. Choose which side should win.', 'bulk-catalog-studio' ) . '</p>';
		echo '<table class="widefat striped bcs-sync-table"><thead><tr><th>' . esc_html__( 'Product', 'bulk-catalog-studio' ) . '</th><th>' . esc_html__( 'SKU', 'bulk-catalog-studio' ) . '</th><th>' . esc_html__( 'Conflict flag', 'bulk-catalog-studio' ) . '</th><th>' . esc_html__( 'Actions', 'bulk-catalog-studio' ) . '</th></tr></thead><tbody>';
		if ( empty( $posts ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No unresolved conflicts.', 'bulk-catalog-studio' ) . '</td></tr>';
		}
		foreach ( (array) $posts as $post ) {
			$product_id = is_object( $post ) && isset( $post->ID ) ? absint( $post->ID ) : absint( $post );
			$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			$sku        = $product && method_exists( $product, 'get_sku' ) ? $product->get_sku( 'edit' ) : '';
			$flag       = $product && method_exists( $product, 'get_meta' ) ? $product->get_meta( '_bcs_conflict_flag', true, 'edit' ) : '';
			echo '<tr><td>#' . esc_html( (string) $product_id ) . '</td><td><code>' . esc_html( $sku ) . '</code></td><td>' . esc_html( $flag ) . '</td><td>';
			self::conflict_form( $product_id, 'sheet_wins', __( 'Sheet wins', 'bulk-catalog-studio' ), 'primary' );
			self::conflict_form( $product_id, 'keep_wp', __( 'Keep WP version', 'bulk-catalog-studio' ), 'secondary' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render an approval token box.
	 *
	 * @param array       $run Run.
	 * @param string|null $token Plaintext token shown once.
	 * @return void
	 */
	private static function approval_box( array $run, $token = null ) {
		echo '<div class="bcs-token-box bcs-sync-approval">';
		echo '<strong>' . esc_html__( 'Approval required', 'bulk-catalog-studio' ) . '</strong>';
		if ( null !== $token ) {
			echo '<div class="bcs-token-reveal"><strong>' . esc_html__( 'Token shown once — copy it now:', 'bulk-catalog-studio' ) . '</strong><br><code class="bcs-token-code">' . esc_html( $token ) . '</code></div>';
		}
		echo '<form method="post" class="bcs-inline-form">';
		wp_nonce_field( 'bcs_generate_sync_token_' . (int) $run['id'] );
		echo '<input type="hidden" name="bcs_sync_action" value="generate_run_token" /><input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '" />';
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Generate token', 'bulk-catalog-studio' ) . '</button></form>';
		echo '<form method="post" class="bcs-inline-form bcs-approve-form">';
		wp_nonce_field( 'bcs_approve_sync_run_' . (int) $run['id'] );
		echo '<input type="hidden" name="bcs_sync_action" value="approve_run" /><input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '" /><input type="hidden" name="diff_hash" value="' . esc_attr( (string) $run['diff_hash'] ) . '" />';
		echo '<input type="text" name="confirmation_token" class="small-text" autocomplete="off" placeholder="BCS-XXXXXXXX" aria-label="' . esc_attr__( 'Confirmation token', 'bulk-catalog-studio' ) . '" /> ';
		echo '<button type="submit" class="button button-primary button-small">' . esc_html__( 'Approve', 'bulk-catalog-studio' ) . '</button></form>';
		echo '</div>';
	}

	/**
	 * Generic connection action form.
	 *
	 * @param string $action Action.
	 * @param int    $id Connection ID.
	 * @param string $nonce_action Nonce action.
	 * @param string $label Label.
	 * @param array  $hidden Extra hidden values.
	 * @param string $style Button style.
	 * @return void
	 */
	private static function action_form( $action, $id, $nonce_action, $label, array $hidden = array(), $style = 'secondary' ) {
		echo '<form method="post" class="bcs-inline-form">';
		wp_nonce_field( $nonce_action );
		echo '<input type="hidden" name="bcs_sync_action" value="' . esc_attr( $action ) . '" /><input type="hidden" name="connection_id" value="' . esc_attr( (string) $id ) . '" />';
		foreach ( $hidden as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		echo '<button type="submit" class="button button-small ' . esc_attr( 'primary' === $style ? 'button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Delete a paused connection after a browser confirmation.
	 *
	 * @param int $id Connection ID.
	 * @return void
	 */
	private static function delete_form( $id ) {
		$confirm = wp_json_encode( __( 'Delete this paused sync connection? Its run history will be retained.', 'bulk-catalog-studio' ) );
		echo '<form method="post" class="bcs-inline-form" onsubmit="return window.confirm(' . esc_attr( $confirm ) . ');">';
		wp_nonce_field( 'bcs_delete_sync_connection_' . (int) $id );
		echo '<input type="hidden" name="bcs_sync_action" value="delete_connection" /><input type="hidden" name="connection_id" value="' . esc_attr( (string) $id ) . '" />';
		echo '<button type="submit" class="button button-small button-link-delete">' . esc_html__( 'Delete', 'bulk-catalog-studio' ) . '</button></form>';
	}

	/**
	 * Quarantine action form.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $sku SKU.
	 * @param string $action Action.
	 * @param string $label Label.
	 * @param string $style Button style.
	 * @return void
	 */
	private static function quarantine_form( $run_id, $sku, $action, $label, $style ) {
		echo '<form method="post" class="bcs-inline-form">';
		wp_nonce_field( 'bcs_resolve_quarantine_' . (int) $run_id );
		echo '<input type="hidden" name="bcs_sync_action" value="resolve_quarantine" /><input type="hidden" name="run_id" value="' . esc_attr( (string) $run_id ) . '" /><input type="hidden" name="sku" value="' . esc_attr( $sku ) . '" /><input type="hidden" name="quarantine_action" value="' . esc_attr( $action ) . '" />';
		echo '<button type="submit" class="button button-small ' . esc_attr( 'primary' === $style ? 'button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Conflict resolution form.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $resolution Resolution.
	 * @param string $label Label.
	 * @param string $style Button style.
	 * @return void
	 */
	private static function conflict_form( $product_id, $resolution, $label, $style ) {
		echo '<form method="post" class="bcs-inline-form">';
		wp_nonce_field( 'bcs_resolve_conflict_' . (int) $product_id );
		echo '<input type="hidden" name="bcs_sync_action" value="resolve_conflict" /><input type="hidden" name="product_id" value="' . esc_attr( (string) $product_id ) . '" /><input type="hidden" name="resolution" value="' . esc_attr( $resolution ) . '" />';
		echo '<button type="submit" class="button button-small ' . esc_attr( 'primary' === $style ? 'button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Admin notices for connection health and manual gates.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $GLOBALS['wpdb'] ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
			return;
		}
		$connections = BCS_Sync_Connection::list_connections();
		if ( is_wp_error( $connections ) ) {
			return;
		}
		$shown = array();
		foreach ( (array) $connections as $connection ) {
			if ( ! empty( $connection['status'] ) && 'error_state' === $connection['status'] && empty( $shown['error_state'] ) ) {
				self::notice( 'error', __( 'A sync connection is in error state. Open Bulk Catalog → Sync to inspect the last failed run.', 'bulk-catalog-studio' ) );
				$shown['error_state'] = true;
			}
			if ( 'active' === ( isset( $connection['status'] ) ? $connection['status'] : '' ) && ! empty( $connection['last_ok_run_at'] ) && empty( $shown['heartbeat'] ) ) {
				$last     = strtotime( (string) $connection['last_ok_run_at'] . ' UTC' );
				$interval = max( 1, absint( isset( $connection['interval_minutes'] ) ? $connection['interval_minutes'] : 1 ) );
				if ( $last && $last < time() - ( 3 * $interval * 60 ) ) {
					self::notice( 'warning', __( 'A sync connection has missed its expected heartbeat. Open Bulk Catalog → Sync to inspect it.', 'bulk-catalog-studio' ) );
					$shown['heartbeat'] = true;
				}
			}
		}
		foreach ( BCS_Sync_Runner::list_runs( 0, 100 ) as $run ) {
			if ( 'awaiting_approval' === $run['state'] ) {
				self::notice( 'warning', __( 'A sync run is awaiting approval. Review its diff on Bulk Catalog → Sync.', 'bulk-catalog-studio' ) );
				break;
			}
		}
		$pending = BCS_Sync_Runner::pending_counts();
		if ( is_array( $pending ) && ! empty( $pending['conflicts_pending'] ) ) {
			self::notice( 'warning', __( 'There are unresolved catalog sync conflicts. Review them on Bulk Catalog → Sync → Conflicts.', 'bulk-catalog-studio' ) );
		}
	}

	/**
	 * Print one dismissible notice.
	 *
	 * @param string $type Notice type.
	 * @param string $message Message.
	 * @return void
	 */
	private static function notice( $type, $message ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Status pill.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function badge( $status ) {
		$class = 'bcs-pill';
		if ( in_array( $status, array( 'done', 'active', 'approved' ), true ) ) {
			$class .= ' bcs-pill-ok';
		} elseif ( in_array( $status, array( 'failed_fetch', 'failed_guard', 'partially_failed', 'error_state', 'awaiting_approval' ), true ) ) {
			$class .= ' bcs-pill-warn';
		}
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $status ) . '</span>';
	}

	/**
	 * Human summary for a persisted diff.
	 *
	 * @param array $diff Diff.
	 * @return string
	 */
	private static function summary_text( array $diff ) {
		$s = isset( $diff['summary'] ) && is_array( $diff['summary'] ) ? $diff['summary'] : $diff;
		return sprintf(
			'create %d · update %d · variation %d · delete %d · flag %d · quarantine %d · conflict %d · failed %d',
			isset( $s['create'] ) ? (int) $s['create'] : 0,
			isset( $s['update'] ) ? (int) $s['update'] : 0,
			isset( $s['update_variation'] ) ? (int) $s['update_variation'] : 0,
			isset( $s['delete'] ) ? (int) $s['delete'] : 0,
			isset( $s['flag_missing'] ) ? (int) $s['flag_missing'] : 0,
			isset( $s['quarantined'] ) ? (int) $s['quarantined'] : 0,
			isset( $s['conflict'] ) ? (int) $s['conflict'] : 0,
			isset( $s['failed'] ) ? (int) $s['failed'] : 0
		);
	}

	/**
	 * Run duration.
	 *
	 * @param array $run Run.
	 * @return string
	 */
	private static function duration( array $run ) {
		if ( empty( $run['started_at'] ) ) {
			return '—';
		}
		$start = strtotime( (string) $run['started_at'] . ' UTC' );
		$end   = ! empty( $run['finished_at'] ) ? strtotime( (string) $run['finished_at'] . ' UTC' ) : time();
		return $start ? number_format_i18n( max( 0, (int) ( $end - $start ) ) ) . 's' : '—';
	}

	/**
	 * JSON decode helper.
	 *
	 * @param mixed $value JSON.
	 * @return array
	 */
	private static function decode( $value ) {
		$decoded = is_array( $value ) ? $value : json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Compact escaped payload preview.
	 *
	 * @param mixed $payload Payload.
	 * @return string
	 */
	private static function json_preview( $payload ) {
		$json = wp_json_encode( $payload );
		return wp_trim_words( (string) $json, 24, '…' );
	}

	/**
	 * Format the configured sheet reference.
	 *
	 * @param array $connection Connection.
	 * @return string
	 */
	private static function sheet_ref( array $connection ) {
		$config = isset( $connection['sheet_config'] ) && is_array( $connection['sheet_config'] ) ? $connection['sheet_config'] : array();
		$ref    = '';
		foreach ( array( 'spreadsheet_id', 'sheet_id', 'id' ) as $key ) {
			if ( ! empty( $config[ $key ] ) ) {
				$ref = (string) $config[ $key ];
				break;
			}
		}
		if ( '' === $ref && ! empty( $config['url'] ) ) {
			$ref = sanitize_text_field( (string) $config['url'] );
		}
		if ( 'sheets_api' === ( isset( $connection['fetcher'] ) ? $connection['fetcher'] : '' ) ) {
			$range = isset( $config['range'] ) && '' !== (string) $config['range'] ? (string) $config['range'] : 'A1:Z10000';
			return ( '' === $ref ? '' : $ref ) . ' (' . $range . ')';
		}
		$gid = isset( $config['gid'] ) && '' !== (string) $config['gid'] ? $config['gid'] : '0';
		return ( '' === $ref ? '' : $ref ) . ' (gid ' . $gid . ')';
	}

	/**
	 * Inline styles shared by the self-contained screen.
	 *
	 * @return void
	 */
	private static function styles() {
		echo '<style>
		.bcs-sync-wrap .bcs-sync-table td { vertical-align: top; }
		.bcs-sync-wrap .bcs-pill { display:inline-block; padding:1px 8px; border-radius:10px; background:#f0f0f1; font-size:12px; }
		.bcs-sync-wrap .bcs-pill-ok { background:#c6e1c6; }
		.bcs-sync-wrap .bcs-pill-warn { background:#ffd680; }
		.bcs-sync-wrap .bcs-muted { color:#777; font-size:12px; }
		.bcs-sync-wrap .bcs-inline-form { display:inline-block; margin:0 4px 4px 0; }
		.bcs-sync-wrap .bcs-token-box { min-width:260px; background:#fff; border:1px solid #c3c4c7; padding:8px; }
		.bcs-sync-wrap .bcs-token-reveal { background:#fff8e5; border-left:4px solid #dba617; padding:6px; margin:6px 0; }
		.bcs-sync-wrap .bcs-token-code { font-size:16px; letter-spacing:1px; user-select:all; }
		.bcs-sync-wrap .bcs-hash { user-select:all; }
		.bcs-sync-wrap .bcs-filter-form { margin:12px 0; }
		</style>';
	}
}
