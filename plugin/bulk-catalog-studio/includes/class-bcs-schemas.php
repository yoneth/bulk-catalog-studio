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
							'description' => __( 'Required when source_type=inline_csv.', 'bulk-catalog-studio' ),
						),
						'attachment_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Required when source_type=attachment; must be post_type=attachment with mime text/csv.', 'bulk-catalog-studio' ),
						),
						'url'           => array(
							'type'        => 'string',
							'format'      => 'uri',
							'maxLength'   => 2048,
							'description' => __( 'Required when source_type=url; https only, Google Sheets must use the public export?format=csv endpoint.', 'bulk-catalog-studio' ),
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
							'description'          => __( 'Map of CSV header -> canonical field: name, description, short_description, sku, regular_price, sale_price, stock_quantity, weight, length, width, height, categories, tags, attribute:<slug>, variation_sku, variation_attribute:<slug>, variation_regular_price, variation_stock_quantity, image_url, image_alt, seo_title, seo_description', 'bulk-catalog-studio' ),
						),
						'notes'         => array(
							'type'        => 'string',
							'maxLength'   => 2000,
							'description' => __( 'Claude notes about mapping assumptions, stored on the job for audit.', 'bulk-catalog-studio' ),
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
							'description' => __( 'sku: match exact SKU only (case-insensitive, trimmed). sku+title: also match the normalized name (lowercase, no punctuation).', 'bulk-catalog-studio' ),
						),
						'on_duplicate' => array(
							'type'        => 'string',
							'enum'        => array( 'flag_only', 'skip', 'update' ),
							'default'     => 'flag_only',
							'description' => __( 'Default policy carried into build-plan; a human still reviews the result in the preview.', 'bulk-catalog-studio' ),
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
							'description' => __( "A final decision is required when duplicates exist; 'flag_only' is rejected at this stage.", 'bulk-catalog-studio' ),
						),
						'defaults'      => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'status'                       => array(
									'type'        => 'string',
									'enum'        => array( 'publish', 'draft' ),
									'default'     => 'draft',
									'description' => __( 'Status for new products; defaults to draft for safety.', 'bulk-catalog-studio' ),
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
									'description' => __( 'global_pa: variations use the global pa_* taxonomy so terms are reusable.', 'bulk-catalog-studio' ),
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
									'description' => __( 'V1 supports attachment_id_only only (gate1 decision #4).', 'bulk-catalog-studio' ),
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
							'description' => __( 'Per-row decisions (spec §4 implementation note): override the duplicate policy for specific rows.', 'bulk-catalog-studio' ),
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
							'description' => __( 'Must be identical to the plan_hash from build-plan; prevents approving an older plan that has changed.', 'bulk-catalog-studio' ),
						),
						'confirmation_token' => array(
							'type'        => 'string',
							'minLength'   => 6,
							'maxLength'   => 12,
							'pattern'     => '^[A-Z0-9-]+$',
							'description' => __( 'One-time token shown ONLY on the admin diff screen. Handed from the human to Claude, or used by the human directly via the Approve button in the dashboard.', 'bulk-catalog-studio' ),
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
							'description' => __( 'true: run full validation without writing products; the result is a simulation.', 'bulk-catalog-studio' ),
						),
						'resume'  => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'true: resume an executing job whose heartbeat is stale (>5 minutes) because the request died.', 'bulk-catalog-studio' ),
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
							'description' => __( 'Optional; without it = list the latest jobs.', 'bulk-catalog-studio' ),
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
							'description' => __( 'Must be literal true; prevents accidental rollback.', 'bulk-catalog-studio' ),
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
						'source_hash'      => array( 'type' => 'string', 'description' => __( 'sha1 of the source content; detects duplicate uploads.', 'bulk-catalog-studio' ) ),
						'total_rows'       => array( 'type' => 'integer' ),
						'detected_columns' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'unmapped_columns' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'warnings'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Skipped rows, empty required columns, non-numeric prices, etc.', 'bulk-catalog-studio' ) ),
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
						'plan_hash'            => array( 'type' => 'string', 'description' => __( 'sha1 of the plan JSON; approval is bound to this hash.', 'bulk-catalog-studio' ) ),
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
						'diff_preview_url'     => array( 'type' => 'string', 'description' => __( "Admin diff preview URL: admin_url('admin.php?page=bcs-jobs&view=plan&plan=<id>').", 'bulk-catalog-studio' ) ),
						'approval_token_hint'  => array( 'type' => 'string', 'const' => 'The approval token is only displayed on the WP admin screen (diff page). Ask the human.', 'description' => __( 'The token is NEVER returned via MCP.', 'bulk-catalog-studio' ) ),
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
						'approved_by' => array( 'type' => 'integer', 'description' => __( 'WP user ID of the MCP session owner who validated the token.', 'bulk-catalog-studio' ) ),
						'approved_at' => array( 'type' => 'string', 'format' => 'date-time' ),
						'note'        => array( 'type' => 'string', 'description' => __( 'Empty on success; contains the token rejection reason on failure.', 'bulk-catalog-studio' ) ),
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
									'stale'        => array( 'type' => 'boolean', 'description' => __( 'true when status=executing and the heartbeat is older than 5 minutes; can be resumed.', 'bulk-catalog-studio' ) ),
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
								'products_restored'  => array( 'type' => 'integer', 'description' => __( 'updated products restored to their before values.', 'bulk-catalog-studio' ) ),
								'variations_trashed' => array( 'type' => 'integer' ),
								'failed'             => array( 'type' => 'integer' ),
							),
							'required'             => array( 'products_trashed', 'products_restored', 'variations_trashed', 'failed' ),
						),
						'note'    => array( 'type' => 'string', 'description' => __( 'When failed > 0, explain which rows and why.', 'bulk-catalog-studio' ) ),
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
									'action'        => array( 'type' => 'string', 'description' => __( 'example: create_product, update_variation, trash_product, approve_plan, rollback_row.', 'bulk-catalog-studio' ) ),
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
