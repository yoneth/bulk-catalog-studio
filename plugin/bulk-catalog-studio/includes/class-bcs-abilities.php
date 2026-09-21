<?php
/**
 * Registrar 8 ability BCS ke WordPress Abilities API + MCP Adapter (spec §1).
 *
 * Ikuti riset terverifikasi docs/research/abilities-mcp-api.md:
 * - Hook kategori: wp_abilities_api_categories_init (SEBELUM abilities init).
 * - Hook ability : wp_abilities_api_init (bukan abilities_api_init — gotcha #1).
 * - Kategori HARUS diregistrasi sebelum ability (gotcha #2: gagal senyap).
 * - meta.show_in_rest + meta.mcp.public + meta.mcp.type = 'tool'.
 * - permission_callback diperiksa ulang di dalam execute_callback (defense
 *   in depth — jangan mengandalkan transport; spec §5.5.5).
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Abilities
 */
class BCS_Abilities {

	/**
	 * Registrasi kategori 'bcs'.
	 *
	 * @return void
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'bcs',
			array(
				'label'       => __( 'Bulk Catalog Studio', 'bulk-catalog-studio' ),
				'description' => __( 'Mengubah sumber data tabular (CSV / Google Sheet export / rows prompt) menjadi produk WooCommerce native melalui pipeline parse → duplicate-check → plan → human approval → execute → rollback.', 'bulk-catalog-studio' ),
			)
		);
	}

	/**
	 * Registrasi 8 ability.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$definitions = self::definitions();

		foreach ( $definitions as $name => $args ) {
			// Guard idempotent (registry WP menolak duplikat).
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
				continue;
			}

			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Definisi lengkap 8 ability.
	 *
	 * @return array name => args
	 */
	private static function definitions() {
		return array(
			'bcs/parse-source'      => array(
				'label'               => __( 'BCS: Parse Source', 'bulk-catalog-studio' ),
				'description'         => __( 'Ambil sumber data (CSV inline, attachment media library, atau URL export CSV publik Google Sheets), parse, normalisasi header, validasi tipe, lalu buat job baru berstatus parsed. Read-only terhadap katalog: hanya menulis baris job + plan staging.', 'bulk-catalog-studio' ),
				'category'            => 'bcs',
				'input_schema'        => BCS_Schemas::input( 'bcs/parse-source' ),
				'output_schema'       => BCS_Schemas::output( 'bcs/parse-source' ),
				'execute_callback'    => array( __CLASS__, 'execute_parse_source' ),
				'permission_callback' => array( __CLASS__, 'can_edit_products' ),
				'meta'                => self::meta(
					array(
						'readonly' => false,
						'idempotent' => false,
					)
				),
			),

			'bcs/check-duplicates'  => array(
				'label'               => __( 'BCS: Check Duplicates', 'bulk-catalog-studio' ),
				'description'         => __( 'Cek duplikasi antar baris dalam job dan baris vs katalog existing (SKU exact; opsional nama normalized). Read-only — hasil disimpan di job, tidak mengubah produk.', 'bulk-catalog-studio' ),
				'category'            => 'bcs',
				'input_schema'        => BCS_Schemas::input( 'bcs/check-duplicates' ),
				'output_schema'       => BCS_Schemas::output( 'bcs/check-duplicates' ),
				'execute_callback'    => array( __CLASS__, 'execute_check_duplicates' ),
				'permission_callback' => array( __CLASS__, 'can_edit_products' ),
				'meta'                => self::meta( array( 'readonly' => true, 'idempotent' => true ) ),
			),

			'bcs/build-plan'        => array(
				'label'               => __( 'BCS: Build Plan', 'bulk-catalog-studio' ),
				'description'         => __( 'Ubah rows hasil parse + hasil duplicate-check menjadi plan eksekusi materialized (create/update/skip + payload final + snapshot before untuk update). Read-only terhadap katalog. Plan immutable: plan_hash menjadi dasar validasi approval. Token approval HANYA ditampilkan di layar admin WP.', 'bulk-catalog-studio' ),
				'category'            => 'bcs',
				'input_schema'        => BCS_Schemas::input( 'bcs/build-plan' ),
				'output_schema'       => BCS_Schemas::output( 'bcs/build-plan' ),
				'execute_callback'    => array( __CLASS__, 'execute_build_plan' ),
				'permission_callback' => array( __CLASS__, 'can_edit_products' ),
				'meta'                => self::meta( array( 'readonly' => false, 'idempotent' => true ) ),
			),

			'bcs/approve-plan'      => array(
				'label'               => __( 'BCS: Approve Plan', 'bulk-catalog-studio' ),
				'description'         => __( 'Gerbang approval manusia: validasi confirmation_token one-time (hanya tampil di layar admin WP diff) terhadap plan_hash, lalu transisi planned → approved. Token single-use, kedaluwarsa 24 jam, terikat plan_hash.', 'bulk-catalog-studio' ),
				'category'            => 'bcs',
				'input_schema'        => BCS_Schemas::input( 'bcs/approve-plan' ),
				'output_schema'       => BCS_Schemas::output( 'bcs/approve-plan' ),
				'execute_callback'    => array( __CLASS__, 'execute_approve_plan' ),
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'meta'                => self::meta( array( 'readonly' => false, 'destructive' => false ) ),
			),

			'bcs/execute-batch'     => array(
				'label'               => __( 'BCS: Execute Batch', 'bulk-catalog-studio' ),
				'description'         => __( 'Eksekusi plan yang sudah approved lewat WC_Product CRUD dalam chunk 10 operasi dengan heartbeat; idempotent per baris (row_key); mendukung dry_run (simulasi tanpa tulis) dan resume (lanjutkan job executing yang stale). Satu-satunya ability yang membuat/mengubah produk.', 'bulk-catalog-studio' ),
				'category'            => 'bcs',
				'input_schema'        => BCS_Schemas::input( 'bcs/execute-batch' ),
				'output_schema'       => BCS_Schemas::output( 'bcs/execute-batch' ),
				'execute_callback'    => array( __CLASS__, 'execute_execute_batch' ),
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'meta'                => self::meta( array( 'readonly' => false, 'idempotent' => true ) ),
			),

			'bcs/job-status'        => array(
				'label'               => __( 'BCS: Job Status', 'bulk-catalog-studio' ),
				'description'         => __( 'Tanpa job_id: list 20 job terbaru (untuk resuming antar sesi). Dengan job_id: detail satu job termasuk staleness heartbeat. Read-only.', 'bulk-catalog-studio' ),
				'category'            => 'bcs',
				'input_schema'        => BCS_Schemas::input( 'bcs/job-status' ),
				'output_schema'       => BCS_Schemas::output( 'bcs/job-status' ),
				'execute_callback'    => array( __CLASS__, 'execute_job_status' ),
				'permission_callback' => array( __CLASS__, 'can_edit_products' ),
				'meta'                => self::meta( array( 'readonly' => true, 'idempotent' => true ) ),
			),

			'bcs/rollback-batch'    => array(
				'label'               => __( 'BCS: Rollback Batch', 'bulk-catalog-studio' ),
				'description'         => __( 'Batalkan efek job done/partially_failed: produk baru → trash beserta variasinya (soft), produk update → dikembalikan ke snapshot before. Wajib confirm=true. Teraudit per baris.', 'bulk-catalog-studio' ),
				'category'            => 'bcs',
				'input_schema'        => BCS_Schemas::input( 'bcs/rollback-batch' ),
				'output_schema'       => BCS_Schemas::output( 'bcs/rollback-batch' ),
				'execute_callback'    => array( __CLASS__, 'execute_rollback_batch' ),
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'meta'                => self::meta( array( 'readonly' => false, 'destructive' => true ) ),
			),

			'bcs/audit-log'         => array(
				'label'               => __( 'BCS: Audit Log', 'bulk-catalog-studio' ),
				'description'         => __( 'Tarik entri audit untuk satu job (level call dan row), urut waktu, paginasi. Untuk verifikasi manusia maupun Claude. Read-only.', 'bulk-catalog-studio' ),
				'category'            => 'bcs',
				'input_schema'        => BCS_Schemas::input( 'bcs/audit-log' ),
				'output_schema'       => BCS_Schemas::output( 'bcs/audit-log' ),
				'execute_callback'    => array( __CLASS__, 'execute_audit_log' ),
				'permission_callback' => array( __CLASS__, 'can_edit_products' ),
				'meta'                => self::meta( array( 'readonly' => true, 'idempotent' => true ) ),
			),
		);
	}

