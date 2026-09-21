<?php
/**
 * Koneksi BCS Sync: CRUD, policy/threshold validation, and credential crypto.
 *
 * Credentials are encrypted at rest with AES-256-CBC. The derived key is never
 * stored in the database; it is derived from the installation secrets on each
 * request.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Sync_Connection
 */
class BCS_Sync_Connection {

	/**
	 * Supported fetchers.
	 *
	 * @return array
	 */
	public static function fetchers() {
		return array( 'csv_export', 'sheets_api' );
	}

	/**
	 * Connection status values.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array( 'active', 'paused', 'error_state' );
	}

	/**
	 * Defaults for a new connection.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'name'             => '',
			'fetcher'          => 'csv_export',
			'sheet_config'     => array(),
			'credentials'      => null,
			'column_mapping'   => array(),
			'policy'           => array(
				'on_missing_row'  => 'flag',
				'conflict_policy' => 'flag_only',
				'auto_apply_mode' => 'within_threshold',
				'seen_skus'      => array(),
			),
			'thresholds'       => array(
				'max_create_update_ops'       => 50,
				'max_create_update_catalog_pct' => 10,
				'max_delete_ops'              => 10,
				'missing_row_flag_pct'        => 20,
				'price_delta_pct'             => 80,
				'stock_drop_pct'              => 90,
				'sku_regex'                   => '^[A-Za-z0-9._-]{3,64}$',
			),
			'interval_minutes' => 1,
			'status'           => 'paused',
		);
	}

	/**
	 * Insert a connection.
	 *
	 * @param array $args Connection fields.
	 * @return int|WP_Error
	 */
	public static function insert( array $args ) {
		global $wpdb;

		$validated = self::validate_args( $args, false );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$table = $wpdb->prefix . 'bcs_connections';
		$now   = self::now_mysql();
		$data  = self::storage_data( $validated, $now, $now );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom; wpdb insert prepares values.
		$inserted = $wpdb->insert( $table, $data, self::formats( $data ) );
		if ( ! $inserted ) {
			return new WP_Error( 'bcs_db_error', __( 'Failed to save the sync connection.', 'bulk-catalog-studio' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Alias yang lebih deskriptif untuk insert().
	 *
	 * @param array $args Connection fields.
	 * @return int|WP_Error
	 */
	public static function create( array $args ) {
		return self::insert( $args );
	}

	/**
	 * Ambil baris mentah, masih berisi credential terenkripsi.
	 *
	 * @param int $connection_id Connection ID.
	 * @return object|null
	 */
	public static function get_raw( $connection_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_connections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom; id diprepare.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $connection_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix terkontrol.
	}

	/**
	 * Ambil koneksi ter-normalisasi dengan credential plaintext hanya di memory.
	 *
	 * @param int $connection_id Connection ID.
	 * @return array|WP_Error|null
	 */
	public static function get( $connection_id ) {
		$raw = self::get_raw( $connection_id );
		if ( ! $raw ) {
			return null;
		}

		$connection = self::decode_row( $raw, true );
		return $connection;
	}

	/**
	 * Update koneksi. Field yang tidak dikirim dipertahankan.
	 *
	 * @param int   $connection_id Connection ID.
	 * @param array $args          Fields to update.
	 * @return true|WP_Error
	 */
	public static function update( $connection_id, array $args ) {
		global $wpdb;

		$raw = self::get_raw( $connection_id );
		if ( ! $raw ) {
			return new WP_Error( 'bcs_not_found', __( 'Sync connection not found.', 'bulk-catalog-studio' ) );
		}

		$current = self::decode_row( $raw, false );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$merged = $current;
		foreach ( $args as $key => $value ) {
			$merged[ $key ] = $value;
		}
		$validated = self::validate_args( $merged, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$old_created = ! empty( $raw->created_at ) ? (string) $raw->created_at : self::now_mysql();
		$data        = self::storage_data( $validated, $old_created, self::now_mysql(), $args, $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$table = $wpdb->prefix . 'bcs_connections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom; wpdb update prepares values.
		$updated = $wpdb->update( $table, $data, array( 'id' => absint( $connection_id ) ), self::formats( $data ), array( '%d' ) );
		if ( false === $updated ) {
			return new WP_Error( 'bcs_db_error', __( 'Failed to update the sync connection.', 'bulk-catalog-studio' ) );
		}

		return true;
	}

	/**
	 * Hapus satu koneksi. Run history sengaja tetap dipertahankan oleh DB.
	 *
	 * @param int $connection_id Connection ID.
	 * @return true|WP_Error
	 */
	public static function delete( $connection_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_connections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$deleted = $wpdb->delete( $table, array( 'id' => absint( $connection_id ) ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'bcs_db_error', __( 'Failed to delete the sync connection.', 'bulk-catalog-studio' ) );
		}
		if ( 0 === (int) $deleted ) {
			return new WP_Error( 'bcs_not_found', __( 'Sync connection not found.', 'bulk-catalog-studio' ) );
		}

		return true;
	}

	/**
	 * List connections, optionally filtered by status.
	 *
	 * @param string $status Optional status.
	 * @return array|WP_Error
	 */
	public static function list_connections( $status = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bcs_connections';
		if ( in_array( $status, self::statuses(), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nama tabel dari prefix terkontrol.
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tanpa input user; nama tabel dari prefix terkontrol.
		}

		$out = array();
		foreach ( (array) $rows as $raw ) {
			$decoded = self::decode_row( $raw, false );
			if ( is_wp_error( $decoded ) ) {
				$decoded = array(
					'id'     => (int) $raw->id,
					'name'   => (string) $raw->name,
					'status' => (string) $raw->status,
					'error'  => $decoded->get_error_message(),
				);
			}
			$out[] = $decoded;
		}

		return $out;
	}

	/**
	 * Alias list_connections() untuk callers yang memakai nama all().
	 *
	 * @param string $status Optional status.
	 * @return array|WP_Error
	 */
	public static function all( $status = '' ) {
		return self::list_connections( $status );
	}

	/**
	 * Encrypt plaintext credentials.
	 *
	 * @param string $plaintext Plaintext.
	 * @return string|WP_Error
	 */
	public static function encrypt( $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
			return new WP_Error( 'encryption_unavailable', __( 'OpenSSL is required to encrypt sync credentials.', 'bulk-catalog-studio' ) );
		}

		$cipher = 'aes-256-cbc';
		$iv_len = openssl_cipher_iv_length( $cipher );
		if ( ! $iv_len || $iv_len < 1 ) {
			return new WP_Error( 'encryption_unavailable', __( 'AES-256-CBC is unavailable on this server.', 'bulk-catalog-studio' ) );
		}

		$strong = false;
		$iv     = openssl_random_pseudo_bytes( $iv_len, $strong );
		if ( false === $iv || ! $strong ) {
			return new WP_Error( 'encryption_failed', __( 'A secure random IV could not be generated for sync credentials.', 'bulk-catalog-studio' ) );
		}

		// CBC tidak menyediakan autentikasi. Digest plaintext di dalam payload
		// membuat decrypt dapat menolak ciphertext yang telah ditamper.
		$plaintext = (string) $plaintext;
		$payload   = hash( 'sha256', $plaintext, true ) . $plaintext;
		$encrypted = openssl_encrypt( $payload, $cipher, self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return new WP_Error( 'encryption_failed', __( 'OpenSSL failed to encrypt sync credentials.', 'bulk-catalog-studio' ) );
		}

		return base64_encode( $iv ) . '.' . base64_encode( $encrypted );
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $encoded Encrypted value.
	 * @return string|WP_Error
	 */
	public static function decrypt( $encoded ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return new WP_Error( 'encryption_unavailable', __( 'OpenSSL is required to decrypt sync credentials.', 'bulk-catalog-studio' ) );
		}

		$parts = explode( '.', (string) $encoded, 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'decrypt_failed', __( 'Stored sync credentials have an invalid encrypted format.', 'bulk-catalog-studio' ) );
		}

		$iv        = base64_decode( $parts[0], true );
		$ciphertext = base64_decode( $parts[1], true );
		$iv_len    = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( false === $iv || false === $ciphertext || ! $iv_len || strlen( $iv ) !== (int) $iv_len ) {
			return new WP_Error( 'decrypt_failed', __( 'Stored sync credentials could not be decoded.', 'bulk-catalog-studio' ) );
		}

		$decrypted = openssl_decrypt( $ciphertext, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $decrypted || strlen( $decrypted ) < 32 ) {
			return new WP_Error( 'decrypt_failed', __( 'Stored sync credentials could not be decrypted.', 'bulk-catalog-studio' ) );
		}

		$digest    = substr( $decrypted, 0, 32 );
		$plaintext = substr( $decrypted, 32 );
		if ( ! hash_equals( hash( 'sha256', $plaintext, true ), $digest ) ) {
			return new WP_Error( 'decrypt_failed', __( 'Stored sync credentials failed integrity validation.', 'bulk-catalog-studio' ) );
		}

		return $plaintext;
	}

	/**
	 * Merge newly observed SKUs into policy.seen_skus.
	 *
	 * @param int   $connection_id Connection ID.
	 * @param array $skus          Current source SKU set.
	 * @return true|WP_Error
	 */
	public static function update_seen_skus( $connection_id, array $skus ) {
		global $wpdb;

		$raw = self::get_raw( $connection_id );
		if ( ! $raw ) {
			return new WP_Error( 'bcs_not_found', __( 'Sync connection not found.', 'bulk-catalog-studio' ) );
		}

		$policy = self::decode_json( $raw->policy, array() );
		if ( ! is_array( $policy ) ) {
			$policy = array();
		}
		$seen = isset( $policy['seen_skus'] ) && is_array( $policy['seen_skus'] ) ? $policy['seen_skus'] : array();
		$merged = array();
		foreach ( array_merge( $seen, $skus ) as $sku ) {
			$sku = strtoupper( trim( (string) $sku ) );
			if ( '' !== $sku ) {
				$merged[ strtolower( $sku ) ] = $sku;
			}
		}
		$policy['seen_skus'] = array_values( $merged );

		$table = $wpdb->prefix . 'bcs_connections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabel kustom.
		$updated = $wpdb->update(
			$table,
			array(
				'policy'     => wp_json_encode( $policy ),
				'updated_at' => self::now_mysql(),
			),
			array( 'id' => absint( $connection_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false === $updated ? new WP_Error( 'bcs_db_error', __( 'Failed to update the connection SKU state.', 'bulk-catalog-studio' ) ) : true;
	}

	/**
	 * Compute the next due time from last successful run and interval.
	 *
	 * @param array       $connection Connection.
	 * @param string|null $last_run_at Last successful run UTC MySQL time.
	 * @return string|null ISO-8601 UTC time or null when invalid.
	 */
	public static function next_run_at( array $connection, $last_run_at = null ) {
		$base = $last_run_at ? strtotime( (string) $last_run_at . ' UTC' ) : ( ! empty( $connection['created_at'] ) ? strtotime( (string) $connection['created_at'] . ' UTC' ) : time() );
		if ( false === $base ) {
			$base = time();
		}
		$minutes = max( 1, absint( isset( $connection['interval_minutes'] ) ? $connection['interval_minutes'] : 1 ) );

		return gmdate( 'c', $base + ( $minutes * 60 ) );
	}

	/**
	 * Validate and normalize connection data.
	 *
	 * @param array $args   Raw fields.
	 * @param bool  $update Whether this is an update.
	 * @return array|WP_Error
	 */
	public static function validate_args( array $args, $update = false ) {
		$defaults = self::defaults();
		$merged   = array_merge( $defaults, $args );

		$name = sanitize_text_field( (string) $merged['name'] );
		if ( '' === $name ) {
			return new WP_Error( 'invalid_connection', __( 'Connection name is required.', 'bulk-catalog-studio' ) );
		}
		$fetcher = sanitize_key( $merged['fetcher'] );
		if ( ! in_array( $fetcher, self::fetchers(), true ) ) {
			return new WP_Error( 'invalid_fetcher', __( 'Fetcher must be csv_export or sheets_api.', 'bulk-catalog-studio' ) );
		}
		$interval = absint( $merged['interval_minutes'] );
		if ( $interval < 1 ) {
			return new WP_Error( 'invalid_interval', __( 'Sync interval must be at least 1 minute.', 'bulk-catalog-studio' ) );
		}
		$status = sanitize_key( $merged['status'] );
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return new WP_Error( 'invalid_status', __( 'Invalid sync connection status.', 'bulk-catalog-studio' ) );
		}
		if ( ! is_array( $merged['sheet_config'] ) ) {
			return new WP_Error( 'invalid_config', __( 'Sheet configuration must be a JSON object.', 'bulk-catalog-studio' ) );
		}
		if ( ! is_array( $merged['column_mapping'] ) ) {
			return new WP_Error( 'invalid_mapping', __( 'Column mapping must be a JSON object.', 'bulk-catalog-studio' ) );
		}

		$policy = self::sanitize_policy( $merged['policy'] );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}
		$thresholds = self::sanitize_thresholds( $merged['thresholds'] );
		if ( is_wp_error( $thresholds ) ) {
			return $thresholds;
		}

		$credentials = $merged['credentials'];
		if ( null !== $credentials && ! is_scalar( $credentials ) && ! is_array( $credentials ) ) {
			return new WP_Error( 'invalid_credentials', __( 'Credentials must be a JSON string, object, or null.', 'bulk-catalog-studio' ) );
		}
		if ( is_array( $credentials ) ) {
			$credentials = wp_json_encode( $credentials );
		}

		return array(
			'name'             => substr( $name, 0, 120 ),
			'fetcher'          => $fetcher,
			'sheet_config'     => $merged['sheet_config'],
			'credentials'      => null === $credentials ? null : (string) $credentials,
			'column_mapping'   => $merged['column_mapping'],
			'policy'           => $policy,
			'thresholds'       => $thresholds,
			'interval_minutes' => $interval,
			'status'           => $status,
			'created_at'       => isset( $merged['created_at'] ) ? (string) $merged['created_at'] : '',
			'updated_at'       => isset( $merged['updated_at'] ) ? (string) $merged['updated_at'] : '',
			'last_ok_run_at'   => isset( $merged['last_ok_run_at'] ) ? $merged['last_ok_run_at'] : null,
		);
	}

	/**
	 * Default policy normalization.
	 *
	 * @param mixed $raw Policy array or JSON.
	 * @return array|WP_Error
	 */
	public static function sanitize_policy( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( null === $raw ) {
			$raw = array();
		}
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'invalid_policy', __( 'Policy must be valid JSON.', 'bulk-catalog-studio' ) );
		}
		$on_missing = isset( $raw['on_missing_row'] ) ? sanitize_key( $raw['on_missing_row'] ) : 'flag';
		if ( ! in_array( $on_missing, array( 'flag', 'hold' ), true ) ) {
			return new WP_Error( 'invalid_policy', __( 'on_missing_row must be flag or hold.', 'bulk-catalog-studio' ) );
		}
		$conflict = isset( $raw['conflict_policy'] ) ? sanitize_key( $raw['conflict_policy'] ) : 'flag_only';
		if ( 'flag_only' !== $conflict ) {
			return new WP_Error( 'invalid_policy', __( 'Only the flag_only conflict policy is supported.', 'bulk-catalog-studio' ) );
		}
		$auto = isset( $raw['auto_apply_mode'] ) ? sanitize_key( $raw['auto_apply_mode'] ) : 'within_threshold';
		if ( ! in_array( $auto, array( 'within_threshold', 'approval_required' ), true ) ) {
			return new WP_Error( 'invalid_policy', __( 'Invalid auto_apply_mode.', 'bulk-catalog-studio' ) );
		}
		$seen = isset( $raw['seen_skus'] ) && is_array( $raw['seen_skus'] ) ? $raw['seen_skus'] : array();
		$seen_clean = array();
		foreach ( $seen as $sku ) {
			$sku = strtoupper( trim( (string) $sku ) );
			if ( '' !== $sku ) {
				$seen_clean[ strtolower( $sku ) ] = $sku;
			}
		}

		return array(
			'on_missing_row'  => $on_missing,
			'conflict_policy' => 'flag_only',
			'auto_apply_mode' => $auto,
			'seen_skus'      => array_values( $seen_clean ),
		);
	}

	/**
	 * Default threshold normalization.
	 *
	 * @param mixed $raw Threshold array or JSON.
	 * @return array|WP_Error
	 */
	public static function sanitize_thresholds( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( null === $raw ) {
			$raw = array();
		}
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'invalid_thresholds', __( 'Thresholds must be valid JSON.', 'bulk-catalog-studio' ) );
		}

		// Accept a few intuitive aliases while persisting one canonical shape.
		$max_ops = self::first_numeric( $raw, array( 'max_create_update_ops', 'run_level_max_ops', 'max_ops' ), 50 );
		$catalog_pct = self::first_numeric( $raw, array( 'max_create_update_catalog_pct', 'run_level_catalog_pct', 'catalog_pct' ), 10 );
		$max_delete = self::first_numeric( $raw, array( 'max_delete_ops', 'delete_max', 'max_deletes' ), 10 );
		$missing_pct = self::first_numeric( $raw, array( 'missing_row_flag_pct', 'missing_rows_pct' ), 20 );
		$price_pct = self::first_numeric( $raw, array( 'price_delta_pct', 'price_change_pct' ), 80 );
		$stock_pct = self::first_numeric( $raw, array( 'stock_drop_pct', 'stock_decrease_pct' ), 90 );

		if ( $max_ops < 1 || $catalog_pct < 0 || $max_delete < 0 || $missing_pct < 0 || $missing_pct > 100 || $price_pct < 0 || $stock_pct < 0 || $stock_pct > 100 ) {
			return new WP_Error( 'invalid_thresholds', __( 'Threshold values are outside the supported range.', 'bulk-catalog-studio' ) );
		}
		$regex = isset( $raw['sku_regex'] ) ? (string) $raw['sku_regex'] : '^[A-Za-z0-9._-]{3,64}$';
		if ( '' === $regex || false === @preg_match( '/' . trim( $regex, '/' ) . '/', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validation only.
			return new WP_Error( 'invalid_thresholds', __( 'sku_regex must be a valid regular expression.', 'bulk-catalog-studio' ) );
		}

		return array(
			'max_create_update_ops'         => (int) $max_ops,
			'max_create_update_catalog_pct' => (float) $catalog_pct,
			'max_delete_ops'                => (int) $max_delete,
			'missing_row_flag_pct'          => (float) $missing_pct,
			'price_delta_pct'               => (float) $price_pct,
			'stock_drop_pct'                => (float) $stock_pct,
			'sku_regex'                     => trim( $regex, '/' ),
		);
	}

	/**
	 * Return first numeric key from an array.
	 *
	 * @param array $values Candidate values.
	 * @param array $keys   Candidate keys.
	 * @param mixed $default Default.
	 * @return float
	 */
	private static function first_numeric( array $values, array $keys, $default ) {
		foreach ( $keys as $key ) {
			if ( isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ) {
				return (float) $values[ $key ];
			}
		}
		return (float) $default;
	}

	/**
	 * Convert normalized data to DB columns and encrypt credentials when needed.
	 *
	 * @param array       $connection Normalized connection.
	 * @param string      $created_at Created time.
	 * @param string      $updated_at Updated time.
	 * @param array|null  $provided Fields supplied by update.
	 * @param object|null $raw Existing raw row.
	 * @return array|WP_Error
	 */
	private static function storage_data( array $connection, $created_at, $updated_at, $provided = null, $raw = null ) {
		$credentials = null;
		$should_write_credentials = null === $provided || array_key_exists( 'credentials', $provided );
		if ( ! $should_write_credentials && $raw ) {
			$credentials = $raw->credentials;
		} elseif ( null !== $connection['credentials'] ) {
			$credentials = self::encrypt( $connection['credentials'] );
			if ( is_wp_error( $credentials ) ) {
				return $credentials;
			}
		}

		return array(
			'name'             => $connection['name'],
			'fetcher'          => $connection['fetcher'],
			'sheet_config'     => wp_json_encode( $connection['sheet_config'] ),
			'credentials'      => $credentials,
			'column_mapping'   => wp_json_encode( $connection['column_mapping'] ),
			'policy'           => wp_json_encode( $connection['policy'] ),
			'thresholds'       => wp_json_encode( $connection['thresholds'] ),
			'interval_minutes' => (int) $connection['interval_minutes'],
			'status'           => $connection['status'],
			'last_ok_run_at'   => $connection['last_ok_run_at'],
			'created_at'       => $created_at,
			'updated_at'       => $updated_at,
		);
	}

	/**
	 * Decode a raw DB row.
	 *
	 * @param object $raw         Raw DB row.
	 * @param bool   $decrypt_creds Decrypt credentials.
	 * @return array|WP_Error
	 */
	private static function decode_row( $raw, $decrypt_creds ) {
		$credentials = null;
		if ( $decrypt_creds && null !== $raw->credentials && '' !== (string) $raw->credentials ) {
			$credentials = self::decrypt( $raw->credentials );
			if ( is_wp_error( $credentials ) ) {
				return $credentials;
			}
		}

		$policy = self::sanitize_policy( self::decode_json( $raw->policy, array() ) );
		$thresholds = self::sanitize_thresholds( self::decode_json( $raw->thresholds, array() ) );
		if ( is_wp_error( $policy ) || is_wp_error( $thresholds ) ) {
			return new WP_Error( 'invalid_config', __( 'Stored sync connection policy or thresholds are invalid.', 'bulk-catalog-studio' ) );
		}

		return array(
			'id'               => (int) $raw->id,
			'name'             => (string) $raw->name,
			'fetcher'          => (string) $raw->fetcher,
			'sheet_config'     => self::decode_json( $raw->sheet_config, array() ),
			'credentials'      => $credentials,
			'column_mapping'   => self::decode_json( $raw->column_mapping, array() ),
			'policy'           => $policy,
			'thresholds'       => $thresholds,
			'interval_minutes' => max( 1, (int) $raw->interval_minutes ),
			'status'           => (string) $raw->status,
			'last_ok_run_at'   => ! empty( $raw->last_ok_run_at ) ? (string) $raw->last_ok_run_at : null,
			'created_at'       => (string) $raw->created_at,
			'updated_at'       => (string) $raw->updated_at,
			'next_run_at'      => self::next_run_at( array( 'interval_minutes' => (int) $raw->interval_minutes, 'created_at' => $raw->created_at ), $raw->last_ok_run_at ),
		);
	}

	/**
	 * Decode JSON defensively.
	 *
	 * @param mixed $json JSON text.
	 * @param array $default Fallback.
	 * @return array
	 */
	private static function decode_json( $json, array $default ) {
		$value = is_array( $json ) ? $json : json_decode( (string) $json, true );
		return is_array( $value ) ? $value : $default;
	}

	/**
	 * DB formats for normalized columns.
	 *
	 * @param array $data DB data.
	 * @return array
	 */
	private static function formats( array $data ) {
		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[] = in_array( $key, array( 'interval_minutes' ), true ) ? '%d' : '%s';
		}
		return $formats;
	}

	/**
	 * Installation-derived encryption key.
	 *
	 * @return string
	 */
	private static function key() {
		$auth = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		$db   = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
		return hash( 'sha256', $auth . $db, true );
	}

	/**
	 * UTC MySQL timestamp without requiring BCS_Settings in isolated tests.
	 *
	 * @return string
	 */
	private static function now_mysql() {
		if ( class_exists( 'BCS_Settings' ) && method_exists( 'BCS_Settings', 'now_mysql' ) ) {
			return BCS_Settings::now_mysql();
		}
		return gmdate( 'Y-m-d H:i:s' );
	}
}
