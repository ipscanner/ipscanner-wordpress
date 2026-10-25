<?php
/**
 * Removes the plugin's options and cached data.
 *
 * @package IPScanner
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes options and transients for the current site.
 */
function ipscanner_uninstall_site() {
	global $wpdb;
	foreach ( array( 'ipscanner_settings', 'ipscanner_log', 'ipscanner_stats', 'ipscanner_onboarding', 'ipscanner_policy_cache', 'ipscanner_last_error', 'ipscanner_edge_mode', 'ipscanner_enforce_paused', 'ipscanner_account', 'ipscanner_version', 'ipscanner_flagged_total', 'ipscanner_installed_at', 'ipscanner_review' ) as $ipscanner_option ) {
		delete_option( $ipscanner_option );
	}
	// Cached verdicts are transients keyed by a hash, so they are removed by prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ipscanner_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ipscanner_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $ipscanner_blog_id ) {
		switch_to_blog( $ipscanner_blog_id );
		ipscanner_uninstall_site();
		restore_current_blog();
	}
} else {
	ipscanner_uninstall_site();
}
