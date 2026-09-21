<?php
/**
 * CSV parser + normalizer untuk bcs/parse-source (spec §1.1, §5.3).
 *
 * Fitur:
 * - Delimiter koma / titik-koma / tab.
 * - Header row opsional + column_mapping header → field kanonik.
 * - Auto-detect mapping bila header sudah bernama field kanonik.
 * - Validasi tipe: harga numerik, stok numerik, dimensi numerik.
 * - Batas baris per job (setting, hard limit 500 — AC-12).
 * - Multi-nilai untuk produk variabel dipisah karakter pipe "|".
 * - Kolom hierarki kategori "Induk > Anak", tag dipisah koma.
 * - Image: nilai numerik → attachment_id; URL → diabaikan di V1 (gate1 #4).
 *
 * Output normalisasi per baris mengikuti kontrak plan §3.3.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_CSV_Parser
 */
class BCS_CSV_Parser {

	/**
	 * Field kanonik non-variabel (spec §1.1 column_mapping) + alias tambahan.
	 *
	 * @return array
	 */
	public static function canonical_fields() {
		return array(
			'name',
			'description',
			'short_description',
			'sku',
			'regular_price',
			'sale_price',
			'stock_quantity',
			'weight',
			'length',
			'width',
			'height',
			'categories',
			'tags',
			'variation_sku',
			'variation_regular_price',
			'variation_sale_price',
			'variation_stock_quantity',
			'variation_weight',
			'image_url',
			'image_id',
			'image_alt',
			'seo_title',
			'seo_description',
		);
	}

	/**
	 * Parse + normalisasi satu sumber CSV.
	 *
	 * @param string $csv_text      Isi CSV.
	 * @param string $delimiter     ',' | ';' | 'tab'.
	 * @param bool   $header_row    Baris pertama adalah header?
	 * @param array  $column_mapping Peta header → field kanonik.
	 * @param string $source_hash   sha1 sumber (untuk row_key).
	 * @return array|WP_Error { rows, detected_columns, unmapped_columns, warnings, errors }
	 */
	public static function parse( $csv_text, $delimiter = ',', $header_row = true, array $column_mapping = array(), $source_hash = '' ) {
		$warnings = array();
		$errors   = array();

		if ( '' === trim( (string) $csv_text ) ) {
			return new WP_Error( 'bcs_empty_source', __( 'Empty CSV source.', 'bulk-catalog-studio' ) );
		}

		// Normalisasi delimiter + strip BOM + konversi encoding (MINOR-7 retur
		// QA-1: CSV Windows-1252/ISO-8859-1 dari Excel umum — jangan simpan
		// karakter rusak permanen ke produk).
		$csv_text = self::strip_bom( (string) $csv_text );
		$encoding_warning = self::maybe_convert_to_utf8( $csv_text );
		if ( null !== $encoding_warning ) {
			$warnings[] = $encoding_warning;
		}
		$delim    = self::delimiter_char( $delimiter );

		// Baca seluruh baris via php://temp (aman memory untuk 2 MB).
		$handle = fopen( 'php://temp', 'r+' );
		if ( ! $handle ) {
			return new WP_Error( 'bcs_parse_failed', __( 'Unable to open the parse buffer.', 'bulk-catalog-studio' ) );
		}
		fwrite( $handle, $csv_text );
		rewind( $handle );

		$all_rows = array();
		while ( ( $data = fgetcsv( $handle, 0, $delim, '"', '\\' ) ) !== false ) {
			// Lewati baris yang seluruh selnya kosong.
			if ( 1 === count( $data ) && ( null === $data[0] || '' === trim( (string) $data[0] ) ) ) {
				continue;
			}
			$all_rows[] = $data;
		}
		fclose( $handle );

		if ( empty( $all_rows ) ) {
			return new WP_Error( 'bcs_parse_failed', __( 'No rows could be read from the source.', 'bulk-catalog-studio' ) );
		}

		$headers = array();
		$data_rows = $all_rows;
		if ( $header_row ) {
			$headers   = array_map( array( __CLASS__, 'clean_header' ), (array) array_shift( $data_rows ) );
		}

		// Resolusi mapping header → field kanonik.
		$resolved = self::resolve_mapping( $headers, $column_mapping, $header_row );

		foreach ( $resolved['warnings'] as $w ) {
			$warnings[] = $w;
		}

		// Batas baris (spec §5.3 + AC-12).
		$data_rows = array_values( array_filter( $data_rows, array( __CLASS__, 'row_has_content' ) ) );
		$total     = count( $data_rows );
		$settings  = BCS_Settings::all();

		if ( $total > (int) $settings['max_rows_hard_limit'] ) {
			return new WP_Error(
				'bcs_too_many_rows',
				sprintf(
					/* translators: 1: jumlah baris, 2: batas keras */
					__( 'Source contains %1$d rows — exceeds the hard limit of %2$d rows per job. Split it into multiple jobs.', 'bulk-catalog-studio' ),
					$total,
					(int) $settings['max_rows_hard_limit']
				)
			);
		}

		if ( $total > (int) $settings['max_rows_per_job'] ) {
			return new WP_Error(
				'bcs_too_many_rows',
				sprintf(
					/* translators: 1: jumlah baris, 2: batas konfigurasi */
					__( 'Source contains %1$d rows — exceeds the limit of %2$d rows per job (max_rows_per_job setting). Split the source or raise the setting.', 'bulk-catalog-studio' ),
					$total,
					(int) $settings['max_rows_per_job']
				)
			);
		}

		// Normalisasi tiap baris.
		$rows = array();
		foreach ( array_values( $data_rows ) as $i => $cells ) {
			$row_index = $i + 1; // 1-based index data (tanpa header).
			$normalized = self::normalize_row( $cells, $headers, $resolved['map'], $row_index, $header_row, $source_hash, $warnings );

			if ( is_wp_error( $normalized ) ) {
				$errors[] = sprintf(
					/* translators: 1: nomor baris, 2: pesan error */
					__( 'Row %1$d: %2$s', 'bulk-catalog-studio' ),
					$row_index,
					$normalized->get_error_message()
				);
				continue;
			}

			if ( null !== $normalized ) {
				$rows[] = $normalized;
			}
		}

		return array(
			'rows'             => $rows,
			'detected_columns' => $resolved['detected'],
			'unmapped_columns' => $resolved['unmapped'],
			'warnings'         => $warnings,
			'errors'           => $errors,
		);
	}

