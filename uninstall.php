<?php
/**
 * Foundation: Zero Mass uninstall routine.
 *
 * @package FoundationZeroMass
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'zmm_settings' );
delete_transient( 'zmm_library_stats' );

wp_clear_scheduled_hook( 'zmm_process_queue' );
wp_clear_scheduled_hook( 'zmm_daily_verification' );
wp_clear_scheduled_hook( 'zmm_backup_cleanup' );

$meta_query = new WP_Query(
	[
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [
			[
				'key'     => '_zmm_backup_path',
				'compare' => 'EXISTS',
			],
		],
	]
);

foreach ( $meta_query->posts as $attachment_id ) {
	$backup_path = get_post_meta( $attachment_id, '_zmm_backup_path', true );
	if ( $backup_path && file_exists( $backup_path ) && is_writable( $backup_path ) ) {
		@unlink( $backup_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

global $wpdb;

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_zmm_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
