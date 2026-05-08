<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Groups admin menus of WFE-installed plugins under a single "Plugin Settings"
 * toggle in the WordPress sidebar.
 *
 * Strategy: hook admin_menu at PHP_INT_MAX (after everything is registered),
 * detect which top-level menu items come from WFE-installed plugins via PHP
 * Reflection on their registered page callbacks, pull them out of $menu,
 * and re-insert them right after a new "Plugin Settings" toggle item.
 * The actual show/hide is done in JS so active-menu highlighting stays
 * with each plugin's own items.
 */
class WFX_Menu_Consolidator {

	const TOGGLE_SLUG = 'wfx-plugin-settings-group';

	/** Menu slugs moved into the group; populated by consolidate(), read by enqueue(). */
	private static array $grouped_slugs = [];

	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'consolidate' ], PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	// ── Menu reorganisation ───────────────────────────────────────────────────

	public static function consolidate(): void {
		global $menu;

		$detected = self::detect_wfx_menu_slugs();
		if ( empty( $detected ) ) return;

		// Pull matching items out of $menu.
		$to_move = [];
		foreach ( $menu as $pos => $item ) {
			if ( in_array( $item[2] ?? '', $detected, true ) ) {
				$to_move[] = $item;
				unset( $menu[ $pos ] );
			}
		}

		if ( empty( $to_move ) ) return;

		self::$grouped_slugs = array_column( $to_move, 2 );

		// Find a free starting position after Settings (80).
		$used       = array_keys( $menu );
		$toggle_pos = 82;
		while ( in_array( $toggle_pos, $used ) ) {
			$toggle_pos++;
		}

		// Register the "Plugin Settings" toggle page.
		add_menu_page(
			__( 'Plugin Settings', 'widget-finder-for-elementor' ),
			__( 'Plugin Settings', 'widget-finder-for-elementor' ),
			'manage_options',
			self::TOGGLE_SLUG,
			[ __CLASS__, 'render_fallback_page' ],
			'dashicons-admin-plugins',
			$toggle_pos
		);

		// Re-insert moved items right after the toggle.
		$pos = $toggle_pos;
		foreach ( $to_move as $item ) {
			$pos++;
			$used = array_keys( $menu ); // refresh after add_menu_page
			while ( in_array( $pos, $used ) ) {
				$pos++;
			}
			$menu[ $pos ] = $item;
		}

		ksort( $menu );
	}

	// ── Fallback page (no-JS) ─────────────────────────────────────────────────

	public static function render_fallback_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Plugin Settings', 'widget-finder-for-elementor' ) . '</h1>';
		echo '<p>' . esc_html__( 'Use the menu links on the left to access individual plugin settings.', 'widget-finder-for-elementor' ) . '</p>';
		echo '</div>';
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue(): void {
		if ( empty( self::$grouped_slugs ) ) return;

		wp_enqueue_style(
			'wfx-menu-collapse',
			WFX_URL . 'assets/css/wfe-menu-collapse.css',
			[],
			WFX_VERSION
		);

		wp_enqueue_script(
			'wfx-menu-collapse',
			WFX_URL . 'assets/js/wfe-menu-collapse.js',
			[],
			WFX_VERSION,
			true
		);

		wp_localize_script( 'wfx-menu-collapse', 'wfeMenuCollapse', [
			'toggleSlug' => self::TOGGLE_SLUG,
			'groupSlugs' => self::$grouped_slugs,
			'i18n'       => [
				'expand'   => __( 'Expand plugin settings', 'widget-finder-for-elementor' ),
				'collapse' => __( 'Collapse plugin settings', 'widget-finder-for-elementor' ),
			],
		] );
	}

	// ── Detection via Reflection ──────────────────────────────────────────────

	/**
	 * Returns the list of top-level $menu slugs that belong to WFE-installed plugins.
	 *
	 * Two detection passes:
	 *
	 * 1. Direct top-level scan — walk $menu, reflect each item's page callback,
	 *    match against WFE plugin dirs.  Covers plugins that register their own
	 *    top-level menu with add_menu_page().
	 *
	 * 2. Submenu parent scan — walk $submenu, reflect each sub-item's callback,
	 *    and when a WFE plugin is found, collect the parent slug.  Covers plugins
	 *    like WPZOOM that attach settings pages under a CPT-generated top-level
	 *    item (e.g. edit.php?post_type=wpzoom-shortcode) and never call
	 *    add_menu_page() themselves.
	 */
	private static function detect_wfx_menu_slugs(): array {
		global $menu, $submenu, $wp_filter;

		$tracked = WFX_Plugin_Tracker::get_all();
		if ( empty( $tracked ) ) return [];

		// Normalised plugin directory paths for all WFE-tracked plugins.
		// plugin_slug is stored as a bare folder name ("royal-elementor-addons"),
		// so use it directly.  Fall back to dirname(plugin_file) when available.
		$plugin_dirs = [];
		foreach ( $tracked as $p ) {
			$folder = $p->plugin_file ? dirname( $p->plugin_file ) : $p->plugin_slug;
			if ( ! $folder || $folder === '.' ) continue;
			$raw  = WP_PLUGIN_DIR . '/' . $folder;
			$real = realpath( $raw );
			$plugin_dirs[] = trailingslashit( $real ?: $raw );
		}
		$plugin_dirs = array_unique( $plugin_dirs );

		// Top-level slugs we never want to move (WP core + WFE's own menu).
		$skip = [
			'index.php', 'upload.php', 'edit.php', 'edit-comments.php',
			'themes.php', 'plugins.php', 'users.php', 'tools.php',
			'options-general.php', 'elementor', 'elementor-app',
			WFX_Admin::MENU_SLUG, self::TOGGLE_SLUG,
		];

		$slugs = [];

		// ── Pass 1: top-level $menu items ────────────────────────────────────
		foreach ( $menu as $item ) {
			$slug     = $item[2] ?? '';
			$hookname = $item[5] ?? '';

			if ( ! $slug || in_array( $slug, $skip, true ) ) continue;

			// Skip URL-style or file-path slugs (not add_menu_page-managed pages).
			if ( str_contains( $slug, '/' ) || str_contains( $slug, '.php' ) ) continue;

			if ( ! $hookname || ! isset( $wp_filter[ $hookname ] ) ) continue;

			$file = self::hook_source_file( $hookname );
			if ( ! $file ) continue;

			foreach ( $plugin_dirs as $dir ) {
				if ( str_starts_with( $file, $dir ) ) {
					$slugs[] = $slug;
					break;
				}
			}
		}

		// ── Pass 2: $submenu parent slugs ────────────────────────────────────
		// Some plugins (e.g. WPZOOM) only use add_submenu_page() and attach
		// under a CPT-generated top-level item.  Detect them by reflecting their
		// sub-page callbacks, then collect the parent slug for moving.
		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( $submenu as $parent_slug => $sub_items ) {
			if ( in_array( $parent_slug, $skip, true ) ) continue;
			if ( in_array( $parent_slug, $slugs, true ) ) continue; // already found

			foreach ( $sub_items as $sub_item ) {
				$sub_slug = $sub_item[2] ?? '';
				if ( ! $sub_slug ) continue;

				// Skip sub-items that are raw URLs (no page callback).
				if ( str_contains( $sub_slug, '?' ) || str_contains( $sub_slug, '://' ) ) continue;

				$hookname = get_plugin_page_hookname( $sub_slug, $parent_slug );
				if ( ! $hookname || ! isset( $wp_filter[ $hookname ] ) ) continue;

				$file = self::hook_source_file( $hookname );
				if ( ! $file ) continue;

				foreach ( $plugin_dirs as $dir ) {
					if ( str_starts_with( $file, $dir ) ) {
						$slugs[] = $parent_slug; // move the whole top-level parent
						break 2;                 // done with this parent
					}
				}
			}
		}

		return array_unique( $slugs );
	}

	/**
	 * Return the source file of the callback registered for a WP hook.
	 *
	 * add_menu_page() always registers its callback at priority 10.
	 * We look there first to avoid picking up callbacks added by OTHER plugins
	 * that hook into the same page (e.g. for tracking/analytics) at a different
	 * priority — which would cause false positives.
	 */
	private static function hook_source_file( string $hookname ): ?string {
		global $wp_filter;

		// Check priority 10 first (what add_menu_page uses).
		$callbacks_at_10 = $wp_filter[ $hookname ]->callbacks[10] ?? [];
		foreach ( $callbacks_at_10 as $entry ) {
			$file = self::reflect_file( $entry['function'] );
			if ( $file ) return $file;
		}

		// Fallback: scan other priorities (some plugins use non-default priority).
		foreach ( $wp_filter[ $hookname ]->callbacks as $priority => $priority_group ) {
			if ( $priority === 10 ) continue; // already checked
			foreach ( $priority_group as $entry ) {
				$file = self::reflect_file( $entry['function'] );
				if ( $file ) return $file;
			}
		}

		return null;
	}

	/** Use PHP Reflection to get the source file of any callable. */
	private static function reflect_file( $callable ): ?string {
		try {
			if ( $callable instanceof Closure || ( is_string( $callable ) && function_exists( $callable ) ) ) {
				return ( new ReflectionFunction( $callable ) )->getFileName() ?: null;
			}
			if ( is_array( $callable ) && count( $callable ) === 2 ) {
				return ( new ReflectionMethod( $callable[0], $callable[1] ) )->getFileName() ?: null;
			}
			if ( is_string( $callable ) && str_contains( $callable, '::' ) ) {
				[ $class, $method ] = explode( '::', $callable, 2 );
				return ( new ReflectionMethod( $class, $method ) )->getFileName() ?: null;
			}
		} catch ( ReflectionException $e ) {
			// Ignore — move on to the next callback.
		}
		return null;
	}
}