	/**
	 * Bersihkan header: BOM, spasi, entitas.
	 *
	 * @param string $h Header mentah.
	 * @return string
	 */
	private static function clean_header( $h ) {
		return trim( (string) $h, " \t\n\r\0\x0B\xC2\xA0" );
	}

	/**
	 * Baris punya isi?
	 *
	 * @param array $cells Sel-sel.
	 * @return bool
	 */
	private static function row_has_content( $cells ) {
		foreach ( (array) $cells as $c ) {
			if ( '' !== trim( (string) $c ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Karakter delimiter dari enum.
	 *
	 * @param string $delimiter Enum delimiter.
	 * @return string
	 */
	private static function delimiter_char( $delimiter ) {
		if ( 'tab' === $delimiter ) {
			return "\t";
		}
		if ( ';' === $delimiter ) {
			return ';';
		}
		return ',';
	}

	/**
	 * Strip UTF-8 BOM.
	 *
	 * @param string $text Teks.
	 * @return string
	 */
	private static function strip_bom( $text ) {
		if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}
		return $text;
	}

	/**
	 * Konversi sumber non-UTF-8 ke UTF-8 bila perlu (MINOR-7 retur QA-1).
	 * Mengembalikan pesan warning bila konversi dilakukan, null bila tidak.
	 *
	 * @param string $csv_text Referensi isi CSV.
	 * @return string|null
	 */
	private static function maybe_convert_to_utf8( &$csv_text ) {
		if ( ! function_exists( 'mb_check_encoding' ) ) {
			return null; // mbstring tidak ada — biarkan (host standar WP menyediakan).
		}
		if ( mb_check_encoding( $csv_text, 'UTF-8' ) ) {
			return null;
		}
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			return __( 'Source is not valid UTF-8 and mb_convert_encoding is unavailable — non-ASCII characters may be corrupted.', 'bulk-catalog-studio' );
		}

		$converted = mb_convert_encoding( $csv_text, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8' );
		if ( is_string( $converted ) && '' !== $converted && mb_check_encoding( $converted, 'UTF-8' ) ) {
			$csv_text = $converted;

			return __( 'Source detected as non-UTF-8 (assumed Windows-1252/ISO-8859-1) — converted to UTF-8.', 'bulk-catalog-studio' );
		}

		return __( 'Source is not valid UTF-8 and encoding conversion failed — non-ASCII characters may be corrupted.', 'bulk-catalog-studio' );
	}

	/**
	 * Resolusi mapping header → field kanonik.
	 *
	 * Aturan (urutan prioritas):
	 * 1. column_mapping eksplisit dari pemanggil (header mentah → target).
	 * 2. Auto-detect: header sama dengan field kanonik (case-insensitive).
	 * 3. Auto-detect kolom atribut: header berawalan "attribute:" /
	 *    "variation_attribute:" dipakai langsung.
	 *
	 * @param array $headers        Header mentah.
	 * @param array $column_mapping Mapping eksplisit.
	 * @param bool  $header_row     Ada header?
	 * @return array { map, detected, unmapped, warnings }
	 */
	private static function resolve_mapping( array $headers, array $column_mapping, $header_row ) {
		$warnings = array();
		$map      = array();    // index kolom => field kanonik / attribute:<slug>.
		$detected = $headers;   // kolom mentah terdeteksi.
		$unmapped = array();

		if ( ! $header_row ) {
			// Tanpa header: mapping wajib berupa indeks numerik ("0" => "name").
			foreach ( $column_mapping as $key => $target ) {
				if ( ! preg_match( '/^\d+$/', (string) $key ) ) {
					$warnings[] = sprintf(
						/* translators: %s: kunci mapping */
						__( 'Mapping "%s" ignored: without header_row, mapping keys must be numeric column indexes.', 'bulk-catalog-studio' ),
						sanitize_text_field( (string) $key )
					);
					continue;
				}
				$idx = absint( $key );
				if ( self::valid_target( (string) $target ) ) {
					$map[ $idx ] = (string) $target;
				}
			}

			return array(
				'map'      => $map,
				'detected' => array(),
				'unmapped' => array(),
				'warnings' => $warnings,
			);
		}

		foreach ( array_values( $headers ) as $idx => $header ) {
			$target = null;

			// 1) Mapping eksplisit.
			foreach ( $column_mapping as $raw_key => $candidate ) {
				if ( self::header_equals( $header, (string) $raw_key ) ) {
					$target = (string) $candidate;
					break;
				}
			}

			// 2) Auto-detect field kanonik.
			if ( null === $target ) {
				foreach ( self::canonical_fields() as $field ) {
					if ( self::header_equals( $header, $field ) ) {
						$target = $field;
						break;
					}
				}
			}

			// 3) Auto-detect kolom atribut / variasi.
			if ( null === $target ) {
				$lower = strtolower( trim( $header ) );
				if ( 0 === strpos( $lower, 'attribute:' ) || 0 === strpos( $lower, 'variation_attribute:' ) ) {
					$target = $lower;
				}
			}

			if ( null !== $target && self::valid_target( $target ) ) {
				$map[ $idx ] = $target;
			} else {
				$unmapped[] = $header;
			}
		}

		if ( ! empty( $unmapped ) ) {
			$warnings[] = sprintf(
				/* translators: 1: daftar kolom */
				__( 'Unmapped columns (ignored): %s', 'bulk-catalog-studio' ),
				implode( ', ', array_map( 'sanitize_text_field', $unmapped ) )
			);
		}

		if ( empty( $map ) ) {
			$warnings[] = __( 'No columns were mapped — check column_mapping.', 'bulk-catalog-studio' );
		}

		return array(
			'map'      => $map,
			'detected' => $detected,
			'unmapped' => $unmapped,
			'warnings' => $warnings,
		);
	}

	/**
	 * Perbandingan header longgar: trim + lowercase + tanpa spasi berlebih.
	 *
	 * @param string $header Header.
	 * @param string $target Kunci mapping.
	 * @return bool
	 */
	private static function header_equals( $header, $target ) {
		$norm_header = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $header ) ) );
		$norm_target = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $target ) ) );

		return $norm_header === $norm_target;
	}

	/**
	 * Target mapping valid? (field kanonik, dimensions.*, atau kolom atribut)
	 *
	 * @param string $target Target.
	 * @return bool
	 */
	private static function valid_target( $target ) {
		$target = trim( strtolower( (string) $target ) );

		if ( 0 === strpos( $target, 'attribute:' ) && strlen( $target ) > 10 ) {
			return true;
		}
		if ( 0 === strpos( $target, 'variation_attribute:' ) && strlen( $target ) > 20 ) {
			return true;
		}

		foreach ( self::canonical_fields() as $field ) {
			if ( $target === $field ) {
				return true;
			}
		}

		// Bentuk dimensions.length / dimensions.width / dimensions.height.
		if ( in_array( $target, array( 'dimensions.length', 'dimensions.width', 'dimensions.height' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalisasi satu baris CSV menjadi struktur kanonik.
	 *
	 * @param array  $cells        Sel-sel baris.
	 * @param array  $headers      Header mentah.
	 * @param array  $map          Peta index kolom → target.
	 * @param int    $row_index    Nomor baris data (1-based).
	 * @param bool   $header_row   Ada header?
	 * @param string $source_hash  sha1 sumber.
	 * @param array  $warnings     Referensi array warning global.
	 * @return array|null Struktur baris kanonik; null bila baris kosong inti.
	 */
	private static function normalize_row( $cells, array $headers, array $map, $row_index, $header_row, $source_hash, array &$warnings ) {
		$values = array();

		foreach ( $map as $idx => $target ) {
			$raw = isset( $cells[ $idx ] ) ? trim( (string) $cells[ $idx ] ) : '';
			$values[ $target ][] = $raw;
		}

		// --- Field skalar ---
		$row = array(
			'row_index'         => $row_index,
			'row_key'           => '',
			'name'              => '',
			'description'       => '',
			'short_description' => '',
			'sku'               => '',
			'regular_price'     => null,
			'sale_price'        => null,
			'stock_quantity'    => 0,
			'weight'            => null,
			'dimensions'        => array(
				'length' => null,
				'width'  => null,
				'height' => null,
			),
			'categories'        => array(),
			'tags'              => array(),
			'attributes'        => array(),
			'variations'        => array(),
			'image_id'          => null,
			'image_url'         => null,
			'image_alt'         => '',
			'seo_title'         => '',
			'seo_description'   => '',
		);

		// Nama & SKU wajib.
		$row['name'] = self::first_value( $values, 'name' );
		$row['sku']  = strtoupper( trim( self::first_value( $values, 'sku' ) ) );

		if ( '' === $row['name'] && '' === $row['sku'] ) {
			return null; // Baris inti kosong — lewati tanpa error.
		}
		if ( '' === $row['name'] ) {
			return new WP_Error( 'bcs_row_invalid', __( 'name column is empty.', 'bulk-catalog-studio' ) );
		}
		if ( '' === $row['sku'] ) {
			return new WP_Error( 'bcs_row_invalid', __( 'SKU column is empty — V1 requires a unique SKU per row.', 'bulk-catalog-studio' ) );
		}

		$row['row_key'] = self::row_key( $source_hash, $row_index, $row['sku'] );

		$row['description']       = self::first_value( $values, 'description' );
		$row['short_description'] = self::first_value( $values, 'short_description' );

		// Harga.
		$row['regular_price'] = self::parse_price( self::first_value( $values, 'regular_price' ), $row_index, 'price', $warnings );
		$row['sale_price']    = self::parse_price( self::first_value( $values, 'sale_price' ), $row_index, 'sale price', $warnings );

		// Stok: kosong → default 0 + warning (contoh spec §4).
		// BLOCKER-1 (retur QA-1): simpan juga flag eksplisitas — operasi
		// update TIDAK boleh membawa default 0 ke produk existing.
		$stock_raw = self::first_value( $values, 'stock_quantity' );
		$row['stock_provided'] = false;
		if ( '' === $stock_raw ) {
			$warnings[] = sprintf(
				/* translators: 1: nomor baris */
				__( 'Row %1$d: empty stock → defaulting to 0.', 'bulk-catalog-studio' ),
				$row_index
			);
			$row['stock_quantity'] = 0;
		} else {
			$row['stock_provided'] = true;
			$stock = self::parse_number( $stock_raw );
			if ( null === $stock ) {
				$warnings[] = sprintf(
					/* translators: 1: nomor baris, 2: nilai mentah */
					__( 'Row %1$d: non-numeric stock "%2$s" → defaulting to 0.', 'bulk-catalog-studio' ),
					$row_index,
					$stock_raw
				);
				$row['stock_provided'] = false;
				$row['stock_quantity'] = 0;
			} else {
				$row['stock_quantity'] = (int) round( (float) $stock );
			}
		}

		// Berat + dimensi.
		$row['weight'] = self::parse_number( self::first_value( $values, 'weight' ) );
		$row['dimensions']['length'] = self::parse_number( self::first_value( $values, 'length' ) );
		if ( null === $row['dimensions']['length'] ) {
			$row['dimensions']['length'] = self::parse_number( self::first_value( $values, 'dimensions.length' ) );
		}
		$row['dimensions']['width'] = self::parse_number( self::first_value( $values, 'width' ) );
		if ( null === $row['dimensions']['width'] ) {
			$row['dimensions']['width'] = self::parse_number( self::first_value( $values, 'dimensions.width' ) );
		}
		$row['dimensions']['height'] = self::parse_number( self::first_value( $values, 'height' ) );
		if ( null === $row['dimensions']['height'] ) {
			$row['dimensions']['height'] = self::parse_number( self::first_value( $values, 'dimensions.height' ) );
		}

		// Kategori & tag.
		$row['categories'] = self::parse_list( self::first_value( $values, 'categories' ), true );
		$row['tags']       = self::parse_list( self::first_value( $values, 'tags' ), false );

		// Atribut level produk (pipe = multi opsi).
		foreach ( $values as $target => $unused ) {
			if ( 0 === strpos( $target, 'attribute:' ) ) {
				$slug = substr( $target, strlen( 'attribute:' ) );
				$raw  = self::first_value( $values, $target );
				if ( '' === $raw ) {
					continue;
				}
				$row['attributes'][] = array(
					'name'      => self::attribute_taxonomy( $slug ),
					'label'     => self::attribute_label( $slug ),
					'options'   => array_values( array_filter( array_map( 'trim', explode( '|', $raw ) ) ) ),
					'variation' => true,
					'visible'   => true,
				);
			}
		}

		// --- Variasi ---
		$var_skus    = self::pipe_split( self::first_value( $values, 'variation_sku' ) );
		$var_prices  = self::pipe_split( self::first_value( $values, 'variation_regular_price' ) );
		$var_sales   = self::pipe_split( self::first_value( $values, 'variation_sale_price' ) );
		$var_stocks  = self::pipe_split( self::first_value( $values, 'variation_stock_quantity' ) );
		$var_weights = self::pipe_split( self::first_value( $values, 'variation_weight' ) );

		// Kombinasi variasi eksplisit via kolom variation_attribute:<slug>.
		$combo_attr_names  = array();
		$combo_attr_values = array();
		foreach ( $values as $target => $unused ) {
			if ( 0 === strpos( $target, 'variation_attribute:' ) ) {
				$slug                 = substr( $target, strlen( 'variation_attribute:' ) );
				$combo_attr_names[]   = self::attribute_taxonomy( $slug );
				$combo_attr_values[]  = self::pipe_split( self::first_value( $values, $target ) );
			}
		}

		$combo_count = 0;
		foreach ( $combo_attr_values as $vals ) {
			$combo_count = max( $combo_count, count( $vals ) );
		}

		// Jumlah kombinasi:
		// 1) Kolom variation_attribute:* eksplisit → max panjang list-nya.
		// 2) Kolom variasi lain (SKU/harga/stok) eksplisit → max panjang list-nya,
		//    atribut tiap kombinasi diambil dari index cartesian opsi.
		// 3) Tanpa kolom variasi sama sekali → cartesian product penuh opsi atribut.
		if ( 0 === $combo_count && ! empty( $row['attributes'] ) && ( ! empty( $var_skus ) || ! empty( $var_prices ) || ! empty( $var_stocks ) ) ) {
			$explicit_counts = array_filter(
				array( count( $var_skus ), count( $var_prices ), count( $var_stocks ) ),
				array( __CLASS__, 'greater_than_zero' )
			);

			$cartesian_total = 1;
			foreach ( $row['attributes'] as $attr ) {
				$cartesian_total *= max( 1, count( $attr['options'] ) );
			}

			$combo_count = ! empty( $explicit_counts ) ? max( $explicit_counts ) : $cartesian_total;

			if ( $combo_count !== $cartesian_total && ! empty( $var_skus ) ) {
				$warnings[] = sprintf(
					/* translators: 1: nomor baris, 2: jumlah SKU variasi, 3: jumlah kombinasi atribut */
					__( 'Row %1$d: variation SKU count (%2$d) does not match the attribute combination count (%3$d) — attributes are mapped in order starting from the first combination.', 'bulk-catalog-studio' ),
					$row_index,
					count( $var_skus ),
					$cartesian_total
				);
			}
		}

		for ( $c = 0; $c < $combo_count; $c++ ) {
			$variation = array(
				'sku'            => isset( $var_skus[ $c ] ) ? strtoupper( trim( $var_skus[ $c ] ) ) : '',
				'attributes'     => array(),
				'regular_price'  => null,
				'sale_price'     => null,
				'stock_quantity' => 0,
				'weight'         => null,
				'dimensions'     => array(
					'length' => null,
					'width'  => null,
					'height' => null,
				),
			);

			if ( ! empty( $combo_attr_names ) ) {
				foreach ( $combo_attr_names as $k => $tax ) {
					$value = isset( $combo_attr_values[ $k ][ $c ] ) ? trim( $combo_attr_values[ $k ][ $c ] ) : '';
					if ( '' !== $value ) {
						$variation['attributes'][ $tax ] = $value;
					}
				}
			} elseif ( ! empty( $row['attributes'] ) ) {
				// Cartesian product dari opsi atribut.
				$variation['attributes'] = self::cartesian_combo( $row['attributes'], $c );
			}

			if ( empty( $variation['attributes'] ) ) {
				continue; // Kombinasi tanpa atribut tidak valid untuk variabel.
			}

			$variation['regular_price'] = isset( $var_prices[ $c ] ) ? self::parse_number( $var_prices[ $c ] ) : null;
			$variation['sale_price']    = isset( $var_sales[ $c ] ) ? self::parse_number( $var_sales[ $c ] ) : null;

			$stock_v = isset( $var_stocks[ $c ] ) ? trim( (string) $var_stocks[ $c ] ) : '';
			if ( '' === $stock_v ) {
				$variation['stock_quantity'] = 0;
			} else {
				$parsed_stock = self::parse_number( $stock_v );
				$variation['stock_quantity'] = null === $parsed_stock ? 0 : (int) round( (float) $parsed_stock );
			}

			$variation['weight'] = isset( $var_weights[ $c ] ) ? self::parse_number( $var_weights[ $c ] ) : null;

			$row['variations'][] = $variation;
		}

		// Produk tanpa variasi tapi kolom variasi terisi → warning.
		if ( ! empty( $row['variations'] ) && empty( $row['attributes'] ) ) {
			$warnings[] = sprintf(
				/* translators: 1: nomor baris */
				__( 'Row %1$d: variation columns present but no attribute:* columns — variations ignored, product created as simple.', 'bulk-catalog-studio' ),
				$row_index
			);
			$row['variations'] = array();
		}

		// --- Gambar ---
		$image_url_raw = self::first_value( $values, 'image_url' );
		$image_id_raw  = self::first_value( $values, 'image_id' );

		if ( '' !== $image_url_raw ) {
			if ( preg_match( '/^\d+$/', $image_url_raw ) ) {
				$row['image_id'] = absint( $image_url_raw );
			} else {
				$row['image_url'] = esc_url_raw( $image_url_raw );
				$warnings[]       = sprintf(
					/* translators: 1: nomor baris */
					__( 'Row %1$d: image_url ignored — V1 only supports attachment_id (gate1 decision #4).', 'bulk-catalog-studio' ),
					$row_index
				);
			}
		}
		if ( '' !== $image_id_raw && preg_match( '/^\d+$/', $image_id_raw ) ) {
			$row['image_id'] = absint( $image_id_raw );
		}

		$row['image_alt']       = self::first_value( $values, 'image_alt' );
		$row['seo_title']       = self::first_value( $values, 'seo_title' );
		$row['seo_description'] = self::first_value( $values, 'seo_description' );

		return $row;
	}

	/**
	 * Ambil nilai pertama non-kosong untuk satu target.
	 *
	 * @param array  $values Peta target → array nilai.
	 * @param string $target Target.
	 * @return string
	 */
	private static function first_value( array $values, $target ) {
		if ( isset( $values[ $target ] ) && is_array( $values[ $target ] ) ) {
			foreach ( $values[ $target ] as $v ) {
				if ( '' !== trim( (string) $v ) ) {
					return trim( (string) $v );
				}
			}
		}
		return '';
	}

	/**
	 * Split pipe dengan trim + buang kosong.
	 *
	 * @param string $raw Nilai mentah.
	 * @return array
	 */
	private static function pipe_split( $raw ) {
		if ( '' === trim( (string) $raw ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( '|', (string) $raw ) ), array( __CLASS__, 'not_empty' ) ) );
	}

	/**
	 * Callback buang kosong.
	 *
	 * @param string $v Nilai.
	 * @return bool
	 */
	private static function not_empty( $v ) {
		return '' !== trim( (string) $v );
	}

	/**
	 * Callback angka > 0.
	 *
	 * @param int $v Nilai.
	 * @return bool
	 */
	private static function greater_than_zero( $v ) {
		return (int) $v > 0;
	}

	/**
	 * Kombinasi cartesian ke-n dari daftar atribut.
	 *
	 * Urutan: atribut TERAKHIR bervariasi paling cepat (rightmost-fastest),
	 * sehingga dengan opsi ukuran S|M|L dan warna Merah|Biru urutannya:
	 * (S,Merah) (S,Biru) (M,Merah) (M,Biru) (L,Merah) (L,Biru) — selaras
	 * dengan konvensi SKU variasi berurut {SKU}-{UKURAN}-{WARNA}.
	 *
	 * @param array $attributes Atribut dengan opsi.
	 * @param int   $n          Index kombinasi.
	 * @return array
	 */
	private static function cartesian_combo( array $attributes, $n ) {
		$combo   = array();
		$divisor = 1;

		foreach ( array_reverse( $attributes, true ) as $attr ) {
			$options = ! empty( $attr['options'] ) ? array_values( $attr['options'] ) : array( '' );
			$count   = count( $options );

			$combo[ $attr['name'] ] = $options[ intdiv( $n, $divisor ) % $count ];
			$divisor *= $count;
		}

		return array_filter( $combo, array( __CLASS__, 'not_empty' ) );
	}

	/**
	 * Parse harga: terima "189000", "189.000,50", "1,899.99".
	 *
	 * @param string $raw        Nilai mentah.
	 * @param int    $row_index  Nomor baris.
	 * @param string $label      Label kolom untuk warning.
	 * @param array  $warnings   Referensi warning global.
	 * @return string|null Harga terformat (wc_format_decimal style) atau null.
	 */
	private static function parse_price( $raw, $row_index, $label, array &$warnings ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}

		$parsed = self::parse_number( $raw );
		if ( null === $parsed ) {
			$warnings[] = sprintf(
				/* translators: 1: nomor baris, 2: label kolom, 3: nilai mentah */
				__( 'Row %1$d: non-numeric %2$s "%3$s" — column ignored.', 'bulk-catalog-studio' ),
				$row_index,
				$label,
				$raw
			);
			return null;
		}

		return (string) round( (float) $parsed, 4 );
	}

	/**
	 * Parse angka longgar: strip spasi + karakter non-numerik umum,
	 * tangani desimal koma/titik dan pemisah ribuan.
	 *
	 * @param string $raw Nilai mentah.
	 * @return string|null
	 */
	private static function parse_number( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}

		// Buang semua kecuali digit, titik, koma, minus.
		$clean = preg_replace( '/[^0-9.,\-]/', '', $raw );
		if ( '' === $clean ) {
			return null;
		}

		$has_comma = false !== strpos( $clean, ',' );
		$has_dot   = false !== strpos( $clean, '.' );

		if ( $has_comma && $has_dot ) {
			// Pemisah terakhir dianggap desimal.
			if ( strrpos( $clean, ',' ) > strrpos( $clean, '.' ) ) {
				$clean = str_replace( '.', '', $clean );
				$clean = str_replace( ',', '.', $clean );
			} else {
				$clean = str_replace( ',', '', $clean );
			}
		} elseif ( $has_comma ) {
			// Koma tunggal: desimal bila mengikuti maksimal 2 digit di akhir.
			if ( preg_match( '/,\d{1,2}$/', $clean ) && substr_count( $clean, ',' ) < 2 ) {
				$clean = str_replace( ',', '.', $clean );
			} else {
				$clean = str_replace( ',', '', $clean );
			}
		} elseif ( $has_dot && preg_match( '/^\d{1,3}(\.\d{3})+$/', $clean ) ) {
			// Titik saja dengan kelompok tepat 3 digit (189.000 / 1.899.000)
			// → pemisah ribuan gaya Indonesia; buang titik.
			$clean = str_replace( '.', '', $clean );
		}

		if ( ! is_numeric( $clean ) ) {
			return null;
		}

		return (string) ( 0 + $clean );
	}

	/**
	 * Parse daftar terpisah koma. Kategori mendukung hierarki "A > B > C".
	 *
	 * @param string $raw          Nilai mentah.
	 * @param bool   $hierarchical Kategori hierarkis?
	 * @return array
	 */
	private static function parse_list( $raw, $hierarchical ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}

		$items = array();
		foreach ( explode( ',', $raw ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}

			if ( $hierarchical && false !== strpos( $part, '>' ) ) {
				$chain = array_map( 'trim', explode( '>', $part ) );
				$items[] = implode( ' > ', array_filter( $chain, array( __CLASS__, 'not_empty' ) ) );
			} else {
				$items[] = $part;
			}
		}

		return $items;
	}

	/**
	 * Normalisasi slug atribut menjadi taxonomy: "ukuran" → "pa_ukuran",
	 * "pa_ukuran" → "pa_ukuran". Scope non-global hanya pada custom scope.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function attribute_taxonomy( $slug ) {
		$slug = trim( (string) $slug );

		// Strip prefix 'pa_' SEBELUM sanitasi — sanitize_title mengubah
		// underscore menjadi dash sehingga 'pa_ukuran' bisa menjadi
		// 'pa-pa-ukuran' bila prefix ikut disanitasi lebih dulu.
		if ( 0 === strpos( $slug, 'pa_' ) ) {
			$slug = substr( $slug, 3 );
		}

		// Konvensi WooCommerce: taxonomy = 'pa_' + wc_sanitize_taxonomy_name
		// (lowercase, dash untuk multi-kata, tanpa underscore).
		return 'pa_' . sanitize_title( $slug );
	}

	/**
	 * Label manusia dari slug: "pa_ukuran" → "ukuran".
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function attribute_label( $slug ) {
		$slug = trim( (string) $slug );
		if ( 0 === strpos( $slug, 'pa_' ) ) {
			$slug = substr( $slug, 3 );
		}

		return sanitize_title( $slug );
	}

	/**
	 * row_key idempotency persis spec §5.4:
	 * sha1( source_hash + '|' + row_index + '|' + lower(trim(sku)) )
	 *
	 * @param string $source_hash sha1 sumber.
	 * @param int    $row_index   Nomor baris (1-based).
	 * @param string $sku         SKU.
	 * @return string
	 */
	public static function row_key( $source_hash, $row_index, $sku ) {
		return sha1( (string) $source_hash . '|' . absint( $row_index ) . '|' . strtolower( trim( (string) $sku ) ) );
	}
}
