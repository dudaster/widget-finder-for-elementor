<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST API endpoints for Widget Finder.
 *
 * Routes:
 *   GET  /wp-json/wfx/v1/search?q=term    — search widgets
 *   POST /wp-json/wfx/v1/install           — install a plugin
 *   POST /wp-json/wfx/v1/activate          — activate an installed plugin
 *   POST /wp-json/wfx/v1/deactivate        — deactivate a plugin
 *   POST /wp-json/wfx/v1/uninstall         — uninstall + remove from tracker
 */
class WFX_Rest_API {

	private static ?WFX_Rest_API $instance = null;

	public static function instance(): WFX_Rest_API {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'wfx/v1', '/search', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_search' ],
			'permission_callback' => [ $this, 'editor_permission' ],
			'args'                => [
				'q' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $v ) {
						return is_string( $v ) && strlen( trim( $v ) ) >= 1;
					},
				],
				'limit' => [
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 50,
				],
				'offset' => [
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				],
			],
		] );

		register_rest_route( 'wfx/v1', '/install', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_install' ],
			'permission_callback' => function () {
				return current_user_can( 'install_plugins' );
			},
			'args' => [
				'slug' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'plugin_name' => [
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( 'wfx/v1', '/activate', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_activate' ],
			'permission_callback' => function () {
				return current_user_can( 'activate_plugins' );
			},
			'args' => [
				'plugin_file' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( 'wfx/v1', '/deactivate', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_deactivate' ],
			'permission_callback' => function () {
				return current_user_can( 'activate_plugins' );
			},
			'args' => [
				'plugin_file' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( 'wfx/v1', '/debug', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_debug' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		register_rest_route( 'wfx/v1', '/remove', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_remove' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args' => [
				'slug' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		register_rest_route( 'wfx/v1', '/uninstall', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_uninstall' ],
			'permission_callback' => function () {
				return current_user_can( 'delete_plugins' );
			},
			'args' => [
				'slug' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'plugin_file' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	// ── Handlers ─────────────────────────────────────────────────────────

	public function handle_search( WP_REST_Request $request ): WP_REST_Response {
		$query  = $request->get_param( 'q' );
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );

		// Fetch one extra row to determine if there are more results.
		$rows = WFX_Database::search( $query, $limit + 1, $offset );

		if ( empty( $rows ) ) {
			return rest_ensure_response( [ 'items' => [], 'has_more' => false ] );
		}

		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows ); // remove the extra probe row
		}

		// Batch-resolve plugin statuses (single get_plugins() call)
		$slugs    = array_unique( array_column( $rows, 'plugin_slug' ) );
		$statuses = WFX_Plugin_Manager::get_statuses( $slugs );

		$items = [];
		foreach ( $rows as $row ) {
			$status_info = $statuses[ $row->plugin_slug ] ?? [
				'status'      => WFX_Plugin_Manager::STATUS_NOT_INSTALLED,
				'plugin_file' => null,
			];

			$items[] = [
				'widget_raw_id'     => (int) $row->widget_raw_id,
				'plugin_slug'       => $row->plugin_slug,
				'plugin_name'       => $row->plugin_name,
				'widget_title'      => $row->widget_title,
				'widget_type'       => $row->widget_type,
				'icon_type'         => $row->icon_type,
				'icon_value'        => $row->icon_value,
				'description_short' => $row->description_short,
				'active_installs'   => $row->active_installs ? (int) $row->active_installs : null,
				'rating_average'    => $row->rating_average ? (float) $row->rating_average : null,
				'status'            => $status_info['status'],
				'plugin_file'       => $status_info['plugin_file'],
			];
		}

		return rest_ensure_response( [ 'items' => $items, 'has_more' => $has_more ] );
	}

	public function handle_install( WP_REST_Request $request ): WP_REST_Response {
		$slug        = $request->get_param( 'slug' );
		$plugin_name = $request->get_param( 'plugin_name' );
		$result      = WFX_Plugin_Manager::install( $slug );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => $result->get_error_message(),
			], 500 );
		}

		// After install, find the plugin file
		$status = WFX_Plugin_Manager::get_status( $slug );

		// Record in tracker + snapshot widget-type mapping from WFE dataset
		WFX_Plugin_Tracker::record_install( $slug, $plugin_name ?: $slug, $status['plugin_file'] );
		WFX_Plugin_Tracker::record_widget_types( $slug );

		// Enforce inactive-unused limit: uninstall oldest if over the cap.
		$settings = WFX_Settings::get();
		if ( $settings['max_inactive_unused'] > 0 ) {
			WFX_Plugin_Tracker::enforce_inactive_limit( $settings['max_inactive_unused'] );
		}

		return rest_ensure_response( [
			'success'     => true,
			'plugin_file' => $status['plugin_file'],
		] );
	}

	public function handle_activate( WP_REST_Request $request ): WP_REST_Response {
		$plugin_file = $request->get_param( 'plugin_file' );
		$result      = WFX_Plugin_Manager::activate( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => $result->get_error_message(),
			], 500 );
		}

		// Enforce active-unused limit: deactivate oldest if over the cap.
		$settings = WFX_Settings::get();
		if ( $settings['max_active_unused'] > 0 ) {
			WFX_Plugin_Tracker::enforce_active_limit( $settings['max_active_unused'] );
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	public function handle_debug(): WP_REST_Response {
		global $wpdb;

		$map_table = $wpdb->prefix . 'widget_finder_plugin_widget_map';

		// 1. What's in the map table?
		$map_rows = $wpdb->get_results( 'SELECT plugin_slug, widget_type FROM ' . esc_sql( $map_table ) . ' ORDER BY plugin_slug, widget_type' ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input; table name sanitised via esc_sql()
		$map = [];
		foreach ( $map_rows as $row ) {
			$map[ $row->plugin_slug ][] = $row->widget_type;
		}

		// 2. What widget types actually exist in Elementor pages?
		$elementor_posts = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->postmeta} em ON em.post_id = pm.post_id
			    AND em.meta_key = '_elementor_edit_mode' AND em.meta_value = 'builder'
			 WHERE pm.meta_key = '_elementor_data'
			 LIMIT 20"
		) ?: [];

		$found_types = [];
		foreach ( $elementor_posts as $post ) {
			$data = json_decode( $post->meta_value, true );
			if ( $data ) {
				self::extract_widget_types( $data, $found_types );
			}
		}
		$found_types = array_unique( $found_types );
		sort( $found_types );

		// 3. Which tracked plugin slugs have NO map entries?
		$tracked = WFX_Plugin_Tracker::get_all();
		$no_map  = [];
		foreach ( $tracked as $p ) {
			if ( empty( $map[ $p->plugin_slug ] ) ) {
				$no_map[] = $p->plugin_slug;
			}
		}

		return rest_ensure_response( [
			'map'                     => $map,
			'widget_types_in_pages'   => $found_types,
			'plugins_with_no_map'     => $no_map,
			'elementor_posts_scanned' => count( $elementor_posts ),
		] );
	}

	private static function extract_widget_types( array $elements, array &$types ): void {
		foreach ( $elements as $el ) {
			if ( ! empty( $el['widgetType'] ) ) {
				$types[] = $el['widgetType'];
			}
			if ( ! empty( $el['elements'] ) ) {
				self::extract_widget_types( $el['elements'], $types );
			}
		}
	}

	public function handle_deactivate( WP_REST_Request $request ): WP_REST_Response {
		$plugin_file = $request->get_param( 'plugin_file' );

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( $plugin_file );

		return rest_ensure_response( [ 'success' => true ] );
	}

	public function handle_remove( WP_REST_Request $request ): WP_REST_Response {
		$slug = $request->get_param( 'slug' );

		// Remove from tracker and widget-type map only — plugin files are NOT touched.
		WFX_Plugin_Tracker::remove_widget_types( $slug );
		WFX_Plugin_Tracker::remove( $slug );
		WFX_Plugin_Tracker::clear_usage_cache( $slug );

		return rest_ensure_response( [ 'success' => true ] );
	}

	public function handle_uninstall( WP_REST_Request $request ): WP_REST_Response {
		$slug        = $request->get_param( 'slug' );
		$plugin_file = $request->get_param( 'plugin_file' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Deactivate first if active
		if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
			deactivate_plugins( $plugin_file );
		}

		// Delete plugin files via WP_Filesystem, bypassing uninstall hooks.
		if ( $plugin_file ) {
			WP_Filesystem();
			global $wp_filesystem;

			$plugins_dir = trailingslashit( WP_PLUGIN_DIR );
			$plugin_dir  = trailingslashit( dirname( $plugins_dir . $plugin_file ) );

			if ( strpos( $plugin_file, '/' ) !== false && $plugin_dir !== $plugins_dir ) {
				$deleted = $wp_filesystem->delete( $plugin_dir, true );
			} else {
				$deleted = $wp_filesystem->delete( $plugins_dir . $plugin_file );
			}

			if ( ! $deleted ) {
				return new WP_REST_Response( [
					'success' => false,
					'message' => __( 'Could not delete plugin files. Check file permissions.', 'widget-finder-for-elementor' ),
				], 500 );
			}
		}

		// Remove from tracker + widget-type map
		WFX_Plugin_Tracker::remove_widget_types( $slug );
		WFX_Plugin_Tracker::remove( $slug );
		WFX_Plugin_Tracker::clear_usage_cache( $slug );

		return rest_ensure_response( [ 'success' => true ] );
	}

	// ── Permissions ───────────────────────────────────────────────────────

	/**
	 * Allow any logged-in user that can access the Elementor editor.
	 */
	public function editor_permission(): bool {
		return current_user_can( 'edit_posts' );
	}
}
