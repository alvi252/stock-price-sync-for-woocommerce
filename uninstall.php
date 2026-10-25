<?php
/**
 * Removes everything the plugin stored. Runs when the plugin is deleted.
 *
 * @package WSPS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the plugin's options, transients, cron event and log table from the current site.
 */
function wsps_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'wsps_cron_sync' );

	delete_option( 'wsps_settings' );
	delete_option( 'wsps_db_version' );
	delete_option( 'wsps_sync_lock' );

	// Per-user previews and notices are transients named wsps_preview_{user} and wsps_notice_{user}.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup; there is no API to delete transients by prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_wsps_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wsps_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wsps_sync_log' ) );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $wsps_site_id ) {
		switch_to_blog( (int) $wsps_site_id );
		wsps_uninstall_site();
		restore_current_blog();
	}
} else {
	wsps_uninstall_site();
}
