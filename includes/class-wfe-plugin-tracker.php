<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tracks plugins installed via Widget Finder.
 *
 * Tables (both in the WP database):
 *
 *   {prefix}widget_finder_plugins
 *     Installed plugin records (slug, name, file, date).
 *
 *   {prefix}widget_finder_plugin_widget_map
 *     Snapshot of plugin_slug → widget_type mappings taken from the WFE
 *     dataset at install time.  Usage detection queries this table instead
 *     of the remote WFE database, ensuring accuracy and performance.
 */
class WFE_Plugin_Tracker {

	const TABLE_PLUGINS = 'widget_finder_plugins';
	const TABLE_MAP     = 'widget_finder_plugin_widget_map';

	// ── Schema ────────────────────────────────────────────────────────────────

	public static function create_table(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$sql_plugins = "CREATE TABLE {$wpdb->prefix}" . self::TABLE_PLUGINS . " (
			id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			plugin_slug  varchar(191)        NOT NULL,
			plugin_name  varchar(255)        NOT NULL DEFAULT '',
			plugin_file  varchar(255)                 DEFAULT NULL,
			installed_at datetime            NOT NULL,
			installed_by bigint(20) unsigned          DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY   plugin_slug (plugin_slug)
		) {$charset};";

		$sql_map = "CREATE TABLE {$wpdb->prefix}" . self::TABLE_MAP . " (
			plugin_slug  varchar(191) NOT NULL,
			widget_type  varchar(191) NOT NULL,
			PRIMARY KEY  (plugin_slug, widget_type),
			KEY          widget_type (widget_type)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_plugins );
		dbDelta( $sql_map );
	}

	// ── Write: plugins ────────────────────────────────────────────────────────

	public static function record_install( string $slug, string $plugin_name, ?string $plugin_file = null ): void {
		global $wpdb;

		$wpdb->replace(
			$wpdb->prefix . self::TABLE_PLUGINS,
			[
				'plugin_slug'  => $slug,
				'plugin_name'  => $plugin_name,
				'plugin_file'  => $plugin_file,
				'installed_at' => current_time( 'mysql' ),
				'installed_by' => get_current_user_id() ?: null,
			],
			[ '%s', '%s', '%s', '%s', '%d' ]
		);
	}

	public static function remove( string $slug ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . self::TABLE_PLUGINS, [ 'plugin_slug' => $slug ], [ '%s' ] );
	}

	// ── Write: widget-type map ─────────────────────────────────────────────────

	/**
	 * Snapshot the widget types for a plugin from the WFE dataset into the
	 * local map table.  Call this immediately after record_install().
	 *
	 * Storing the mapping locally means usage detection never needs to query
	 * the remote WFE database — and the data is accurate as of install time.
	 */
	public static function record_widget_types( string $slug ): void {
		global $wpdb;

		try {
			$wfe_db = WFE_Database::connection();
			// Use internal_widget_key from runtime_widgets — this is the actual
			// Elementor widget registration slug (e.g. "plexx_text", "pistonui_accordion"),
			// not the functional category stored in runtime_widgets_search.widget_type.
			$types  = $wfe_db->get_col(
				$wfe_db->prepare(
					'SELECT DISTINCT internal_widget_key FROM runtime_widgets WHERE plugin_slug = %s AND internal_widget_key IS NOT NULL AND LENGTH(internal_widget_key) >= 3',
					$slug
				)
			) ?: [];
		} catch ( \Throwable $e ) {
			return;
		}

		// Strip Elementor core types — they appear in every page and would
		// produce false positives in the usage count.
		$core  = self::elementor_core_types();
		$types = array_values( array_filter( $types, fn( $t ) => ! in_array( $t, $core, true ) ) );

		$map_table = $wpdb->prefix . self::TABLE_MAP;

		// Always replace the full set so stale entries are removed.
		$wpdb->delete( $map_table, [ 'plugin_slug' => $slug ], [ '%s' ] );

		foreach ( $types as $type ) {
			$wpdb->insert(
				$map_table,
				[ 'plugin_slug' => $slug, 'widget_type' => $type ],
				[ '%s', '%s' ]
			);
		}
	}

	/**
	 * Rebuild widget-type maps for all tracked plugins.
	 * Called once when the map logic changes (keyed by MAP_VERSION).
	 */
	public static function rebuild_all_maps(): void {
		foreach ( self::get_all() as $plugin ) {
			self::record_widget_types( $plugin->plugin_slug );
		}
	}

	private static function elementor_core_types(): array {
		return [
			'section', 'column', 'container', 'inner-section', 'common',
			'heading', 'text-editor', 'image', 'video', 'button', 'divider',
			'spacer', 'google_maps', 'icon', 'image-box', 'gallery',
			'image-carousel', 'icon-box', 'star-rating', 'testimonial', 'tabs',
			'accordion', 'toggle', 'social-icons', 'alert', 'audio', 'shortcode',
			'html', 'menu-anchor', 'sidebar', 'read-more', 'text-path',
			'theme-post-content', 'theme-post-excerpt', 'theme-post-featured-image',
			'theme-post-title', 'theme-site-logo', 'theme-site-tagline',
			'theme-site-title', 'theme-post-navigation', 'theme-post-info',
			'theme-author-box', 'theme-loop-grid', 'theme-archive-title',
			'theme-archive-posts', 'theme-breadcrumbs', 'theme-search-form',
			'theme-post-comments',
			'wc-products', 'wc-menu-cart', 'wc-product-title', 'wc-product-images',
			'wc-product-price', 'wc-product-add-to-cart', 'wc-product-rating',
			'wc-product-stock', 'wc-product-meta', 'wc-product-content',
			'wc-product-data-tabs', 'wc-product-related', 'wc-product-upsell',
			'wc-cart', 'wc-checkout', 'wc-my-account', 'wc-purchase-summary',
			'wc-product-breadcrumbs', 'wc-archive-products',
			'wp-widget-archives', 'wp-widget-calendar', 'wp-widget-categories',
			'wp-widget-custom_html', 'wp-widget-media_audio', 'wp-widget-media_gallery',
			'wp-widget-media_image', 'wp-widget-media_video', 'wp-widget-meta',
			'wp-widget-nav_menus', 'wp-widget-pages', 'wp-widget-recent-comments',
			'wp-widget-recent-posts', 'wp-widget-rss', 'wp-widget-search',
			'wp-widget-tag_cloud', 'wp-widget-text',
		];
	}

	public static function remove_widget_types( string $slug ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . self::TABLE_MAP, [ 'plugin_slug' => $slug ], [ '%s' ] );
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	public static function get_all(): array {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT * FROM ' . $wpdb->prefix . self::TABLE_PLUGINS . ' ORDER BY installed_at DESC'
		) ?: [];
	}

	public static function get( string $slug ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . self::TABLE_PLUGINS . ' WHERE plugin_slug = %s',
				$slug
			)
		) ?: null;
	}

	// ── Usage count ───────────────────────────────────────────────────────────

	/**
	 * Count Elementor-edited posts that use at least one widget from this plugin.
	 *
	 * How it works:
	 *   1. Read the plugin → widget_type mapping saved locally at install time.
	 *   2. Scan _elementor_data in wp_postmeta for those widget types.
	 *   3. Return the count of distinct posts that contain at least one match.
	 *
	 * Working with the local map (not the WFE database) means:
	 *   - No false positives from overlapping core-Elementor types.
	 *   - No dependency on the remote WFE database connection at check time.
	 *   - Fast: only the widget types known to belong to this plugin are searched.
	 */
	public static function get_usage_count( string $slug ): int {
		$cache_key = 'wfe_usage_' . md5( $slug );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = self::compute_usage_count( $slug );
		set_transient( $cache_key, $count, HOUR_IN_SECONDS );
		return $count;
	}

	public static function clear_usage_cache( string $slug ): void {
		delete_transient( 'wfe_usage_' . md5( $slug ) );
	}

	// ── Limit enforcement ─────────────────────────────────────────────────────

	/**
	 * Deactivate the oldest WFE-tracked plugins with 0 widget usage until at
	 * most $max active-unused plugins remain.  Called after a plugin is activated.
	 */
	public static function enforce_active_limit( int $max ): void {
		if ( $max <= 0 ) return;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all   = array_reverse( self::get_all() ); // oldest first
		$slugs = array_column( $all, 'plugin_slug' );
		if ( empty( $slugs ) ) return;

		$statuses = WFE_Plugin_Manager::get_statuses( $slugs );

		$unused_active = [];
		foreach ( $all as $plugin ) {
			$info = $statuses[ $plugin->plugin_slug ] ?? [ 'status' => 'not_installed', 'plugin_file' => null ];
			if ( $info['status'] !== WFE_Plugin_Manager::STATUS_ACTIVE ) continue;
			if ( self::get_usage_count( $plugin->plugin_slug ) > 0 ) continue;
			$unused_active[] = [ 'plugin' => $plugin, 'plugin_file' => $info['plugin_file'] ];
		}

		if ( count( $unused_active ) <= $max ) return;

		$to_deactivate = array_slice( $unused_active, 0, count( $unused_active ) - $max );
		foreach ( $to_deactivate as $item ) {
			if ( $item['plugin_file'] ) {
				deactivate_plugins( $item['plugin_file'] );
			}
		}
	}

	/**
	 * Uninstall the oldest WFE-tracked plugins with 0 widget usage until at
	 * most $max inactive-unused plugins remain.  Called after a plugin is installed.
	 */
	public static function enforce_inactive_limit( int $max ): void {
		if ( $max <= 0 ) return;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all   = array_reverse( self::get_all() ); // oldest first
		$slugs = array_column( $all, 'plugin_slug' );
		if ( empty( $slugs ) ) return;

		$statuses = WFE_Plugin_Manager::get_statuses( $slugs );

		$unused_inactive = [];
		foreach ( $all as $plugin ) {
			$info = $statuses[ $plugin->plugin_slug ] ?? [ 'status' => 'not_installed', 'plugin_file' => null ];
			if ( $info['status'] !== WFE_Plugin_Manager::STATUS_INACTIVE ) continue;
			if ( self::get_usage_count( $plugin->plugin_slug ) > 0 ) continue;
			$unused_inactive[] = [ 'plugin' => $plugin, 'plugin_file' => $info['plugin_file'] ];
		}

		if ( count( $unused_inactive ) <= $max ) return;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		$plugins_dir   = trailingslashit( WP_PLUGIN_DIR );
		$to_uninstall  = array_slice( $unused_inactive, 0, count( $unused_inactive ) - $max );

		foreach ( $to_uninstall as $item ) {
			$plugin      = $item['plugin'];
			$plugin_file = $item['plugin_file'];

			if ( $plugin_file ) {
				$plugin_dir = trailingslashit( dirname( $plugins_dir . $plugin_file ) );
				if ( strpos( $plugin_file, '/' ) !== false && $plugin_dir !== $plugins_dir ) {
					$wp_filesystem->delete( $plugin_dir, true );
				} else {
					$wp_filesystem->delete( $plugins_dir . $plugin_file );
				}
			}

			self::remove_widget_types( $plugin->plugin_slug );
			self::remove( $plugin->plugin_slug );
			self::clear_usage_cache( $plugin->plugin_slug );
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private static function compute_usage_count( string $slug ): int {
		global $wpdb;

		$map_table = $wpdb->prefix . self::TABLE_MAP;

		// Read widget types from the local map saved at install time.
		$types = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT widget_type FROM {$map_table} WHERE plugin_slug = %s",
				$slug
			)
		) ?: [];

		// Map is empty — plugin was installed before the map feature existed.
		// Backfill from the WFE dataset now and re-read.
		if ( empty( $types ) ) {
			self::record_widget_types( $slug );
			$types = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT widget_type FROM {$map_table} WHERE plugin_slug = %s",
					$slug
				)
			) ?: [];
		}

		if ( empty( $types ) ) {
			return 0;
		}

		// Build one LIKE clause per widget type.
		// Elementor saves compact JSON ("widgetType":"type") but some tools add a
		// space after the colon ("widgetType": "type") — match both variants.
		$like_parts = [];
		foreach ( $types as $type ) {
			$escaped = $wpdb->esc_like( $type );
			$like_parts[] = $wpdb->prepare(
				'(pm.meta_value LIKE %s OR pm.meta_value LIKE %s)',
				'%"widgetType":"' . $escaped . '"%',
				'%"widgetType": "' . $escaped . '"%'
			);
		}

		// Count distinct posts that:
		//   a) have _elementor_data meta (Elementor content)
		//   b) were built with Elementor (_elementor_edit_mode = 'builder')
		//   c) are not revisions (post_type != 'revision')
		//   d) contain at least one widget type from this plugin
		return (int) $wpdb->get_var( "
			SELECT COUNT(DISTINCT pm.post_id)
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->postmeta} em
				ON  em.post_id    = pm.post_id
				AND em.meta_key   = '_elementor_edit_mode'
				AND em.meta_value = 'builder'
			INNER JOIN {$wpdb->posts} p
				ON  p.ID          = pm.post_id
				AND p.post_type  != 'revision'
			WHERE pm.meta_key = '_elementor_data'
			AND ( " . implode( ' OR ', $like_parts ) . ' )
		' );
	}
}
