<?php
/**
 * Halaman admin "Bulk Catalog Studio" (spec §1 diff preview, §4 alur manusia).
 *
 * Menu: admin.php?page=bcs-jobs
 * Views:
 * - default        : daftar job + status.
 * - view=job       : detail job + daftar plan.
 * - view=plan      : diff preview per plan (create/update/skip) + generate &
 *                    tampilkan confirmation_token one-time + tombol Approve.
 * - view=audit     : audit log viewer per job.
 *
 * Keamanan: capability edit_products untuk melihat; manage_woocommerce untuk
 * aksi tulis (generate token, approve); nonces di semua form; escaping penuh.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Admin
 */
class BCS_Admin {

	const PAGE_SLUG = 'bcs-jobs';

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		if ( class_exists( 'BCS_Sync_Admin' ) ) {
			BCS_Sync_Admin::init();
		}
		if ( class_exists( 'BCS_Settings_Admin' ) ) {
			BCS_Settings_Admin::init();
		}
	}

	/**
	 * Registrasi menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Bulk Catalog Studio', 'bulk-catalog-studio' ),
			__( 'Bulk Catalog', 'bulk-catalog-studio' ),
			'edit_products',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-products',
			56
		);

		if ( function_exists( 'add_submenu_page' ) && class_exists( 'BCS_Sync_Admin' ) ) {
			add_submenu_page(
				self::PAGE_SLUG,
				__( 'Sync', 'bulk-catalog-studio' ),
				__( 'Sync', 'bulk-catalog-studio' ),
				'edit_products',
				BCS_Sync_Admin::PAGE_SLUG,
				array( 'BCS_Sync_Admin', 'render' )
			);
		}

		// Settings sit behind manage_woocommerce, not edit_products: they decide
		// which hosts the server will fetch from and how much history is deleted.
		if ( function_exists( 'add_submenu_page' ) && class_exists( 'BCS_Settings_Admin' ) ) {
			add_submenu_page(
				self::PAGE_SLUG,
				__( 'Settings', 'bulk-catalog-studio' ),
				__( 'Settings', 'bulk-catalog-studio' ),
				'manage_woocommerce',
				BCS_Settings_Admin::PAGE_SLUG,
				array( 'BCS_Settings_Admin', 'render' )
			);
		}
	}

	/**
	 * Ambil parameter view yang disanitasi.
	 *
	 * @return array
	 */
	private static function get_view() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$plan = isset( $_GET['plan'] ) ? absint( wp_unslash( $_GET['plan'] ) ) : 0;        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$job  = isset( $_GET['job'] ) ? absint( wp_unslash( $_GET['job'] ) ) : 0;          // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 0, absint( wp_unslash( $_GET['paged'] ) ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array(
			'view'  => $view,
			'plan'  => $plan,
			'job'   => $job,
			'paged' => $paged,
		);
	}

	/**
	 * URL halaman dengan params.
	 *
	 * @param array $params Query params.
	 * @return string
	 */
	private static function page_url( array $params = array() ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		if ( ! empty( $params ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $params ) ), $url );
		}

		return $url;
	}

	/**
	 * Render utama.
	 *
	 * @return void
	 */
	public static function render_sync() {
		if ( class_exists( 'BCS_Sync_Admin' ) ) {
			BCS_Sync_Admin::render();
		}
	}

	/**
	 * Render the main batch page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have access to this page.', 'bulk-catalog-studio' ), 403 );
		}

		$v = self::get_view();

		// MINOR-6 (retur QA-1): proses POST (generate token / approve) SEBELUM
		// satu pun byte output — wp_safe_redirect butuh headers belum terkirim.
		// Token plaintext hasil generate disimpan sementara untuk render.
		$generated_token = null;
		if ( 'plan' === $v['view'] && $v['plan'] > 0 && isset( $_POST['bcs_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce diverifikasi dalam handler per aksi.
			$plan_row_post = BCS_Plan_Builder::get_plan_row( $v['plan'] );
			if ( $plan_row_post ) {
				$generated_token = self::handle_plan_actions( $plan_row_post );
			}
		}

		self::print_styles();

		echo '<div class="wrap bcs-wrap">';
		echo '<h1>' . esc_html__( 'Bulk Catalog Studio', 'bulk-catalog-studio' ) . '</h1>';

		// Notice hasil aksi.
		if ( isset( $_GET['bcs_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pesan status read-only.
			$notice = sanitize_text_field( wp_unslash( $_GET['bcs_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		if ( isset( $_GET['bcs_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$err = sanitize_text_field( wp_unslash( $_GET['bcs_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}

		if ( 'plan' === $v['view'] && $v['plan'] > 0 ) {
			self::render_plan( $v['plan'], $generated_token );
		} elseif ( 'job' === $v['view'] && $v['job'] > 0 ) {
			self::render_job( $v['job'] );
		} elseif ( 'audit' === $v['view'] && $v['job'] > 0 ) {
			self::render_audit( $v['job'], $v['paged'] );
		} else {
			self::render_job_list();
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * View: daftar job.
	 * ------------------------------------------------------------------- */

	/**
	 * Daftar job + status.
	 *
	 * @return void
	 */
	private static function render_job_list() {
		$jobs = BCS_Job_Manager::list_jobs( 50 );

		echo '<p>' . esc_html__( 'All Bulk Catalog Studio jobs. Pipeline: parse → duplicate-check → build-plan → approval (token shown on the admin screen) → execute → rollback.', 'bulk-catalog-studio' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Job', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Rows', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Created / Updated / Variations / Skipped / Failed', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Plan', 'bulk-catalog-studio' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $jobs ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No jobs yet. Run bcs-parse-source from Claude/MCP.', 'bulk-catalog-studio' ) . '</td></tr>';
		}

		foreach ( $jobs as $job ) {
			$badge = self::status_badge( $job['status'] );
			if ( $job['stale'] ) {
				$badge .= ' <span class="bcs-pill bcs-pill-warn">' . esc_html__( 'stale — can be resumed', 'bulk-catalog-studio' ) . '</span>';
			}

			echo '<tr>';
			echo '<td><a href="' . esc_url( self::page_url( array( 'view' => 'job', 'job' => (string) $job['id'] ) ) ) . '">#' . esc_html( $job['id'] ) . '</a></td>';
			echo '<td>' . wp_kses_post( $badge ) . '</td>';
			echo '<td>' . esc_html( $job['source_name'] ) . ' <span class="bcs-muted">(' . esc_html( $job['source_type'] ) . ')</span></td>';
			echo '<td>' . esc_html( number_format_i18n( $job['total_rows'] ) ) . '</td>';
			echo '<td>';
			echo esc_html(
				sprintf(
					'%d / %d / %d / %d / %d',
					$job['counts']['created'],
					$job['counts']['updated'],
					$job['counts']['variations'],
					$job['counts']['skipped'],
					$job['counts']['failed']
				)
			);
			echo '</td>';
			echo '<td>' . esc_html( mysql2date( 'Y-m-d H:i', $job['created_at'] ) ) . '</td>';
			echo '<td>';
			if ( $job['active_plan_id'] ) {
				echo '<a class="button button-small" href="' . esc_url( self::page_url( array( 'view' => 'plan', 'plan' => (string) $job['active_plan_id'] ) ) ) . '">' . esc_html__( 'Diff preview', 'bulk-catalog-studio' ) . '</a> ';
			}
			echo '<a class="button button-small" href="' . esc_url( self::page_url( array( 'view' => 'audit', 'job' => (string) $job['id'] ) ) ) . '">' . esc_html__( 'Audit', 'bulk-catalog-studio' ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Badge status.
	 *
	 * @param string $status Status.
	 * @return string HTML (harus di-kses dengan wp_kses_post di pemanggil).
	 */
	private static function status_badge( $status ) {
		$warn_statuses = array( 'failed', 'partially_failed' );
		$ok_statuses   = array( 'done' );

		$class = 'bcs-pill';
		if ( in_array( $status, $warn_statuses, true ) ) {
			$class .= ' bcs-pill-warn';
		} elseif ( in_array( $status, $ok_statuses, true ) ) {
			$class .= ' bcs-pill-ok';
		}

		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $status ) . '</span>';
	}

	/* ---------------------------------------------------------------------
	 * View: detail job.
	 * ------------------------------------------------------------------- */

	/**
	 * Detail satu job + daftar plan.
	 *
	 * @param int $job_id ID job.
	 * @return void
	 */
	private static function render_job( $job_id ) {
		$job = BCS_Job_Manager::get( $job_id );

		if ( ! $job ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Job not found.', 'bulk-catalog-studio' ) . '</p></div>';
			return;
		}

		echo '<p><a href="' . esc_url( self::page_url() ) . '">&larr; ' . esc_html__( 'All jobs', 'bulk-catalog-studio' ) . '</a></p>';

		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %d: job id */
				__( 'Job #%d', 'bulk-catalog-studio' ),
				$job['id']
			)
		) . ' ' . wp_kses_post( self::status_badge( $job['status'] ) ) . '</h2>';

		echo '<table class="widefat striped" style="max-width:820px"><tbody>';
		echo '<tr><th>' . esc_html__( 'Source', 'bulk-catalog-studio' ) . '</th><td>' . esc_html( $job['source_name'] ) . ' <span class="bcs-muted">(' . esc_html( $job['source_type'] ) . ')</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Source hash', 'bulk-catalog-studio' ) . '</th><td><code>' . esc_html( $job['source_hash'] ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Total rows', 'bulk-catalog-studio' ) . '</th><td>' . esc_html( number_format_i18n( $job['total_rows'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Counter', 'bulk-catalog-studio' ) . '</th><td>' . esc_html(
			sprintf(
				/* translators: 1-5: counters */
				__( 'created %1$d, updated %2$d, variations %3$d, skipped %4$d, failed %5$d', 'bulk-catalog-studio' ),
				$job['counts']['created'],
				$job['counts']['updated'],
				$job['counts']['variations'],
				$job['counts']['skipped'],
				$job['counts']['failed']
			)
		) . '</td></tr>';

		if ( ! empty( $job['last_error'] ) ) {
			echo '<tr><th>' . esc_html__( 'Last note', 'bulk-catalog-studio' ) . '</th><td>' . esc_html( $job['last_error'] ) . '</td></tr>';
		}

		$notes = isset( $job['mapping']['notes'] ) ? (string) $job['mapping']['notes'] : '';
		if ( '' !== $notes ) {
			echo '<tr><th>' . esc_html__( 'Claude notes', 'bulk-catalog-studio' ) . '</th><td>' . esc_html( $notes ) . '</td></tr>';
		}

		echo '</tbody></table>';

		// Daftar plan job.
		$plans = self::get_job_plans( $job_id );

		echo '<h3>' . esc_html__( 'Plans', 'bulk-catalog-studio' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:820px"><thead><tr>';
		echo '<th>' . esc_html__( 'Plan', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Version', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'bulk-catalog-studio' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		if ( empty( $plans ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No plans yet (staging v0 not counted).', 'bulk-catalog-studio' ) . '</td></tr>';
		}

		foreach ( $plans as $plan ) {
			echo '<tr>';
			echo '<td>#' . esc_html( $plan->id ) . '</td>';
			echo '<td>v' . esc_html( $plan->version ) . '</td>';
			echo '<td>' . esc_html( $plan->status ) . '</td>';
			echo '<td>' . esc_html( mysql2date( 'Y-m-d H:i', $plan->created_at ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( self::page_url( array( 'view' => 'plan', 'plan' => (string) $plan->id ) ) ) . '">' . esc_html__( 'Diff preview', 'bulk-catalog-studio' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p><a class="button" href="' . esc_url( self::page_url( array( 'view' => 'audit', 'job' => (string) $job_id ) ) ) . '">' . esc_html__( 'View audit log', 'bulk-catalog-studio' ) . '</a></p>';
	}

	/**
	 * Ambil semua plan versi > 0 untuk job.
	 *
	 * @param int $job_id ID job.
	 * @return array
	 */
	private static function get_job_plans( $job_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_plans';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d AND version > 0 ORDER BY version ASC", absint( $job_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
		);
	}

	/* ---------------------------------------------------------------------
	 * View: diff preview per plan + token approval.
	 * ------------------------------------------------------------------- */

	/**
	 * Diff preview plan.
	 *
	 * @param int         $plan_id        ID plan.
	 * @param string|null $generated_token Token baru hasil POST (tampil sekali).
	 * @return void
	 */
	private static function render_plan( $plan_id, $generated_token = null ) {
		$plan_row = BCS_Plan_Builder::get_plan_row( $plan_id );

		if ( ! $plan_row ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Plan not found.', 'bulk-catalog-studio' ) . '</p></div>';
			return;
		}

		$plan    = BCS_Plan_Builder::decode_plan( $plan_row );
		$job_id  = (int) $plan_row->job_id;

		echo '<p><a href="' . esc_url( self::page_url( array( 'view' => 'job', 'job' => (string) $job_id ) ) ) . '">&larr; ' . esc_html(
			sprintf(
				/* translators: %d: job id */
				__( 'Job #%d', 'bulk-catalog-studio' ),
				$job_id
			)
		) . '</a></p>';

		echo '<h2>' . esc_html(
			sprintf(
				/* translators: 1: plan id, 2: versi */
				__( 'Plan #%1$d (v%2$d) — %3$s', 'bulk-catalog-studio' ),
				(int) $plan_row->id,
				(int) $plan_row->version,
				(string) $plan_row->status
			)
		) . '</h2>';

		$summary = isset( $plan['summary'] ) && is_array( $plan['summary'] ) ? $plan['summary'] : array();

		echo '<p class="bcs-summary">';
		echo esc_html(
			sprintf(
				/* translators: 1-6: summary */
				__( 'Create simple: %1$d · create variable: %2$d · variations: %3$d · update: %4$d · skip duplicates: %5$d · total operations: %6$d', 'bulk-catalog-studio' ),
				isset( $summary['create_simple'] ) ? (int) $summary['create_simple'] : 0,
				isset( $summary['create_variable'] ) ? (int) $summary['create_variable'] : 0,
				isset( $summary['create_variations'] ) ? (int) $summary['create_variations'] : 0,
				isset( $summary['update_products'] ) ? (int) $summary['update_products'] : 0,
				isset( $summary['skip_duplicates'] ) ? (int) $summary['skip_duplicates'] : 0,
				isset( $summary['total_operations'] ) ? (int) $summary['total_operations'] : 0
			)
		);
		echo '</p>';

		echo '<p><code class="bcs-hash">' . esc_html( (string) $plan_row->plan_hash ) . '</code> <span class="bcs-muted">' . esc_html__( 'plan_hash — approval is bound to this value.', 'bulk-catalog-studio' ) . '</span></p>';

		/* --- Tabel operasi --- */
		$operations = isset( $plan['operations'] ) && is_array( $plan['operations'] ) ? $plan['operations'] : array();

		echo '<h3>' . esc_html__( 'Operations (materialized diff)', 'bulk-catalog-studio' ) . '</h3>';
		echo '<table class="widefat striped bcs-ops"><thead><tr>';
		echo '<th>' . esc_html__( 'Row', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Op', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Price / Stock', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Var.', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Target', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Detail', 'bulk-catalog-studio' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $operations ) ) {
			echo '<tr><td colspan="9">' . esc_html__( 'Plan has no operations.', 'bulk-catalog-studio' ) . '</td></tr>';
		}

		foreach ( $operations as $op ) {
			$payload = is_array( $op['payload'] ?? null ) ? $op['payload'] : array();
			$is_skip = 'skip_duplicate' === ( $op['op'] ?? '' );

			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $op['row_index'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $op['op'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $op['product_type'] ?? '—' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $payload['sku'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $payload['name'] ?? '' ) ) . '</td>';
			echo '<td>';
			echo esc_html( (string) ( $payload['regular_price'] ?? '—' ) );
			if ( ! empty( $payload['sale_price'] ) ) {
				echo ' <span class="bcs-muted">(sale ' . esc_html( (string) $payload['sale_price'] ) . ')</span>';
			}
			echo ' / ' . esc_html( (string) ( $payload['stock_quantity'] ?? '0' ) );
			echo '</td>';
			echo '<td>' . esc_html( (string) count( (array) ( $payload['variations'] ?? array() ) ) ) . '</td>';
			echo '<td>' . esc_html( ! empty( $op['target_product_id'] ) ? '#' . (string) $op['target_product_id'] : '—' ) . '</td>';
			echo '<td>';

			// Before/after untuk update.
			if ( 'update_product' === ( $op['op'] ?? '' ) && is_array( $op['before'] ?? null ) ) {
				echo '<details><summary>' . esc_html__( 'before → after', 'bulk-catalog-studio' ) . '</summary>';
				echo self::render_before_after( (array) $op['before'], $payload ); // phpcs:ignore WordPress.Security.EscapeOutput -- sudah di-escape di dalam.
				echo '</details>';
			}

			if ( $is_skip ) {
				echo '<span class="bcs-muted">' . esc_html__( 'Duplicate — not modified.', 'bulk-catalog-studio' ) . '</span>';
			} elseif ( ! empty( $payload['categories'] ) || ! empty( $payload['tags'] ) ) {
				echo '<details><summary>' . esc_html__( 'categories & tags', 'bulk-catalog-studio' ) . '</summary>';
				echo '<div class="bcs-detail">';
				if ( ! empty( $payload['categories'] ) ) {
					echo '<div><strong>' . esc_html__( 'Categories:', 'bulk-catalog-studio' ) . '</strong> ' . esc_html( implode( ', ', (array) $payload['categories'] ) ) . '</div>';
				}
				if ( ! empty( $payload['tags'] ) ) {
					echo '<div><strong>' . esc_html__( 'Tag:', 'bulk-catalog-studio' ) . '</strong> ' . esc_html( implode( ', ', (array) $payload['tags'] ) ) . '</div>';
				}
				echo '</div></details>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		/* --- Kotak token + approve --- */
		$this_manage = current_user_can( 'manage_woocommerce' );

		echo '<div class="bcs-token-box">';
		echo '<h3>' . esc_html__( 'Approval', 'bulk-catalog-studio' ) . '</h3>';

		if ( 'planned' !== (string) $plan_row->status ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: status plan */
					__( 'Plan status is "%s" — approval is no longer available.', 'bulk-catalog-studio' ),
					(string) $plan_row->status
				)
			) . '</p>';
			echo '</div>';
			return;
		}

		echo '<p>' . esc_html__( 'The one-time token is shown ONLY on this screen. Give the token to Claude (bcs-approve-plan) OR click the Approve button below. Tokens expire after 24 hours and can be used only once.', 'bulk-catalog-studio' ) . '</p>';

		if ( ! $this_manage ) {
			echo '<p><em>' . esc_html__( 'You do not have the manage_woocommerce capability required to generate tokens / approve.', 'bulk-catalog-studio' ) . '</em></p>';
			echo '</div>';
			return;
		}

		// Token hasil POST sudah diproses sebelum output (MINOR-6 retur QA-1).
		$token_to_show = $generated_token;

		$token_status = BCS_Approval_Token::status( $plan_id );

		echo '<form method="post" action="' . esc_url( self::page_url( array( 'view' => 'plan', 'plan' => (string) $plan_id ) ) ) . '">';
		wp_nonce_field( 'bcs_generate_token' );
		echo '<input type="hidden" name="bcs_action" value="generate_token" />';
		echo '<input type="hidden" name="plan_id" value="' . esc_attr( (string) $plan_id ) . '" />';
		echo '<input type="hidden" name="plan_hash" value="' . esc_attr( (string) $plan_row->plan_hash ) . '" />';
		submit_button( __( 'Generate token approval', 'bulk-catalog-studio' ), 'secondary', 'submit_bcs_generate', false );
		echo '</form>';

		if ( $token_status['used'] ) {
			echo '<p><em>' . esc_html__( 'The last token has already been used. Generate a new token to approve again.', 'bulk-catalog-studio' ) . '</em></p>';
		} elseif ( $token_status['has_token'] ) {
			echo '<p class="bcs-muted">' . esc_html(
				sprintf(
					/* translators: 1: waktu kedaluwarsa */
					__( 'A token was already generated (expires %1$s) but has not been used. Regenerate if needed.', 'bulk-catalog-studio' ),
					(string) $token_status['expires_at']
				)
			) . '</p>';
		}

		if ( null !== $token_to_show ) {
			echo '<div class="bcs-token-reveal">';
			echo '<p><strong>' . esc_html__( 'Approval token (shown once — copy it now):', 'bulk-catalog-studio' ) . '</strong></p>';
			echo '<p class="bcs-token-code"><code>' . esc_html( $token_to_show ) . '</code></p>';
			echo '</div>';
		}

		echo '<hr />';
		echo '<form method="post" action="' . esc_url( self::page_url( array( 'view' => 'plan', 'plan' => (string) $plan_id ) ) ) . '">';
		wp_nonce_field( 'bcs_approve_plan' );
		echo '<input type="hidden" name="bcs_action" value="approve_plan" />';
		echo '<input type="hidden" name="plan_id" value="' . esc_attr( (string) $plan_id ) . '" />';
		echo '<input type="hidden" name="plan_hash" value="' . esc_attr( (string) $plan_row->plan_hash ) . '" />';
		echo '<p><label for="bcs-token-input">' . esc_html__( 'Approval token:', 'bulk-catalog-studio' ) . '</label> ';
		echo '<input type="text" id="bcs-token-input" name="confirmation_token" class="regular-text" autocomplete="off" /></p>';
		submit_button( __( 'Approve this plan', 'bulk-catalog-studio' ), 'primary', 'submit_bcs_approve', false );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Proses POST di layar plan (generate token / approve).
	 *
	 * @param object $plan_row Baris plan.
	 * @return string|null Token plaintext bila baru di-generate (tampil sekali).
	 */
	private static function handle_plan_actions( $plan_row ) {
		if ( ! isset( $_POST['bcs_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce diverifikasi per aksi di bawah.
			return null;
		}

		$plan_id = absint( $plan_row->id );

		$action = sanitize_key( wp_unslash( $_POST['bcs_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// --- Generate token ---
		if ( 'generate_token' === $action ) {
			check_admin_referer( 'bcs_generate_token' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Requires the manage_woocommerce capability.', 'bulk-catalog-studio' ), 403 );
			}

			$plan_hash = isset( $_POST['plan_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_hash'] ) ) : '';

			$token = BCS_Approval_Token::generate( $plan_id, $plan_hash );

			if ( is_wp_error( $token ) ) {
				// NIT-5 (retur QA-1): settings_errors() tidak dirender di halaman
				// menu kustom — pakai redirect notice yang sama dengan approve.
				wp_safe_redirect(
					add_query_arg(
						'bcs_error',
						rawurlencode( $token->get_error_message() ),
						self::page_url( array( 'view' => 'plan', 'plan' => (string) $plan_id ) )
					)
				);
				exit;
			}

			BCS_Audit_Logger::log_call(
				'bcs/admin-screen', 'generate_token', 'ok',
				sprintf(
					/* translators: 1: plan id, 2: user id */
					__( 'Approval token generated for plan #%1$d by user #%2$d.', 'bulk-catalog-studio' ),
					$plan_id,
					get_current_user_id()
				),
				(int) $plan_row->job_id,
				$plan_id
			);

			return $token; // Tampil SEKALI di layar.
		}

		// --- Approve langsung ---
		if ( 'approve_plan' === $action ) {
			check_admin_referer( 'bcs_approve_plan' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Requires the manage_woocommerce capability.', 'bulk-catalog-studio' ), 403 );
			}

			$plan_hash = isset( $_POST['plan_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_hash'] ) ) : '';
			$token     = isset( $_POST['confirmation_token'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['confirmation_token'] ) ) ) : '';

			$verify = BCS_Approval_Token::verify( $plan_id, $plan_hash, $token );

			if ( is_wp_error( $verify ) ) {
				BCS_Audit_Logger::log_call( 'bcs/approve-plan', 'approve_plan', 'failed', $verify->get_error_message(), (int) $plan_row->job_id, $plan_id );
				wp_safe_redirect(
					add_query_arg(
						'bcs_error',
						rawurlencode( $verify->get_error_message() ),
						self::page_url( array( 'view' => 'plan', 'plan' => (string) $plan_id ) )
					)
				);
				exit;
			}

			// MAJOR-2 (retur QA-1): transisi atomik DULU (satu pemenang),
			// baru konsumsi token atomik — sama dengan jalur MCP.
			$transition = BCS_Job_Manager::transition( (int) $plan_row->job_id, 'approved', 'bcs/approve-plan' );

			if ( is_wp_error( $transition ) ) {
				wp_safe_redirect(
					add_query_arg(
						'bcs_error',
						rawurlencode( $transition->get_error_message() ),
						self::page_url( array( 'view' => 'plan', 'plan' => (string) $plan_id ) )
					)
				);
				exit;
			}

			$consume = BCS_Approval_Token::consume( $plan_id, get_current_user_id() );

			if ( ! is_wp_error( $consume ) ) {
				BCS_Plan_Builder::set_status( $plan_id, 'approved' );

				BCS_Audit_Logger::log_call(
					'bcs/approve-plan', 'approve_plan', 'ok',
					sprintf(
						/* translators: 1: plan id, 2: user id */
						__( 'Plan #%1$d approved directly from the dashboard by user #%2$d.', 'bulk-catalog-studio' ),
						$plan_id,
						get_current_user_id()
					),
					(int) $plan_row->job_id,
					$plan_id
				);

				wp_safe_redirect(
					add_query_arg(
						'bcs_notice',
						rawurlencode( __( 'Plan approved. Execution can be run via bcs-execute-batch (MCP).', 'bulk-catalog-studio' ) ),
						self::page_url( array( 'view' => 'plan', 'plan' => (string) $plan_id ) )
					)
				);
				exit;
			}

			$error_msg = is_wp_error( $consume ) ? $consume->get_error_message() : __( 'Failed to mark the plan as approved.', 'bulk-catalog-studio' );
			wp_safe_redirect(
				add_query_arg(
					'bcs_error',
					rawurlencode( $error_msg ),
					self::page_url( array( 'view' => 'plan', 'plan' => (string) $plan_id ) )
				)
			);
			exit;
		}

		return null;
	}

	/**
	 * Render tabel before → after untuk operasi update (sudah di-escape).
	 *
	 * @param array $before  Snapshot before.
	 * @param array $payload Payload after.
	 * @return string HTML ter-escape.
	 */
	private static function render_before_after( array $before, array $payload ) {
		$fields = array(
			'name'          => __( 'Name', 'bulk-catalog-studio' ),
			'sku'           => __( 'SKU', 'bulk-catalog-studio' ),
			'regular_price' => __( 'Regular price', 'bulk-catalog-studio' ),
			'sale_price'    => __( 'Sale price', 'bulk-catalog-studio' ),
			'stock_quantity' => __( 'Stock', 'bulk-catalog-studio' ),
			'description'   => __( 'Description', 'bulk-catalog-studio' ),
			'status'        => __( 'Status', 'bulk-catalog-studio' ),
			'weight'        => __( 'Weight', 'bulk-catalog-studio' ),
		);

		$html = '<table class="widefat striped bcs-ba"><thead><tr>';
		$html .= '<th>' . esc_html__( 'Field', 'bulk-catalog-studio' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Before', 'bulk-catalog-studio' ) . '</th>';
		$html .= '<th>' . esc_html__( 'After', 'bulk-catalog-studio' ) . '</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $fields as $key => $label ) {
			$old = isset( $before[ $key ] ) ? $before[ $key ] : '';
			$new = isset( $payload[ $key ] ) ? $payload[ $key ] : '';

			if ( is_array( $old ) || is_array( $new ) ) {
				continue;
			}

			$old_s = null === $old ? '' : (string) $old;
			$new_s = null === $new ? '' : (string) $new;

			// Konten HTML dipotong untuk keterbacaan.
			if ( 'description' === $key ) {
				$old_s = wp_trim_words( wp_strip_all_tags( $old_s ), 18, '…' );
				$new_s = wp_trim_words( wp_strip_all_tags( $new_s ), 18, '…' );
			}

			if ( $old_s === $new_s && 'description' !== $key && '' === $new_s ) {
				continue;
			}

			$html .= '<tr>';
			$html .= '<td>' . esc_html( $label ) . '</td>';
			$html .= '<td>' . esc_html( $old_s ) . '</td>';
			$html .= '<td>' . esc_html( $new_s ) . '</td>';
			$html .= '</tr>';
		}

		// Stok sebelum bisa null.
		$html .= '</tbody></table>';

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * View: audit log.
	 * ------------------------------------------------------------------- */

	/**
	 * Audit log viewer per job.
	 *
	 * @param int $job_id ID job.
	 * @param int $paged  Offset.
	 * @return void
	 */
	private static function render_audit( $job_id, $paged ) {
		$job = BCS_Job_Manager::get( $job_id );

		if ( ! $job ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Job not found.', 'bulk-catalog-studio' ) . '</p></div>';
			return;
		}

		$limit  = 100;
		$page   = max( 0, (int) $paged );
		$offset = $page * $limit;

		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter read-only.
		if ( ! in_array( $level, array( 'all', 'call', 'row' ), true ) ) {
			$level = 'all';
		}

		$result = BCS_Audit_Logger::query( $job_id, $level, $limit, $offset );

		// Gate 4 (UI test): ?paged= di luar jangkauan (URL lama / data menyusut)
		// sebelumnya menampilkan tabel kosong tanpa pagination (total <= limit
		// tidak merender link) — tidak ada cara kembali. Clamp sekali ke
		// halaman valid terakhir lalu query ulang.
		$clamped = false;
		if ( empty( $result['entries'] ) && $result['total'] > 0 && $page > 0 ) {
			$page    = (int) floor( ( $result['total'] - 1 ) / $limit );
			$offset  = $page * $limit;
			$result  = BCS_Audit_Logger::query( $job_id, $level, $limit, $offset );
			$clamped = true;
		}

		echo '<p><a href="' . esc_url( self::page_url( array( 'view' => 'job', 'job' => (string) $job_id ) ) ) . '">&larr; ' . esc_html(
			sprintf(
				/* translators: %d: job id */
				__( 'Job #%d', 'bulk-catalog-studio' ),
				$job_id
			)
		) . '</a></p>';

		echo '<h2>' . esc_html__( 'Audit log', 'bulk-catalog-studio' ) . ' <span class="bcs-muted">(' . esc_html( number_format_i18n( $result['total'] ) ) . ' ' . esc_html__( 'entries', 'bulk-catalog-studio' ) . ')</span></h2>';

		// Filter level.
		echo '<p>';
		foreach ( array( 'all', 'call', 'row' ) as $opt ) {
			$url = add_query_arg(
				array( 'level' => $opt, 'paged' => '0' ),
				self::page_url( array( 'view' => 'audit', 'job' => (string) $job_id ) )
			);
			echo '<a class="button button-small' . ( $level === $opt ? ' button-primary' : '' ) . '" style="margin-right:4px" href="' . esc_url( $url ) . '">' . esc_html( $opt ) . '</a>';
		}
		echo '</p>';

		// Gate 4 (UI test): notice kecil bila halaman di-clamp.
		if ( $clamped ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Page out of range — showing the last page.', 'bulk-catalog-studio' ) . '</p></div>';
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Time', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Level', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Ability', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Row', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Product', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Actor', 'bulk-catalog-studio' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'bulk-catalog-studio' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $result['entries'] ) ) {
			echo '<tr><td colspan="11">' . esc_html__( 'No entries found.', 'bulk-catalog-studio' ) . '</td></tr>';
		}

		foreach ( $result['entries'] as $entry ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $entry['id'] ) . '</td>';
			echo '<td>' . esc_html( $entry['ts'] ) . '</td>';
			echo '<td>' . esc_html( $entry['level'] ) . '</td>';
			echo '<td>' . esc_html( $entry['ability'] ) . '</td>';
			echo '<td>' . esc_html( $entry['action'] ) . '</td>';
			echo '<td>' . esc_html( null === $entry['row_index'] ? '—' : (string) $entry['row_index'] ) . '</td>';
			echo '<td>' . esc_html( null === $entry['sku'] ? '—' : $entry['sku'] ) . '</td>';
			echo '<td>' . esc_html( null === $entry['product_id'] ? '—' : '#' . (string) $entry['product_id'] ) . '</td>';
			echo '<td>' . esc_html( $entry['status'] ) . '</td>';
			echo '<td>' . esc_html( '#' . (string) $entry['actor_user_id'] . ' via ' . $entry['actor_via'] ) . '</td>';
			echo '<td>' . esc_html( (string) $entry['message'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Pagination.
		if ( $result['total'] > $limit ) {
			echo '<p>';
			if ( $page > 0 ) {
				$prev = add_query_arg(
					array(
						'level' => $level,
						'paged' => (string) ( $page - 1 ),
					),
					self::page_url( array( 'view' => 'audit', 'job' => (string) $job_id ) )
				);
				echo '<a class="button" href="' . esc_url( $prev ) . '">&larr; ' . esc_html__( 'Previous', 'bulk-catalog-studio' ) . '</a> ';
			}
			if ( ( $page + 1 ) * $limit < $result['total'] ) {
				$next = add_query_arg(
					array(
						'level' => $level,
						'paged' => (string) ( $page + 1 ),
					),
					self::page_url( array( 'view' => 'audit', 'job' => (string) $job_id ) )
				);
				echo '<a class="button" href="' . esc_url( $next ) . '">' . esc_html__( 'Next', 'bulk-catalog-studio' ) . ' &rarr;</a>';
			}
			echo '</p>';
		}
	}

	/**
	 * CSS kecil untuk halaman (inline agar self-contained).
	 *
	 * @return void
	 */
	private static function print_styles() {
		echo '<style>
		.bcs-wrap .bcs-pill { display:inline-block; padding:1px 8px; border-radius:10px; background:#f0f0f1; font-size:12px; }
		.bcs-wrap .bcs-pill-ok { background:#c6e1c6; }
		.bcs-wrap .bcs-pill-warn { background:#ffd680; }
		.bcs-wrap .bcs-muted { color:#777; font-size:12px; }
		.bcs-wrap .bcs-summary { font-size:14px; }
		.bcs-wrap .bcs-hash { user-select:all; }
		.bcs-wrap .bcs-token-box { max-width:860px; background:#fff; border:1px solid #c3c4c7; padding:12px 16px; margin-top:16px; }
		.bcs-wrap .bcs-token-reveal { background:#fff8e5; border-left:4px solid #dba617; padding:8px 12px; margin:12px 0; }
		.bcs-wrap .bcs-token-code code { font-size:22px; letter-spacing:2px; }
		.bcs-wrap .bcs-ba td { vertical-align:top; }
		.bcs-wrap .bcs-detail { font-size:12px; color:#444; }
		</style>';
	}
}

// Load the sync screen when this admin class is used directly by a harness.
if ( file_exists( __DIR__ . '/class-bcs-settings-admin.php' ) ) {
	require_once __DIR__ . '/class-bcs-settings-admin.php';
}
if ( file_exists( __DIR__ . '/class-bcs-sync-admin.php' ) ) {
	require_once __DIR__ . '/class-bcs-sync-admin.php';
}