	/**
	 * Meta standar semua ability (spec §1):
	 * show_in_rest=true, mcp.public=true, mcp.type='tool' + annotations.
	 *
	 * @param array $annotations Hints tambahan (readonly/destructive/idempotent).
	 * @return array
	 */
	private static function meta( array $annotations ) {
		$base = array(
			'instructions' => __( 'Bagian dari pipeline Bulk Catalog Studio. Ikuti urutan gerbang: parse → duplicate-check → build-plan → approval manusia (token dari layar admin) → execute. Jangan mencoba menulis produk tanpa approval.', 'bulk-catalog-studio' ),
		);

		return array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations'  => array_merge( $base, $annotations ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Permission callbacks (spec §1.9).
	 * ------------------------------------------------------------------- */

	/**
	 * Read-only ability: edit_products.
	 *
	 * @return bool|WP_Error
	 */
	public static function can_edit_products() {
		if ( current_user_can( 'edit_products' ) ) {
			return true;
		}

		return new WP_Error( 'bcs_forbidden', __( 'Butuh capability edit_products.', 'bulk-catalog-studio' ) );
	}

	/**
	 * Write ability: manage_woocommerce (keputusan gate1 #3).
	 *
	 * @return bool|WP_Error
	 */
	public static function can_manage_woocommerce() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error( 'bcs_forbidden', __( 'Butuh capability manage_woocommerce.', 'bulk-catalog-studio' ) );
	}

