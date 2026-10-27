<?php
/**
 * Plugin Name:       Google Business Profile Post Sync
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Publishes WordPress posts as Business Profile updates.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gbp-post-sync
 * Update URI:        https://github.com/jmsprsns/gbp-post-sync
 *
 * @package GBP_Post_Sync
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$gbp_post_sync_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'GBP_POST_SYNC_VERSION', $gbp_post_sync_headers['version'] );
define( 'GBP_POST_SYNC_REPO', $gbp_post_sync_headers['repo'] );
define( 'GBP_POST_SYNC_FILE', __FILE__ );
define( 'GBP_POST_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'GBP_POST_SYNC_URL', plugin_dir_url( __FILE__ ) );

unset( $gbp_post_sync_headers );

require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-icons.php';
require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-updater.php';
require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-menu.php';
require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-feedback.php';
require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-crypto.php';
require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-log.php';
require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-google.php';
require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-sync.php';
require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-editor.php';
require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-admin.php';
require_once GBP_POST_SYNC_DIR . 'includes/class-gbp-post-sync-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
GBP_Post_Sync_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return GBP_Post_Sync_Plugin
 */
function gbp_post_sync_plugin() {
	return GBP_Post_Sync_Plugin::instance();
}
add_action( 'plugins_loaded', 'gbp_post_sync_plugin' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings, the Google connection and the record of what was shared stay put
 * (uninstall.php removes them). Queued jobs are dropped, so nothing is posted
 * to Google while the plugin is off.
 *
 * @return void
 */
function gbp_post_sync_deactivate() {
	foreach ( GBP_Post_Sync_Sync::CRON_HOOKS as $hook ) {
		wp_unschedule_hook( $hook );
	}
}
register_deactivation_hook( GBP_POST_SYNC_FILE, 'gbp_post_sync_deactivate' );
