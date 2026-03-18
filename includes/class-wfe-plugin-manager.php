<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Detects plugin status and handles install / activate actions.
 */
class WFE_Plugin_Manager {

	// Status constants
	const STATUS_ACTIVE      = 'active';
	const STATUS_INACTIVE    = 'inactive';
	const STATUS_NOT_INSTALLED = 'not_installed';

	/**
	 * Determine the installation/activation status of a plugin by slug.
	 *
	 * @param string $slug  e.g. "royal-elementor-addons"
	 * @return array{ status: string, plugin_file: string|null }
	 */
	public static function get_status( string $slug ): array {
		$plugin_file = self::find_plugin_file( $slug );

		if ( ! $plugin_file ) {
			return [
				'status'      => self::STATUS_NOT_INSTALLED,
				'plugin_file' => null,
			];
		}

		$is_active = is_plugin_active( $plugin_file );

		return [
			'status'      => $is_active ? self::STATUS_ACTIVE : self::STATUS_INACTIVE,
			'plugin_file' => $plugin_file,
		];
	}

	/**
	 * Batch-resolve statuses for multiple slugs.
	 * Much more efficient than calling get_status() per item because
	 * get_plugins() is called only once.
	 *
	 * @param string[] $slugs
	 * @return array<string, array{ status: string, plugin_file: string|null }>
	 */
	public static function get_statuses( array $slugs ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins   = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', [] );

		$index = [];
		foreach ( $all_plugins as $file => $data ) {
			$dir = dirname( $file );
			$index[ $dir ][] = $file;
			// Handle single-file plugins (e.g. hello.php → "hello")
			$basename = basename( $file, '.php' );
			$index[ $basename ][] = $file;
		}

		$statuses = [];
		foreach ( $slugs as $slug ) {
			$matches = $index[ $slug ] ?? [];
			if ( empty( $matches ) ) {
				$statuses[ $slug ] = [
					'status'      => self::STATUS_NOT_INSTALLED,
					'plugin_file' => null,
				];
				continue;
			}

			// Pick the best match: prefer slug/slug.php convention
			$plugin_file = self::pick_best_match( $slug, $matches );
			$is_active   = in_array( $plugin_file, $active_plugins, true );

			$statuses[ $slug ] = [
				'status'      => $is_active ? self::STATUS_ACTIVE : self::STATUS_INACTIVE,
				'plugin_file' => $plugin_file,
			];
		}

		return $statuses;
	}

	/**
	 * Install a plugin from WordPress.org using its slug.
	 * Must be called in an admin context with proper capabilities checked.
	 *
	 * @return true|WP_Error
	 */
	public static function install( string $slug ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error( 'permission_denied', __( 'You do not have permission to install plugins.', 'widget-finder' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api = plugins_api( 'plugin_information', [
			'slug'   => sanitize_key( $slug ),
			'fields' => [ 'short_description' => false, 'sections' => false, 'requires' => false ],
		] );

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Activate an installed plugin.
	 *
	 * @return true|WP_Error
	 */
	public static function activate( string $plugin_file ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'permission_denied', __( 'You do not have permission to activate plugins.', 'widget-finder' ) );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		global $wpdb;

		// Snapshot transients + any option whose name contains redirect-related
		// keywords BEFORE activation. After activation we delete newly-created
		// ones to prevent the plugin from triggering wp_safe_redirect() + exit;
		// on the next admin page load (which causes a blank editor page).
		//
		// Two common patterns:
		//  1. Transients: set_transient('myplugin_activation_redirect', …)
		//  2. Plain options: update_option('myplugin_setup_redirect', 'yes')
		//     e.g. PistonUI uses option_name = 'pistonui_setup_redirect'
		//
		// We never delete core WP options (active_plugins, etc.) because those
		// are updated (not created) by activate_plugin(), so their option_id
		// does not change and they would not appear as "new" anyway.
		$before = array_flip(
			$wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'" )
		);

		// Snapshot option_ids to detect brand-new rows (INSERT, not UPDATE).
		$before_ids = array_flip(
			$wpdb->get_col( "SELECT option_id FROM {$wpdb->options}" )
		);

		// Suppress any wp_redirect() calls made during activation itself.
		$suppress = static fn() => '';
		add_filter( 'wp_redirect', $suppress, PHP_INT_MAX );

		$result = activate_plugin( $plugin_file );

		remove_filter( 'wp_redirect', $suppress, PHP_INT_MAX );

		// 1. Delete new transients (safe — no core options use _transient_ prefix).
		$after_transients = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'" );
		foreach ( $after_transients as $key ) {
			if ( ! isset( $before[ $key ] ) ) {
				delete_option( $key );
			}
		}

		// 2. Delete brand-new options (not updates) whose names contain
		//    redirect/setup/wizard keywords — the typical naming for
		//    activation-redirect flags across plugins.
		$redirect_keywords = [ 'redirect', 'setup_wizard', 'wizard', 'onboard', 'first_run', 'first_time', 'activation' ];
		$all_rows = $wpdb->get_results( "SELECT option_id, option_name FROM {$wpdb->options}" );
		foreach ( $all_rows as $row ) {
			if ( isset( $before_ids[ (string) $row->option_id ] ) ) {
				continue; // existed before activation — skip
			}
			$name_lower = strtolower( $row->option_name );
			foreach ( $redirect_keywords as $kw ) {
				if ( str_contains( $name_lower, $kw ) ) {
					delete_option( $row->option_name );
					break;
				}
			}
		}

		return is_wp_error( $result ) ? $result : true;
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	private static function find_plugin_file( string $slug ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$matches     = [];

		foreach ( $all_plugins as $file => $data ) {
			if ( dirname( $file ) === $slug || basename( $file, '.php' ) === $slug ) {
				$matches[] = $file;
			}
		}

		return $matches ? self::pick_best_match( $slug, $matches ) : null;
	}

	private static function pick_best_match( string $slug, array $files ): string {
		// Prefer the canonical "slug/slug.php" pattern
		$canonical = $slug . '/' . $slug . '.php';
		if ( in_array( $canonical, $files, true ) ) {
			return $canonical;
		}
		return $files[0];
	}
}