	/* ---------------------------------------------------------------------
	 * Guard bersama execute_callback (spec §5.5.5: pemeriksaan ulang di
	 * dalam server; §5.3 rate limit).
	 * ------------------------------------------------------------------- */

	/**
	 * Re-check capability + rate limit di dalam execute_callback.
	 *
	 * @param string $capability Capability yang disyaratkan.
	 * @param string $ability    Nama ability untuk audit.
	 * @return true|WP_Error
	 */
	private static function server_guard( $capability, $ability ) {
		// Defense-in-depth: transport bukan pengganti otorisasi (spec §5.5.5).
		if ( ! current_user_can( $capability ) ) {
			$error = new WP_Error( 'bcs_forbidden', __( 'Capability tidak memenuhi syarat (diperiksa ulang di server).', 'bulk-catalog-studio' ) );

			BCS_Audit_Logger::log_call( $ability, 'call_guard', 'failed', $error->get_error_message() );

			return $error;
		}

		// Rate limit sliding-window per user (spec §5.3).
		$limit = (int) BCS_Settings::get( 'rate_limit_per_minute', 60 );
		$window = 60;
		$bucket = (int) floor( time() / $window );
		$key    = 'bcs_rate_' . get_current_user_id() . '_' . $bucket;

		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			$error = new WP_Error(
				'bcs_rate_limited',
				sprintf(
					/* translators: 1: limit, 2: satuan waktu */
					__( 'Melebihi rate limit %1$d panggilan per %2$s. Coba lagi sebentar.', 'bulk-catalog-studio' ),
					$limit,
					__( 'menit', 'bulk-catalog-studio' )
				)
			);

			BCS_Audit_Logger::log_call( $ability, 'call_guard', 'failed', $error->get_error_message() );

			return $error;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * Ambil field dari input dengan sanitasi dasar.
	 *
	 * @param array  $input   Input ability.
	 * @param string $key     Kunci.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private static function in( $input, $key, $default = null ) {
		if ( is_array( $input ) && array_key_exists( $key, $input ) ) {
			return $input[ $key ];
		}

		return $default;
	}

	/* ---------------------------------------------------------------------
	 * Execute callbacks.
	 * ------------------------------------------------------------------- */

	/**
	 * bcs/parse-source.
	 *
	 * @param array $input Input tervalidasi schema.
	 * @return array|WP_Error
	 */
	public static function execute_parse_source( $input ) {
		$guard = self::server_guard( 'edit_products', 'bcs/parse-source' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$source_type = (string) self::in( $input, 'source_type', '' );
		$delimiter   = (string) self::in( $input, 'delimiter', ',' );
		$header_row  = (bool) self::in( $input, 'header_row', true );
		$mapping     = (array) self::in( $input, 'column_mapping', array() );
		$notes       = (string) self::in( $input, 'notes', '' );
		$source_name = (string) self::in( $input, 'source_name', '' );

		/* --- Ambil isi sumber --- */
		$csv_text = '';

		if ( 'inline_csv' === $source_type ) {
			$csv_text = (string) self::in( $input, 'csv_text', '' );

			if ( '' === trim( $csv_text ) ) {
				return new WP_Error( 'bcs_invalid_input', __( 'csv_text wajib diisi bila source_type=inline_csv.', 'bulk-catalog-studio' ) );
			}

			if ( strlen( $csv_text ) > (int) BCS_Settings::get( 'max_inline_bytes', 2000000 ) ) {
				return new WP_Error( 'bcs_too_large', __( 'csv_text melebihi batas 2 MB.', 'bulk-catalog-studio' ) );
			}
		} elseif ( 'attachment' === $source_type ) {
			$attachment_id = absint( self::in( $input, 'attachment_id', 0 ) );

			if ( $attachment_id < 1 ) {
				return new WP_Error( 'bcs_invalid_input', __( 'attachment_id wajib diisi bila source_type=attachment.', 'bulk-catalog-studio' ) );
			}

			$attachment = get_post( $attachment_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return new WP_Error( 'bcs_invalid_input', __( 'attachment_id bukan post_type attachment.', 'bulk-catalog-studio' ) );
			}

			// NIT-1 (retur QA-1): .xls biner dihapus — tidak bisa diparse fgetcsv.
			$allowed_mimes = array( 'text/csv', 'text/plain', 'application/csv' );
			if ( ! in_array( (string) $attachment->post_mime_type, $allowed_mimes, true ) ) {
				return new WP_Error(
					'bcs_invalid_input',
					sprintf(
						/* translators: %s: mime type */
						__( 'Mime attachment "%s" tidak didukung (harus CSV/text).', 'bulk-catalog-studio' ),
						(string) $attachment->post_mime_type
					)
				);
			}

			$file = get_attached_file( $attachment_id );
			if ( ! $file || ! file_exists( $file ) ) {
				return new WP_Error( 'bcs_invalid_input', __( 'File attachment tidak ditemukan di disk.', 'bulk-catalog-studio' ) );
			}

			if ( filesize( $file ) > (int) BCS_Settings::get( 'max_attachment_bytes', 10485760 ) ) {
				return new WP_Error( 'bcs_too_large', __( 'Attachment melebihi batas 10 MB.', 'bulk-catalog-studio' ) );
			}

			$csv_text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- baca file attachment milik situs.

			if ( '' === $source_name ) {
				$source_name = $attachment->post_title;
			}
		} elseif ( 'url' === $source_type ) {
			if ( ! (bool) BCS_Settings::get( 'allow_url_source', true ) ) {
				return new WP_Error( 'bcs_disabled', __( 'Sumber URL dinonaktifkan di settings.', 'bulk-catalog-studio' ) );
			}

			$url = esc_url_raw( (string) self::in( $input, 'url', '' ) );

			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				return new WP_Error( 'bcs_invalid_input', __( 'url tidak valid.', 'bulk-catalog-studio' ) );
			}

			// Hanya https (spec §1.1).
			if ( 0 !== strpos( $url, 'https://' ) ) {
				return new WP_Error( 'bcs_invalid_input', __( 'Hanya URL https yang diizinkan (spec §1.1).', 'bulk-catalog-studio' ) );
			}

			$host = wp_parse_url( $url, PHP_URL_HOST );

			// MAJOR-1 (retur QA-1): V1 mengunci host ke docs.google.com
			// (selaras gate1 #1 — hanya export CSV publik). Filter tersedia
			// untuk deploy yang sengaja memperluas, tanggung jawab di admin.
			if ( ! self::is_host_allowed( (string) $host ) ) {
				return new WP_Error(
					'bcs_host_not_allowed',
					sprintf(
						/* translators: 1: host */
						__( 'Host "%1$s" tidak diizinkan. V1 hanya menerima export CSV publik dari docs.google.com (batas SSRF, retur QA-1).', 'bulk-catalog-studio' ),
						(string) $host
					)
				);
			}

			// MAJOR-1: khusus Google Sheets wajib endpoint export CSV.
			if ( false === strpos( $url, 'output=csv' ) && false === strpos( $url, 'format=csv' ) ) {
				return new WP_Error( 'bcs_invalid_input', __( 'Google Sheets harus memakai endpoint export CSV publik (…/export?format=csv).', 'bulk-catalog-studio' ) );
			}

			// MAJOR-1: resolve host + tolak IP privat/loopback/link-local
			// (defense-in-depth bila whitelist diperluas via filter).
			$host_ip = self::resolve_host_ip( (string) $host );
			if ( is_wp_error( $host_ip ) ) {
				return $host_ip;
			}

			// MAJOR-1: matikan redirect — hop tanpa re-validasi = jalur SSRF.
			$response = wp_remote_get( $url, array(
				'timeout'     => 30,
				'redirection' => 0,
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error(
					'bcs_fetch_failed',
					sprintf(
						/* translators: %d: HTTP status */
						__( 'Gagal mengambil URL (HTTP %d). Pastikan sheet publik.', 'bulk-catalog-studio' ),
						(int) wp_remote_retrieve_response_code( $response )
					)
				);
			}

			$csv_text = (string) wp_remote_retrieve_body( $response );

			if ( '' === $source_name ) {
				$source_name = $host;
			}
		} else {
			return new WP_Error( 'bcs_invalid_input', __( 'source_type tidak dikenal.', 'bulk-catalog-studio' ) );
		}

		/* --- Parse --- */
		$source_hash = sha1( $csv_text );

		$parsed = BCS_CSV_Parser::parse( $csv_text, $delimiter, $header_row, $mapping, $source_hash );

		if ( is_wp_error( $parsed ) ) {
			BCS_Audit_Logger::log_call( 'bcs/parse-source', 'parse_source', 'failed', $parsed->get_error_message(), 0, 0 );
			return $parsed;
		}

		/* --- Buat job --- */
		$mapping_meta = array(
			'column_mapping' => $mapping,
			'delimiter'      => $delimiter,
			'header_row'     => $header_row,
			'notes'          => sanitize_textarea_field( $notes ),
			'source_type'    => $source_type,
		);

		$job_id = BCS_Job_Manager::create(
			array(
				'source_type' => $source_type,
				'source_name' => $source_name,
				'source_hash' => $source_hash,
				'total_rows'  => count( $parsed['rows'] ),
				'mapping'     => $mapping_meta,
			)
		);

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		// Simpan hasil parse ke plan staging v0.
		$staged = BCS_Plan_Builder::store_staging( $job_id, $parsed );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}

		// Transisi draft → parsed.
		$transition = BCS_Job_Manager::transition( $job_id, 'parsed', 'bcs/parse-source' );
		if ( is_wp_error( $transition ) ) {
			return $transition;
		}

		// Deteksi upload ganda via source_hash.
		$warnings = (array) $parsed['warnings'];
		$duplicate_of = self::find_job_by_source_hash( $source_hash, $job_id );
		if ( $duplicate_of ) {
			$warnings[] = sprintf(
				/* translators: %d: job id */
				__( 'Sumber identik (sha1 sama) dengan job #%d — kemungkinan upload ganda.', 'bulk-catalog-studio' ),
				$duplicate_of
			);
		}

		BCS_Audit_Logger::log_call(
			'bcs/parse-source', 'parse_source', 'ok',
			sprintf(
				/* translators: 1: id job, 2: jumlah baris */
				__( 'Job #%1$d dibuat dengan %2$d baris.', 'bulk-catalog-studio' ),
				$job_id,
				count( $parsed['rows'] )
			),
			$job_id, 0, $mapping_meta
		);

		return array(
			'job_id'           => $job_id,
			'status'           => 'parsed',
			'source_hash'      => $source_hash,
			'total_rows'       => count( $parsed['rows'] ),
			'detected_columns' => array_map( 'strval', (array) $parsed['detected_columns'] ),
			'unmapped_columns' => array_map( 'strval', (array) $parsed['unmapped_columns'] ),
			'warnings'         => array_map( 'strval', $warnings ),
			'errors'           => array_map( 'strval', (array) $parsed['errors'] ),
		);
	}

	/**
	 * Host sumber URL diizinkan? (MAJOR-1 retur QA-1)
	 * V1: hanya docs.google.com; dapat diperluas via filter
	 * `bcs_allowed_source_hosts` (blokir IP privat tetap berlaku).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_host_allowed( $host ) {
		$host = strtolower( trim( (string) $host ) );

		$allowed = apply_filters(
			'bcs_allowed_source_hosts',
			array( 'docs.google.com' )
		);

		return in_array( $host, array_map( 'strtolower', (array) $allowed ), true );
	}

	/**
	 * Resolve host ke IP + tolak IP privat/loopback/link-local.
	 *
	 * @param string $host Host.
	 * @return true|WP_Error
	 */
	public static function resolve_host_ip( $host ) {
		$host = trim( (string) $host );

		if ( '' === $host ) {
			return new WP_Error( 'bcs_invalid_input', __( 'Host URL kosong.', 'bulk-catalog-studio' ) );
		}

		// Jika host itu sendiri literal IP, validasi langsung.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
		} else {
			$resolved = gethostbyname( $host );
			if ( $resolved === $host ) {
				return new WP_Error(
					'bcs_dns_failure',
					__( 'Gagal me-resolve host sumber — URL ditolak.', 'bulk-catalog-studio' )
				);
			}
			$ips = array( $resolved );
		}

		foreach ( $ips as $ip ) {
			if ( self::is_ip_private( $ip ) ) {
				return new WP_Error(
					'bcs_private_ip_blocked',
					sprintf(
						/* translators: 1: IP */
						__( 'Host me-resolve ke IP privat/loopback/link-local (%1$s) — diblokir (SSRF, retur QA-1).', 'bulk-catalog-studio' ),
						(string) $ip
					)
				);
			}
		}

		return true;
	}

