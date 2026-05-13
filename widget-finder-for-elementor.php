<?php
/**
 * Plugin Name:       Widget Finder for Elementor
 * Plugin URI:        https://github.com/dudaster/widget-finder-for-elementor
 * Description:       Extends Elementor's widget search to surface widgets from installed, inactive, and not-yet-installed plugins. Includes Plugin Manager, Notification Center, and Menu Consolidation.
 * Version:           1.7.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Liviu Dudas
 * Author URI:        https://dudaster.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       widget-finder-for-elementor
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WFX_VERSION', '1.7.0' );

// ── Auto-update from GitHub ────────────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'lib/plugin-update-checker/plugin-update-checker.php';
$wfx_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/dudaster/widget-finder-for-elementor',
	__FILE__,
	'widget-finder-for-elementor'
);
$wfx_updater->getVcsApi()->enableReleaseAssets();
define( 'WFX_FILE', __FILE__ );
define( 'WFX_PATH', plugin_dir_path( __FILE__ ) );
define( 'WFX_URL', plugin_dir_url( __FILE__ ) );
define( 'WFX_SLUG', 'widget-finder-for-elementor' );

// Widget data is bundled as data/widgets.json and imported into custom DB tables
// on activation.  tools/build-widgets-json.py regenerates the JSON from SQLite.

// ── Activation ────────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, function () {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wfx-importer.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wfx-plugin-tracker.php';

	WFX_Importer::create_tables();         // widget dataset tables
	WFX_Plugin_Tracker::create_table();    // plugin-tracking tables
	WFX_Importer::maybe_import();          // initial data load from widgets.json
} );

register_deactivation_hook( __FILE__, function () {
	$timestamp = wp_next_scheduled( 'wfx_daily_cleanup' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wfx_daily_cleanup' );
	}
} );

/**
 * Bootstrap the plugin.
 *
 * We hook on plugins_loaded (not elementor/init) because we also need
 * to register REST API routes which run before Elementor is loaded.
 */
function wfx_init() {
	require_once WFX_PATH . 'includes/class-wfx-importer.php';
	require_once WFX_PATH . 'includes/class-wfx-database.php';
	require_once WFX_PATH . 'includes/class-wfx-plugin-manager.php';
	require_once WFX_PATH . 'includes/class-wfx-settings.php';
	require_once WFX_PATH . 'includes/class-wfx-plugin-tracker.php';
	require_once WFX_PATH . 'includes/class-wfx-rest-api.php';
	require_once WFX_PATH . 'includes/class-wfx-elementor-integration.php';
	require_once WFX_PATH . 'includes/class-wfx-admin.php';
	require_once WFX_PATH . 'includes/class-wfx-menu-consolidator.php';
	require_once WFX_PATH . 'includes/class-wfx-plugin.php';

	// Widget dataset: re-import if DATA_VERSION changed (e.g. after plugin update).
	WFX_Importer::maybe_import();

	// Tracker tables: create/upgrade if plugin version changed.
	if ( get_option( 'wfx_db_version' ) !== WFX_VERSION ) {
		WFX_Plugin_Tracker::create_table();
		update_option( 'wfx_db_version', WFX_VERSION );
	}

	// Rebuild widget-type maps when the exclusion logic changes.
	// Bump this constant to force a one-time rebuild for all tracked plugins.
	$map_version = '3';
	if ( get_option( 'wfx_map_version' ) !== $map_version ) {
		WFX_Plugin_Tracker::rebuild_all_maps();
		update_option( 'wfx_map_version', $map_version );
	}

	WFX_Plugin::instance();
	WFX_Admin::init();

	// Boot menu consolidation only if the user has enabled it.
	$wfx_settings = WFX_Settings::get();
	if ( $wfx_settings['menu_consolidation'] ) {
		WFX_Menu_Consolidator::init();
	}
}
add_action( 'plugins_loaded', 'wfx_init' );
