<?php
/**
 * Plugin Name:       Widget Finder for Elementor
 * Plugin URI:        https://dudaster.com
 * Description:       Extends Elementor's widget search to surface widgets from installed, inactive, and not-yet-installed plugins.
 * Version:           1.3.9
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Liviu Dudas
 * Author URI:        https://dudaster.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       widget-finder
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WFE_VERSION', '1.3.9' );
define( 'WFE_FILE', __FILE__ );
define( 'WFE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WFE_URL', plugin_dir_url( __FILE__ ) );
define( 'WFE_SLUG', 'widget-finder' );

// Widget Finder DB connection constants — can be overridden in wp-config.php
if ( ! defined( 'WFE_DB_NAME' ) )     define( 'WFE_DB_NAME', 'widgetfinder' );
if ( ! defined( 'WFE_DB_USER' ) )     define( 'WFE_DB_USER', 'root' );
if ( ! defined( 'WFE_DB_PASSWORD' ) ) define( 'WFE_DB_PASSWORD', 'root' );
// Use the same host as WordPress (same MariaDB server, different database)
if ( ! defined( 'WFE_DB_HOST' ) )     define( 'WFE_DB_HOST', DB_HOST );

// ── Activation ────────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, function () {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wfe-plugin-tracker.php';
	WFE_Plugin_Tracker::create_table();
} );

register_deactivation_hook( __FILE__, function () {
	$timestamp = wp_next_scheduled( 'wfe_daily_cleanup' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wfe_daily_cleanup' );
	}
} );

/**
 * Bootstrap the plugin.
 *
 * We hook on plugins_loaded (not elementor/init) because we also need
 * to register REST API routes which run before Elementor is loaded.
 */
function wfe_init() {
	require_once WFE_PATH . 'includes/class-wfe-database.php';
	require_once WFE_PATH . 'includes/class-wfe-plugin-manager.php';
	require_once WFE_PATH . 'includes/class-wfe-settings.php';
	require_once WFE_PATH . 'includes/class-wfe-plugin-tracker.php';
	require_once WFE_PATH . 'includes/class-wfe-rest-api.php';
	require_once WFE_PATH . 'includes/class-wfe-elementor-integration.php';
	require_once WFE_PATH . 'includes/class-wfe-admin.php';
	require_once WFE_PATH . 'includes/class-wfe-plugin.php';

	// Ensure DB tables exist (dbDelta is idempotent — safe on every load).
	if ( get_option( 'wfe_db_version' ) !== WFE_VERSION ) {
		WFE_Plugin_Tracker::create_table();
		update_option( 'wfe_db_version', WFE_VERSION );
	}

	// Rebuild widget-type maps when the exclusion logic changes.
	// Bump this constant to force a one-time rebuild for all tracked plugins.
	$map_version = '3';
	if ( get_option( 'wfe_map_version' ) !== $map_version ) {
		WFE_Plugin_Tracker::rebuild_all_maps();
		update_option( 'wfe_map_version', $map_version );
	}

	WFE_Plugin::instance();
	WFE_Admin::init();
}
add_action( 'plugins_loaded', 'wfe_init' );