	/**
	 * IP termasuk range privat/loopback/link-local?
	 * (127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, 0/8, 100.64/10 untuk
	 * IPv4; ::1, fc00::/7, fe80::/10 untuk IPv6.)
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	public static function is_ip_private( $ip ) {
		$ip = trim( (string) $ip );

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true; // Tidak valid → anggap berbahaya.
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $packed ) {
				return true;
			}
			if ( '::1' === $ip ) {
				return true;
			}
			// fc00::/7 (unique local) dan fe80::/10 (link-local).
			$first = ord( $packed[0] );
			$second = ord( $packed[1] );
			if ( 0xFC === ( $first & 0xFE ) ) {
				return true; // fc00::/7.
			}
			if ( 0xFE === $first && 0x80 === ( $second & 0xC0 ) ) {
				return true; // fe80::/10.
			}
			return false;
		}

		$long = ip2long( $ip );
		if ( false === $long ) {
			return true;
		}

		// Prefix presisi per range: 0/8, 10/8, 100.64/10 (CGNAT), 127/8,
		// 169.254/16 (link-local, termasuk metadata cloud), 172.16/12, 192.168/16.
		$private_ranges = array(
			array( '0.0.0.0', 8 ),
			array( '10.0.0.0', 8 ),
			array( '100.64.0.0', 10 ),
			array( '127.0.0.0', 8 ),
			array( '169.254.0.0', 16 ),
			array( '172.16.0.0', 12 ),
			array( '192.168.0.0', 16 ),
		);

		foreach ( $private_ranges as $range ) {
			list( $base, $prefix ) = $range;
			$base_long = ip2long( $base );
			if ( false === $base_long ) {
				continue;
			}
			$mask = -1 << ( 32 - $prefix );
			if ( ( $long & $mask ) === $base_long ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cari job lain dengan source_hash sama.
	 *
	 * @param string $source_hash sha1.
	 * @param int    $exclude_id  Job id yang dikecualikan.
	 * @return int|null
	 */
	private static function find_job_by_source_hash( $source_hash, $exclude_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE source_hash = %s AND id != %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix, nilai via prepare.
				$source_hash,
				absint( $exclude_id )
			)
		);

		return $found ? (int) $found : null;
	}

	/**
	 * bcs/check-duplicates.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function execute_check_duplicates( $input ) {
		$guard = self::server_guard( 'edit_products', 'bcs/check-duplicates' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$job_id       = absint( self::in( $input, 'job_id', 0 ) );
		$strategy     = (string) self::in( $input, 'strategy', 'sku+title' );
		$on_duplicate = (string) self::in( $input, 'on_duplicate', 'flag_only' );

		$result = BCS_Duplicate_Checker::check( $job_id, $strategy, $on_duplicate );

		if ( is_wp_error( $result ) ) {
			BCS_Audit_Logger::log_call( 'bcs/check-duplicates', 'check_duplicates', 'failed', $result->get_error_message(), $job_id );
			return $result;
		}

		// Simpan hasil ke staging + bawa kebijakan default ke job.
		BCS_Plan_Builder::store_duplicate_check( $job_id, $result );
		BCS_Job_Manager::update_fields(
			$job_id,
			array(
				'mapping_json' => wp_json_encode(
					array_merge(
						BCS_Job_Manager::get( $job_id )['mapping'],
						array(
							'duplicate_policy'   => $result['on_duplicate_effective'],
							'duplicate_strategy' => $strategy,
							'duplicate_count'    => $result['duplicate_count'],
						)
					)
				),
			)
		);

		BCS_Audit_Logger::log_call(
			'bcs/check-duplicates', 'check_duplicates', 'ok',
			sprintf(
				/* translators: 1: jumlah duplikat */
				__( 'Ditemukan %d duplikat.', 'bulk-catalog-studio' ),
				(int) $result['duplicate_count']
			),
			$job_id, 0, $result
		);

		return $result;
	}

	/**
	 * bcs/build-plan.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function execute_build_plan( $input ) {
		$guard = self::server_guard( 'edit_products', 'bcs/build-plan' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$job_id        = absint( self::in( $input, 'job_id', 0 ) );
		$on_duplicate  = (string) self::in( $input, 'on_duplicate', '' );
		$defaults      = (array) self::in( $input, 'defaults', array() );
		$image_policy  = (array) self::in( $input, 'image_policy', array() );
		$row_overrides = (array) self::in( $input, 'row_overrides', array() );

		$result = BCS_Plan_Builder::build( $job_id, $on_duplicate, $defaults, $image_policy, $row_overrides );

		if ( is_wp_error( $result ) ) {
			BCS_Audit_Logger::log_call( 'bcs/build-plan', 'build_plan', 'failed', $result->get_error_message(), $job_id );
			return $result;
		}

		BCS_Audit_Logger::log_call(
			'bcs/build-plan', 'build_plan', 'ok',
			sprintf(
				/* translators: 1: plan id, 2: total operasi */
				__( 'Plan #%1$d dibangun dengan %2$d operasi.', 'bulk-catalog-studio' ),
				(int) $result['plan_id'],
				(int) $result['summary']['total_operations']
			),
			$job_id, (int) $result['plan_id'], $result['summary']
		);

		return $result;
	}

	/**
	 * bcs/approve-plan.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function execute_approve_plan( $input ) {
		$guard = self::server_guard( 'manage_woocommerce', 'bcs/approve-plan' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$plan_id  = absint( self::in( $input, 'plan_id', 0 ) );
		$plan_hash = (string) self::in( $input, 'plan_hash', '' );
		$token    = (string) self::in( $input, 'confirmation_token', '' );

		$plan_row = BCS_Plan_Builder::get_plan_row( $plan_id );
		if ( ! $plan_row ) {
			return new WP_Error( 'bcs_not_found', __( 'Plan tidak ditemukan.', 'bulk-catalog-studio' ) );
		}

		$job_id = (int) $plan_row->job_id;

		// Gate plan: hanya plan berstatus planned yang bisa diapprove —
		// plan 'superseded' (digantikan rebuild, MINOR-2 retur QA-1) ditolak.
		if ( 'planned' !== (string) $plan_row->status ) {
			$error = new WP_Error(
				'bcs_invalid_state',
				sprintf(
					/* translators: %s: status plan */
					__( 'approve-plan hanya untuk plan berstatus planned (status plan saat ini: %s — kemungkinan sudah digantikan plan lebih baru).', 'bulk-catalog-studio' ),
					(string) $plan_row->status
				)
			);
			BCS_Audit_Logger::log_call( 'bcs/approve-plan', 'approve_plan', 'failed', $error->get_error_message(), $job_id, $plan_id );

			return $error;
		}

		// State gate: job harus planned.
		$job = BCS_Job_Manager::get( $job_id );
		if ( ! $job || 'planned' !== $job['status'] ) {
			$error = new WP_Error(
				'bcs_invalid_state',
				sprintf(
					/* translators: %s: status job */
					__( 'approve-plan hanya untuk job berstatus planned (status saat ini: %s).', 'bulk-catalog-studio' ),
					$job ? $job['status'] : 'unknown'
				)
			);
			BCS_Audit_Logger::log_call( 'bcs/approve-plan', 'approve_plan', 'failed', $error->get_error_message(), $job_id, $plan_id );

			return $error;
		}

		// Verifikasi token (single-use, expire, terikat plan_hash).
		$verify = BCS_Approval_Token::verify( $plan_id, $plan_hash, $token );
		if ( is_wp_error( $verify ) ) {
			BCS_Audit_Logger::log_call( 'bcs/approve-plan', 'approve_plan', 'failed', $verify->get_error_message(), $job_id, $plan_id );

			return new WP_Error(
				$verify->get_error_code(),
				sprintf(
					/* translators: 1: alasan */
					__( 'Approval ditolak: %1$s', 'bulk-catalog-studio' ),
					$verify->get_error_message()
				)
			);
		}

		// MAJOR-2 (retur QA-1): transisi job DULU (atomik — hanya satu proses
		// yang menang), baru konsumsi token secara atomik
		// (WHERE token_used_at IS NULL). Urutan ini menjamin token tidak
		// terbakar bila transisi gagal.
		$transition = BCS_Job_Manager::transition( $job_id, 'approved', 'bcs/approve-plan' );
		if ( is_wp_error( $transition ) ) {
			return $transition;
		}

		$consume = BCS_Approval_Token::consume( $plan_id, get_current_user_id() );
		if ( is_wp_error( $consume ) ) {
			// Teoretis (pemenang transisi tunggal seharusnya menang consume);
			// tetap dilaporkan + teraudit agar invariant terlihat.
			BCS_Audit_Logger::log_call( 'bcs/approve-plan', 'approve_plan', 'failed', $consume->get_error_message(), $job_id, $plan_id );

			return $consume;
		}

		BCS_Plan_Builder::set_status( $plan_id, 'approved' );

		BCS_Audit_Logger::log_call(
			'bcs/approve-plan', 'approve_plan', 'ok',
			sprintf(
				/* translators: 1: plan id, 2: user id */
				__( 'Plan #%1$d diapprove oleh user #%2$d via token one-time.', 'bulk-catalog-studio' ),
				$plan_id,
				get_current_user_id()
			),
			$job_id, $plan_id
		);

		return array(
			'plan_id'     => $plan_id,
			'job_id'      => $job_id,
			'status'      => 'approved',
			'approved_by' => get_current_user_id(),
			'approved_at' => gmdate( 'c' ),
			'note'        => '',
		);
	}

	/**
	 * bcs/execute-batch.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function execute_execute_batch( $input ) {
		$guard = self::server_guard( 'manage_woocommerce', 'bcs/execute-batch' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$plan_id = absint( self::in( $input, 'plan_id', 0 ) );
		$dry_run = (bool) self::in( $input, 'dry_run', false );
		$resume  = (bool) self::in( $input, 'resume', false );

		$result = BCS_Executor::execute( $plan_id, $dry_run, $resume );

		// Kegagalan pre-state (not found / invalid state) sudah diaudit di executor.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * bcs/job-status.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function execute_job_status( $input ) {
		$guard = self::server_guard( 'edit_products', 'bcs/job-status' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$job_id = absint( self::in( $input, 'job_id', 0 ) );

		/* --- Detail satu job --- */
		if ( $job_id > 0 ) {
			$job = BCS_Job_Manager::get( $job_id );

			if ( ! $job ) {
				return new WP_Error( 'bcs_not_found', __( 'Job tidak ditemukan.', 'bulk-catalog-studio' ) );
			}

			return array(
				'jobs' => array(
					array(
						'job_id'       => $job['id'],
						'status'       => $job['status'],
						'source_name'  => $job['source_name'],
						'total_rows'   => $job['total_rows'],
						'counts'       => $job['counts'],
						'created_at'   => $job['created_at'] ? mysql2date( 'c', $job['created_at'] ) : gmdate( 'c' ),
						'updated_at'   => $job['updated_at'] ? mysql2date( 'c', $job['updated_at'] ) : gmdate( 'c' ),
						'heartbeat_at' => $job['heartbeat_at'] ? mysql2date( 'c', $job['heartbeat_at'] ) : null,
						'stale'        => BCS_Job_Manager::is_stale( (object) array(
							'status'       => $job['status'],
							'heartbeat_at' => $job['heartbeat_at'],
						) ),
					),
				),
			);
		}

		/* --- List 20 job terbaru --- */
		$jobs = array();

		foreach ( BCS_Job_Manager::list_jobs( 20 ) as $job ) {
			$jobs[] = array(
				'job_id'       => $job['id'],
				'status'       => $job['status'],
				'source_name'  => $job['source_name'],
				'total_rows'   => $job['total_rows'],
				'counts'       => $job['counts'],
				'created_at'   => $job['created_at'] ? mysql2date( 'c', $job['created_at'] ) : gmdate( 'c' ),
				'updated_at'   => $job['updated_at'] ? mysql2date( 'c', $job['updated_at'] ) : gmdate( 'c' ),
				'heartbeat_at' => $job['heartbeat_at'] ? mysql2date( 'c', $job['heartbeat_at'] ) : null,
				'stale'        => (bool) $job['stale'],
			);
		}

		BCS_Audit_Logger::log_call( 'bcs/job-status', 'job_status', 'ok', sprintf( /* translators: %d: jumlah job */ __( 'List %d job.', 'bulk-catalog-studio' ), count( $jobs ) ) );

		return array( 'jobs' => $jobs );
	}

	/**
	 * bcs/rollback-batch.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function execute_rollback_batch( $input ) {
		$guard = self::server_guard( 'manage_woocommerce', 'bcs/rollback-batch' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$job_id  = absint( self::in( $input, 'job_id', 0 ) );
		$confirm = self::in( $input, 'confirm', false );
		$scope   = (string) self::in( $input, 'scope', 'all' );

		$result = BCS_Rollback_Service::rollback( $job_id, $confirm, $scope );

		if ( is_wp_error( $result ) ) {
			BCS_Audit_Logger::log_call( 'bcs/rollback-batch', 'rollback_batch', 'failed', $result->get_error_message(), $job_id );

			return $result;
		}

		return $result;
	}

	/**
	 * bcs/audit-log.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function execute_audit_log( $input ) {
		$guard = self::server_guard( 'edit_products', 'bcs/audit-log' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$job_id = absint( self::in( $input, 'job_id', 0 ) );
		$level  = (string) self::in( $input, 'level', 'all' );
		$limit  = absint( self::in( $input, 'limit', 50 ) );
		$offset = absint( self::in( $input, 'offset', 0 ) );

		$job = BCS_Job_Manager::get( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'bcs_not_found', __( 'Job tidak ditemukan.', 'bulk-catalog-studio' ) );
		}

		$result = BCS_Audit_Logger::query( $job_id, $level, $limit, $offset );

		BCS_Audit_Logger::log_call(
			'bcs/audit-log', 'audit_log', 'ok',
			sprintf(
				/* translators: 1: total entri */
				__( 'Tarik %d entri audit.', 'bulk-catalog-studio' ),
				count( $result['entries'] )
			),
			$job_id
		);

		return array(
			'job_id'  => $job_id,
			'total'   => $result['total'],
			'entries' => $result['entries'],
		);
	}
}
