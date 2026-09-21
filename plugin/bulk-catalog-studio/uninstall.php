<?php
/**
 * Uninstall handler untuk Bulk Catalog Studio (spec §3.6, AC-8).
 *
 * HANYA berjalan saat plugin DIHAPUS (bukan deactivate). Membersihkan:
 * 1. DROP TABLE bcs_jobs, bcs_plans, bcs_audit, bcs_connections, bcs_sync_runs.
 * 2. Option bcs_db_version + bcs_settings + bcs_sync_settings.
 * 3. Transient rate limit berprefix bcs_rate_.
 *
 * TIDAK menyentuh: CPT product/product_variation, term, term relationship,
 * attachment, atau meta batch _bcs_row_key/_bcs_job_id/_bcs_plan_id. Meta state
 * sync dihapus karena merupakan data internal koneksi yang sedang di-uninstall.
 *
 * @package BulkCatalogStudio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/*
 * Keamanan: jangan jalankan uninstall saat MULTISITE tanpa sadar —
 * situs per situs (current site saja, pola standar plugin tunggal).
 */

// 1. Drop tabel.
$jobs_table        = $wpdb->prefix . 'bcs_jobs';
$plans_table       = $wpdb->prefix . 'bcs_plans';
$audit_table       = $wpdb->prefix . 'bcs_audit';
$connections_table = $wpdb->prefix . 'bcs_connections';
$runs_table        = $wpdb->prefix . 'bcs_sync_runs';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- uninstall: drop tabel kustom persis spec §3.6; nama tabel dari prefix terkontrol (bukan input user).
$wpdb->query( "DROP TABLE IF EXISTS {$jobs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$plans_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$audit_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$connections_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$runs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Sync-only state belongs to the plugin; batch ownership meta remains intact.
$sync_meta_keys = array( '_bcs_sync_hash', '_bcs_synced_at', '_bcs_connection_id', '_bcs_conflict_flag' );
$placeholders = implode( ', ', array_fill( 0, count( $sync_meta_keys ), '%s' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", $sync_meta_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// 2. Options.
delete_option( 'bcs_db_version' );
delete_option( 'bcs_settings' );
delete_option( 'bcs_sync_settings' );

// 3. Transient rate limit (bcs_rate_* + timeout pasangannya).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall.
$rate_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_bcs_rate_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bcs_rate_' ) . '%'
	)
);

foreach ( (array) $rate_options as $option_name ) {
	delete_option( $option_name );
}
