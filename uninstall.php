<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: revoke the Google
 * connection and remove the plugin's data. Posts already on Google stay there.
 *
 * @package GBP_Post_Sync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-gbp-post-sync-crypto.php';

/**
 * Remove one site's data.
 *
 * @return void
 */
function gbp_post_sync_uninstall_site() {
	global $wpdb;

	// Ask Google to revoke the token, so the site's access doesn't linger in
	// the Google account. Best effort: the data goes either way.
	$auth  = get_option( 'gbp_post_sync_auth', array() );
	$token = is_array( $auth ) && isset( $auth['refresh_token'] ) ? GBP_Post_Sync_Crypto::decrypt( $auth['refresh_token'] ) : '';

	if ( '' !== $token ) {
		wp_remote_post(
			'https://oauth2.googleapis.com/revoke',
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'body'        => array( 'token' => $token ),
			)
		);
	}

	foreach ( array( 'gbp_post_sync_settings', 'gbp_post_sync_auth', 'gbp_post_sync_log' ) as $option ) {
		delete_option( $option );
	}

	delete_transient( 'gbp_post_sync_token' );
	delete_transient( 'gbp_post_sync_locations' );

	foreach ( array( '_gbp_post_sync_share', '_gbp_post_sync_summary', '_gbp_post_sync_posts', '_gbp_post_sync_status' ) as $key ) {
		delete_post_meta_by_key( $key );
	}

	foreach ( array( 'gbp_post_sync_share', 'gbp_post_sync_update', 'gbp_post_sync_remove' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}

	// Per-user OAuth and error transients, and any leftover lock rows.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_gbp_post_sync_oauth_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_gbp_post_sync_oauth_' ) . '%',
			$wpdb->esc_like( '_transient_gbp_post_sync_error_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_gbp_post_sync_error_' ) . '%',
			$wpdb->esc_like( 'gbp_post_sync_lock_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	$gbp_post_sync_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $gbp_post_sync_sites as $gbp_post_sync_site ) {
		switch_to_blog( $gbp_post_sync_site );
		gbp_post_sync_uninstall_site();
		restore_current_blog();
	}
} else {
	gbp_post_sync_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-gbp-post-sync' );
