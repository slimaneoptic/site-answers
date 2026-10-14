<?php
/**
 * Delete everything the plugin stored: settings, the API key, the index, counters and scheduled jobs.
 *
 * @package Site_Answers
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 */
function site_answers_uninstall_site() {
	global $wpdb;

	$fixed = array(
		'site_answers_settings',
		'site_answers_api_key',
		'site_answers_last_error',
		'site_answers_index',
		'site_answers_build',
		'site_answers_queue',
		'site_answers_daily',
	);
	foreach ( $fixed as $name ) {
		delete_option( $name );
	}

	// Index shards of every generation and the per-IP counters (transients).
	$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time cleanup on uninstall.
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'site_answers_shard_' ) . '%',
			$wpdb->esc_like( '_transient_site_answers_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_site_answers_' ) . '%'
		)
	);
	foreach ( (array) $names as $name ) {
		if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
			continue; // Removed with its transient below.
		}
		if ( 0 === strpos( $name, '_transient_' ) ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		} else {
			delete_option( $name );
		}
	}

	wp_clear_scheduled_hook( 'site_answers_daily_rebuild' );
	wp_clear_scheduled_hook( 'site_answers_rebuild_continue' );
	wp_clear_scheduled_hook( 'site_answers_process_queue' );
}

if ( is_multisite() ) {
	$site_answers_blogs = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_answers_blogs as $site_answers_blog ) {
		switch_to_blog( $site_answers_blog );
		site_answers_uninstall_site();
		restore_current_blog();
	}
} else {
	site_answers_uninstall_site();
}
