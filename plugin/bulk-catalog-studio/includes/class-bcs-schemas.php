<?php
/**
 * JSON Schema input/output untuk 8 ability BCS (spec §1).
 *
 * Semua input schema strict: additionalProperties: false (spec §5.5.1).
 * Schema disalin persis dari docs/spec/bulk-catalog-studio.md §1, dengan satu
 * tambahan yang disuruh spec §4 (catatan implementasi): property
 * `row_overrides` opsional pada build-plan.
 *
 * @package BulkCatalogStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BCS_Schemas
 */
class BCS_Schemas {

	/**
	 * Daftar nama ability (namespace/ability).
	 *
	 * @return array
	 */
	public static function ability_names() {
		return array(
			'bcs/parse-source',
			'bcs/check-duplicates',
			'bcs/build-plan',
			'bcs/approve-plan',
			'bcs/execute-batch',
			'bcs/job-status',
			'bcs/rollback-batch',
			'bcs/audit-log',
		);
	}

	/**
	 * Ambil input schema.
	 *
	 * @param string $ability Nama ability.
	 * @return array
	 */
	public static function input( $ability ) {
		switch ( $ability ) {
			case 'bcs/parse-source':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'source_type'   => array(
							'type' => 'string',
							'enum' => array( 'inline_csv', 'attachment', 'url' ),
						),
						'csv_text'      => array(
							'type'        => 'string',
							'maxLength'   => 2000000,
							'description' => __( 'Wajib jika source_type=inline_csv.', 'bulk-catalog-studio' ),
						),
						'attachment_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Wajib jika source_type=attachment; harus post_type=attachment dan mime text/csv.', 'bulk-catalog-studio' ),
						),
						'url'           => array(
							'type'        => 'string',
							'format'      => 'uri',
							'maxLength'   => 2048,
							'description' => __( 'Wajib jika source_type=url; hanya https, khusus Google Sheets wajib endpoint export?format=csv publik.', 'bulk-catalog-studio' ),
						),
						'source_name'   => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'delimiter'     => array(
							'type'    => 'string',
							'enum'    => array( ',', ';', 'tab' ),
							'default' => ',',
						),
						'header_row'    => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'column_mapping' => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'string' ),
							'description'          => __( 'Peta header CSV -> field kanonik: name, description, short_description, sku, regular_price, sale_price, stock_quantity, weight, length, width, height, categories, tags, attribute:<slug>, variation_sku, variation_attribute:<slug>, variation_regular_price, variation_stock_quantity, image_url, image_alt, seo_title, seo_description', 'bulk-catalog-studio' ),
						),
						'notes'         => array(
							'type'        => 'string',
							'maxLength'   => 2000,
							'description' => __( 'Catatan Claude tentang asumsi mapping, disimpan di job untuk audit.', 'bulk-catalog-studio' ),
						),
					),
					'required'             => array( 'source_type' ),
				);

			case 'bcs/check-duplicates':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'strategy'     => array(
							'type'        => 'string',
							'enum'        => array( 'sku', 'sku+title' ),
							'default'     => 'sku+title',
							'description' => __( 'sku: hanya cocokkan SKU exact (case-insensitive, trim). sku+title: tambah cocokkan nama normalized (lowercase, tanpa tanda baca).', 'bulk-catalog-studio' ),
						),
						'on_duplicate' => array(
							'type'        => 'string',
							'enum'        => array( 'flag_only', 'skip', 'update' ),
							'default'     => 'flag_only',
							'description' => __( 'Kebijakan default yang akan dibawa ke build-plan; manusia tetap melihat hasilnya di preview.', 'bulk-catalog-studio' ),
						),
					),
					'required'             => array( 'job_id' ),
				);

			case 'bcs/build-plan':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'on_duplicate'  => array(
							'type'        => 'string',
							'enum'        => array( 'skip', 'update' ),
							'description' => __( "Wajib keputusan final bila ada duplikat; 'flag_only' ditolak di tahap ini.", 'bulk-catalog-studio' ),
						),
						'defaults'      => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'status'                       => array(
									'type'        => 'string',
									'enum'        => array( 'publish', 'draft' ),
									'default'     => 'draft',
									'description' => __( 'Status produk baru; default draft agar aman.', 'bulk-catalog-studio' ),
								),
								'catalog_visibility'           => array(
									'type'    => 'string',
									'enum'    => array( 'visible', 'hidden' ),
									'default' => 'visible',
								),
								'manage_stock'                 => array(
									'type'    => 'boolean',
									'default' => true,
								),
								'tax_class'                    => array(
									'type'    => array( 'string', 'null' ),
									'default' => null,
								),
								'attribute_scope'              => array(
									'type'        => 'string',
									'enum'        => array( 'global_pa', 'custom' ),
									'default'     => 'global_pa',
									'description' => __( 'global_pa: variasi memakai taxonomy pa_* global agar reusable.', 'bulk-catalog-studio' ),
								),
								'seo_provider'                 => array(
									'type'    => 'string',
									'enum'    => array( 'auto', 'yoast', 'rank_math', 'custom_fields' ),
									'default' => 'auto',
								),
								'max_variations_per_product'   => array(
									'type'    => 'integer',
									'minimum' => 1,
									'maximum' => 100,
									'default' => 100,
								),
							),
						),
						'image_policy'  => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'mode'         => array(
									'type'        => 'string',
									'enum'        => array( 'attachment_id_only', 'sideload_url' ),
									'default'     => 'attachment_id_only',
									'description' => __( 'V1 hanya attachment_id_only (keputusan gate1 #4).', 'bulk-catalog-studio' ),
								),
								'set_alt_text' => array(
									'type'    => 'boolean',
									'default' => true,
								),
							),
						),
						'row_overrides' => array(
							'type'        => 'array',
							'items'       => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'row_index' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'op'        => array(
										'type' => 'string',
										'enum' => array( 'skip', 'update', 'create' ),
									),
								),
								'required'             => array( 'row_index', 'op' ),
							),
							'description' => __( 'Keputusan per baris (spec §4 catatan implementasi): override kebijakan duplikat untuk baris tertentu.', 'bulk-catalog-studio' ),
						),
					),
					'required'             => array( 'job_id', 'on_duplicate' ),
				);

			case 'bcs/approve-plan':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'plan_id'            => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'plan_hash'          => array(
							'type'        => 'string',
							'minLength'   => 40,
							'maxLength'   => 40,
							'description' => __( 'Harus identik dengan plan_hash dari build-plan; mencegah approve plan lama yang sudah berubah.', 'bulk-catalog-studio' ),
						),
						'confirmation_token' => array(
							'type'        => 'string',
							'minLength'   => 6,
							'maxLength'   => 12,
							'pattern'     => '^[A-Z0-9-]+$',
							'description' => __( 'Token one-time yang ditampilkan HANYA di layar admin diff. Diberikan manusia ke Claude atau dipakai manusia langsung via tombol Approve di dashboard.', 'bulk-catalog-studio' ),
						),
					),
					'required'             => array( 'plan_id', 'plan_hash', 'confirmation_token' ),
				);

			case 'bcs/execute-batch':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'plan_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'true: jalankan validasi penuh tanpa menulis produk; hasil = simulasi.', 'bulk-catalog-studio' ),
						),
						'resume'  => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'true: lanjutkan job berstatus executing yang heartbeat-nya stale (>5 menit) karena request mati.', 'bulk-catalog-studio' ),
						),
					),
					'required'             => array( 'plan_id' ),
				);

			case 'bcs/job-status':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Opsional; tanpa ini = list job terbaru.', 'bulk-catalog-studio' ),
						),
					),
					'required'             => array(),
				);

			case 'bcs/rollback-batch':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'confirm' => array(
							'type'        => 'boolean',
							'const'       => true,
							'description' => __( 'Harus true literal; mencegah rollback tak sengaja.', 'bulk-catalog-studio' ),
						),
						'scope'  => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'created_only', 'updated_only' ),
							'default' => 'all',
						),
					),
					'required'             => array( 'job_id', 'confirm' ),
				);

			case 'bcs/audit-log':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'level'  => array(
							'type'    => 'string',
							'enum'    => array( 'call', 'row', 'all' ),
							'default' => 'all',
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 50,
						),
						'offset' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
					'required'             => array( 'job_id' ),
				);
		}

		return array( 'type' => 'object', 'properties' => array() );
	}

	/**
	 * Ambil output schema.
	 *
	 * @param string $ability Nama ability.
	 * @return array
	 */
	public static function output( $ability ) {
		switch ( $ability ) {
			case 'bcs/parse-source':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id'           => array( 'type' => 'integer' ),
						'status'           => array( 'type' => 'string', 'const' => 'parsed' ),
						'source_hash'      => array( 'type' => 'string', 'description' => __( 'sha1 isi sumber; deteksi upload ganda.', 'bulk-catalog-studio' ) ),
						'total_rows'       => array( 'type' => 'integer' ),
						'detected_columns' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'unmapped_columns' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'warnings'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Baris dilewati, kolom kosong wajib, harga non-numerik, dsb.', 'bulk-catalog-studio' ) ),
						'errors'           => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
					'required'             => array( 'job_id', 'status', 'source_hash', 'total_rows', 'warnings' ),
				);

			case 'bcs/check-duplicates':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id'                   => array( 'type' => 'integer' ),
						'intra_batch_duplicates'   => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'row_indices' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
									'sku'         => array( 'type' => array( 'string', 'null' ) ),
								),
								'required'             => array( 'row_indices' ),
							),
						),
						'existing_matches'         => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'row_index'           => array( 'type' => 'integer' ),
									'sku'                 => array( 'type' => array( 'string', 'null' ) ),
									'existing_product_id' => array( 'type' => 'integer' ),
									'match_on'            => array( 'type' => 'string', 'enum' => array( 'sku', 'title' ) ),
								),
								'required'             => array( 'row_index', 'existing_product_id', 'match_on' ),
							),
						),
						'duplicate_count'          => array( 'type' => 'integer' ),
						'on_duplicate_effective'   => array( 'type' => 'string', 'enum' => array( 'flag_only', 'skip', 'update' ) ),
					),
					'required'             => array( 'job_id', 'intra_batch_duplicates', 'existing_matches', 'duplicate_count', 'on_duplicate_effective' ),
				);

			case 'bcs/build-plan':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id'               => array( 'type' => 'integer' ),
						'plan_id'              => array( 'type' => 'integer' ),
						'plan_hash'            => array( 'type' => 'string', 'description' => __( 'sha1 dari plan JSON; approval terikat pada hash ini.', 'bulk-catalog-studio' ) ),
						'summary'              => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'create_simple'    => array( 'type' => 'integer' ),
								'create_variable'  => array( 'type' => 'integer' ),
								'create_variations' => array( 'type' => 'integer' ),
								'update_products'  => array( 'type' => 'integer' ),
								'skip_duplicates'  => array( 'type' => 'integer' ),
								'total_operations' => array( 'type' => 'integer' ),
							),
							'required'             => array( 'create_simple', 'create_variable', 'create_variations', 'update_products', 'skip_duplicates', 'total_operations' ),
						),
						'diff_preview_url'     => array( 'type' => 'string', 'description' => __( "URL admin preview diff: admin_url('admin.php?page=bcs-jobs&view=plan&plan=<id>').", 'bulk-catalog-studio' ) ),
						'approval_token_hint'  => array( 'type' => 'string', 'const' => 'Token approval hanya ditampilkan di layar admin WP (halaman diff). Minta ke manusia.', 'description' => __( 'Token TIDAK PERNAH dikembalikan via MCP.', 'bulk-catalog-studio' ) ),
					),
					'required'             => array( 'job_id', 'plan_id', 'plan_hash', 'summary', 'diff_preview_url' ),
				);

			case 'bcs/approve-plan':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'plan_id'     => array( 'type' => 'integer' ),
						'job_id'      => array( 'type' => 'integer' ),
						'status'      => array( 'type' => 'string', 'const' => 'approved' ),
						'approved_by' => array( 'type' => 'integer', 'description' => __( 'WP user ID pemilik session MCP yang memvalidasi token.', 'bulk-catalog-studio' ) ),
						'approved_at' => array( 'type' => 'string', 'format' => 'date-time' ),
						'note'        => array( 'type' => 'string', 'description' => __( 'Kosong bila sukses; berisi alasan penolakan token bila gagal.', 'bulk-catalog-studio' ) ),
					),
					'required'             => array( 'plan_id', 'job_id', 'status', 'approved_at' ),
				);

			case 'bcs/execute-batch':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id'      => array( 'type' => 'integer' ),
						'plan_id'     => array( 'type' => 'integer' ),
						'status'      => array( 'type' => 'string', 'enum' => array( 'done', 'partially_failed', 'failed' ) ),
						'counts'      => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'products_created'   => array( 'type' => 'integer' ),
								'products_updated'   => array( 'type' => 'integer' ),
								'variations_created' => array( 'type' => 'integer' ),
								'skipped'            => array( 'type' => 'integer' ),
								'failed'             => array( 'type' => 'integer' ),
								'total_rows'         => array( 'type' => 'integer' ),
							),
							'required'             => array( 'products_created', 'products_updated', 'variations_created', 'skipped', 'failed', 'total_rows' ),
						),
						'failures'    => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'row_index' => array( 'type' => 'integer' ),
									'sku'       => array( 'type' => array( 'string', 'null' ) ),
									'reason'    => array( 'type' => 'string' ),
								),
								'required'             => array( 'row_index', 'reason' ),
							),
						),
						'duration_ms' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'job_id', 'plan_id', 'status', 'counts' ),
				);

			case 'bcs/job-status':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'jobs' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'job_id'       => array( 'type' => 'integer' ),
									'status'       => array(
										'type' => 'string',
										'enum' => array( 'draft', 'parsed', 'planned', 'approved', 'executing', 'done', 'failed', 'partially_failed', 'rolled_back' ),
									),
									'source_name'  => array( 'type' => 'string' ),
									'total_rows'   => array( 'type' => 'integer' ),
									'counts'       => array(
										'type'                 => 'object',
										'additionalProperties' => array( 'type' => 'integer' ),
										'description'          => __( 'created / updated / variations / skipped / failed', 'bulk-catalog-studio' ),
									),
									'created_at'   => array( 'type' => 'string', 'format' => 'date-time' ),
									'updated_at'   => array( 'type' => 'string', 'format' => 'date-time' ),
									'heartbeat_at' => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
									'stale'        => array( 'type' => 'boolean', 'description' => __( 'true bila status=executing dan heartbeat > 5 menit lalu; boleh di-resume.', 'bulk-catalog-studio' ) ),
								),
								'required'             => array( 'job_id', 'status', 'total_rows', 'created_at', 'updated_at' ),
							),
						),
					),
					'required'             => array( 'jobs' ),
				);

			case 'bcs/rollback-batch':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id'  => array( 'type' => 'integer' ),
						'status'  => array( 'type' => 'string', 'const' => 'rolled_back' ),
						'counts'  => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'products_trashed'   => array( 'type' => 'integer' ),
								'products_restored'  => array( 'type' => 'integer', 'description' => __( 'produk update yang dikembalikan ke nilai before.', 'bulk-catalog-studio' ) ),
								'variations_trashed' => array( 'type' => 'integer' ),
								'failed'             => array( 'type' => 'integer' ),
							),
							'required'             => array( 'products_trashed', 'products_restored', 'variations_trashed', 'failed' ),
						),
						'note'    => array( 'type' => 'string', 'description' => __( 'Bila failed > 0, jelaskan baris mana dan mengapa.', 'bulk-catalog-studio' ) ),
					),
					'required'             => array( 'job_id', 'status', 'counts' ),
				);

			case 'bcs/audit-log':
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'job_id'  => array( 'type' => 'integer' ),
						'total'   => array( 'type' => 'integer' ),
						'entries' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'id'            => array( 'type' => 'integer' ),
									'ts'            => array( 'type' => 'string', 'format' => 'date-time' ),
									'level'         => array( 'type' => 'string', 'enum' => array( 'call', 'row' ) ),
									'ability'       => array( 'type' => 'string' ),
									'actor_user_id' => array( 'type' => 'integer' ),
									'actor_via'     => array( 'type' => 'string', 'enum' => array( 'mcp', 'wp_admin', 'wp_cli', 'system' ) ),
									'action'        => array( 'type' => 'string', 'description' => __( 'contoh: create_product, update_variation, trash_product, approve_plan, rollback_row.', 'bulk-catalog-studio' ) ),
									'row_index'     => array( 'type' => array( 'integer', 'null' ) ),
									'sku'           => array( 'type' => array( 'string', 'null' ) ),
									'product_id'    => array( 'type' => array( 'integer', 'null' ) ),
									'status'        => array( 'type' => 'string', 'enum' => array( 'ok', 'failed', 'rolled_back', 'skipped' ) ),
									'message'       => array( 'type' => 'string' ),
								),
								'required'             => array( 'id', 'ts', 'level', 'ability', 'actor_user_id', 'action', 'status' ),
							),
						),
					),
					'required'             => array( 'job_id', 'total', 'entries' ),
				);
		}

		return array( 'type' => 'object', 'properties' => array() );
	}
}
