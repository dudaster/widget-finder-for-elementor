<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registers the Widget Finder admin menu, Plugin Manager page, and Settings page.
 */
class WFX_Admin {

	const MENU_SLUG     = 'widget-finder';
	const PM_SLUG       = 'widget-finder-plugins';
	const SETTINGS_SLUG = 'widget-finder-settings';

	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_notice_manager' ] );
		add_action( 'admin_post_wfx_save_settings', [ __CLASS__, 'handle_save_settings' ] );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		// Top-level menu (hidden — used only for the page hook)
		add_menu_page(
			__( 'Widget Finder', 'widget-finder-for-elementor' ),
			__( 'Widget Finder', 'widget-finder-for-elementor' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_plugin_manager' ],
			'dashicons-search',
			58
		);

		// Plugin Manager sub-page (same as parent)
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Plugin Manager — Widget Finder', 'widget-finder-for-elementor' ),
			__( 'Plugin Manager', 'widget-finder-for-elementor' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_plugin_manager' ]
		);

		// Settings sub-page
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings — Widget Finder', 'widget-finder-for-elementor' ),
			__( 'Settings', 'widget-finder-for-elementor' ),
			'manage_options',
			self::SETTINGS_SLUG,
			[ __CLASS__, 'render_settings' ]
		);
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue_assets( string $hook ): void {
		$pm_hook  = 'toplevel_page_' . self::MENU_SLUG;
		$set_hook = 'widget-finder_page_' . self::SETTINGS_SLUG;

		if ( ! in_array( $hook, [ $pm_hook, $set_hook ], true ) ) {
			return;
		}

		wp_enqueue_style(
			'wfx-admin',
			WFX_URL . 'assets/css/wfe-admin.css',
			[],
			WFX_VERSION
		);

		wp_enqueue_script(
			'wfx-admin',
			WFX_URL . 'assets/js/wfe-admin.js',
			[],
			WFX_VERSION,
			true
		);

		wp_localize_script( 'wfx-admin', 'wfeAdmin', [
			'restUrl'      => rest_url( 'wfx/v1/' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'i18n'         => [
				'confirmDeactivate' => __( 'Deactivate this plugin? Elementor widgets from it will stop working on the frontend.', 'widget-finder-for-elementor' ),
				'confirmUninstall'  => __( 'Uninstall this plugin? This will delete all plugin files. This cannot be undone.', 'widget-finder-for-elementor' ),
				'confirmRemove'     => __( 'Remove this plugin from Widget Finder\'s list? The plugin will remain installed and active.', 'widget-finder-for-elementor' ),
				/* translators: %d is the number of selected plugins. */
				'confirmBulk'       => __( 'Apply this action to %d plugin(s)?', 'widget-finder-for-elementor' ),
				'deactivating'      => __( 'Deactivating…', 'widget-finder-for-elementor' ),
				'uninstalling'      => __( 'Uninstalling…', 'widget-finder-for-elementor' ),
				'removing'          => __( 'Removing…', 'widget-finder-for-elementor' ),
				'done'              => __( 'Done', 'widget-finder-for-elementor' ),
				/* translators: %s is the error message returned by the server. */
				'error'             => __( 'Error: %s', 'widget-finder-for-elementor' ),
				'uninstallCriticalError' => __( 'The plugin\'s uninstall script crashed. Delete it from WP Admin › Plugins, then use "Remove from list" here to clean up.', 'widget-finder-for-elementor' ),
			],
		] );
	}

	/**
	 * Enqueue the notification manager on every admin page.
	 * Skips editor screens (Elementor, Gutenberg/classic post editor).
	 */
	public static function enqueue_notice_manager( string $hook ): void {
		// Respect the user setting.
		$settings = WFX_Settings::get();
		if ( ! $settings['notification_center'] ) {
			return;
		}

		// Skip WordPress post/page editor screens (Gutenberg, classic, and Elementor).
		// Elementor editor and preview both run on post.php / post-new.php.
		if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		wp_enqueue_style(
			'wfx-notices',
			WFX_URL . 'assets/css/wfe-notices.css',
			[],
			WFX_VERSION
		);

		wp_enqueue_script(
			'wfx-notices',
			WFX_URL . 'assets/js/wfe-notices.js',
			[],
			WFX_VERSION,
			true  // footer
		);

		wp_localize_script( 'wfx-notices', 'wfeNotices', [
			'i18n' => [
				'title' => __( 'Notifications', 'widget-finder-for-elementor' ),
				'close' => __( 'Close', 'widget-finder-for-elementor' ),
			],
		] );
	}

	// ── Settings save handler ─────────────────────────────────────────────────

	public static function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'widget-finder-for-elementor' ) );
		}

		check_admin_referer( 'wfx_save_settings' );

		$data = [
			'widgets_per_page'        => absint( wp_unslash( $_POST['widgets_per_page'] ?? 20 ) ),
			'show_plugin_info_button' => isset( $_POST['show_plugin_info_button'] ),
			'notification_center'     => isset( $_POST['notification_center'] ),
			'menu_consolidation'      => isset( $_POST['menu_consolidation'] ),
			'max_active_unused'       => absint( wp_unslash( $_POST['max_active_unused'] ?? 5 ) ),
			'max_inactive_unused'     => absint( wp_unslash( $_POST['max_inactive_unused'] ?? 5 ) ),
		];

		WFX_Settings::update( $data );

		set_transient( 'wfx_settings_saved_' . get_current_user_id(), true, 30 );

		wp_safe_redirect( add_query_arg( [ 'page' => self::SETTINGS_SLUG ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Plugin Manager page ───────────────────────────────────────────────────

	public static function render_plugin_manager(): void {
		$tracked = WFX_Plugin_Tracker::get_all();

		// Clear usage cache on every page load so counts are always fresh.
		foreach ( $tracked as $plugin ) {
			WFX_Plugin_Tracker::clear_usage_cache( $plugin->plugin_slug );
		}

		// Enrich with live status
		$slugs    = array_column( $tracked, 'plugin_slug' );
		$statuses = $slugs ? WFX_Plugin_Manager::get_statuses( $slugs ) : [];

		?>
		<div class="wrap wfe-admin-wrap">
			<h1 class="wp-heading-inline">
				<span class="wfx-logo-icon dashicons dashicons-search"></span>
				<?php esc_html_e( 'Widget Finder — Plugin Manager', 'widget-finder-for-elementor' ); ?>
			</h1>
			<hr class="wp-header-end">

			<?php if ( empty( $tracked ) ) : ?>
				<div class="wfx-empty-state">
					<span class="dashicons dashicons-info-outline"></span>
					<p><?php esc_html_e( 'No plugins have been installed via Widget Finder yet.', 'widget-finder-for-elementor' ); ?></p>
					<p class="description"><?php esc_html_e( 'When you install a plugin from the Elementor editor search, it will appear here.', 'widget-finder-for-elementor' ); ?></p>
				</div>
			<?php else : ?>

			<form method="post" id="wfx-pm-form">
				<?php wp_nonce_field( 'wfx_bulk_action', 'wfx_bulk_nonce' ); ?>

				<div class="wfx-table-actions">
					<label for="wfx-bulk-select" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'widget-finder-for-elementor' ); ?></label>
					<select id="wfx-bulk-select" name="bulk_action">
						<option value=""><?php esc_html_e( 'Bulk Actions', 'widget-finder-for-elementor' ); ?></option>
						<option value="deactivate"><?php esc_html_e( 'Deactivate', 'widget-finder-for-elementor' ); ?></option>
						<option value="remove"><?php esc_html_e( 'Remove from list', 'widget-finder-for-elementor' ); ?></option>
						<option value="uninstall"><?php esc_html_e( 'Uninstall', 'widget-finder-for-elementor' ); ?></option>
					</select>
					<button type="button" id="wfx-bulk-apply" class="button"><?php esc_html_e( 'Apply', 'widget-finder-for-elementor' ); ?></button>
				</div>

				<table class="wfx-plugins-table wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="wfx-select-all" /></td>
							<th class="column-name"><?php esc_html_e( 'Plugin', 'widget-finder-for-elementor' ); ?></th>
							<th class="column-status"><?php esc_html_e( 'Status', 'widget-finder-for-elementor' ); ?></th>
							<th class="column-installed"><?php esc_html_e( 'Installed', 'widget-finder-for-elementor' ); ?></th>
							<th class="column-usage"><?php esc_html_e( 'Pages Using', 'widget-finder-for-elementor' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'widget-finder-for-elementor' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $tracked as $plugin ) :
						$slug        = $plugin->plugin_slug;
						$status_info = $statuses[ $slug ] ?? [ 'status' => 'not_installed', 'plugin_file' => null ];
						$status      = $status_info['status'];
						$plugin_file = $status_info['plugin_file'] ?? $plugin->plugin_file;
						$installed   = mysql2date( get_option( 'date_format' ), $plugin->installed_at );
						$usage       = WFX_Plugin_Tracker::get_usage_count( $slug );
					?>
						<tr class="wfx-plugin-row" data-slug="<?php echo esc_attr( $slug ); ?>" data-plugin-file="<?php echo esc_attr( $plugin_file ?? '' ); ?>">
							<td class="check-column">
								<input type="checkbox" name="plugins[]" value="<?php echo esc_attr( $slug ); ?>" />
							</td>
							<td class="column-name">
								<strong><?php echo esc_html( $plugin->plugin_name ?: $slug ); ?></strong>
								<div class="wfx-slug"><?php echo esc_html( $slug ); ?></div>
							</td>
							<td class="column-status">
								<?php self::render_status_badge( $status ); ?>
							</td>
							<td class="column-installed">
								<?php echo esc_html( $installed ); ?>
							</td>
							<td class="column-usage">
								<?php echo esc_html( number_format_i18n( $usage ) ); ?>
							</td>
							<td class="column-actions">
								<?php if ( $status === 'active' && $plugin_file ) : ?>
									<button type="button"
										class="button button-small wfe-btn-deactivate"
										data-plugin-file="<?php echo esc_attr( $plugin_file ); ?>"
									><?php esc_html_e( 'Deactivate', 'widget-finder-for-elementor' ); ?></button>
								<?php endif; ?>
								<button type="button"
									class="button button-small button-link-delete wfe-btn-uninstall"
									data-slug="<?php echo esc_attr( $slug ); ?>"
									data-plugin-file="<?php echo esc_attr( $plugin_file ?? '' ); ?>"
								><?php esc_html_e( 'Uninstall', 'widget-finder-for-elementor' ); ?></button>

							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</form>

			<?php endif; ?>
		</div>
		<?php
	}

	// ── Settings page ─────────────────────────────────────────────────────────

	public static function render_settings(): void {
		$s = WFX_Settings::get();
		?>
		<div class="wrap wfe-admin-wrap">
			<h1>
				<span class="wfx-logo-icon dashicons dashicons-search"></span>
				<?php esc_html_e( 'Widget Finder — Settings', 'widget-finder-for-elementor' ); ?>
			</h1>

			<?php
			$wfx_saved = get_transient( 'wfx_settings_saved_' . get_current_user_id() );
			if ( $wfx_saved ) {
				delete_transient( 'wfx_settings_saved_' . get_current_user_id() );
			}
			?>
			<?php if ( $wfx_saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'widget-finder-for-elementor' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wfx_save_settings" />
				<?php wp_nonce_field( 'wfx_save_settings' ); ?>

				<table class="form-table wfe-settings-table">
					<tbody>

						<tr>
							<th scope="row">
								<label for="widgets_per_page"><?php esc_html_e( 'Widgets per page', 'widget-finder-for-elementor' ); ?></label>
							</th>
							<td>
								<input type="number" id="widgets_per_page" name="widgets_per_page"
									value="<?php echo esc_attr( $s['widgets_per_page'] ); ?>"
									min="5" max="50" step="5" class="small-text" />
								<p class="description"><?php esc_html_e( 'How many results to show in the Elementor panel before paginating (5–50).', 'widget-finder-for-elementor' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Widget cards', 'widget-finder-for-elementor' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="show_plugin_info_button" value="1"
										<?php checked( $s['show_plugin_info_button'] ); ?> />
									<?php esc_html_e( 'Show plugin info (description, ratings) in widget cards', 'widget-finder-for-elementor' ); ?>
								</label>
							</td>
						</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Notification center', 'widget-finder-for-elementor' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="notification_center" value="1"
									<?php checked( $s['notification_center'] ); ?> />
								<?php esc_html_e( 'Collect admin notices in a bell icon instead of showing them inline', 'widget-finder-for-elementor' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Consolidate plugin menus', 'widget-finder-for-elementor' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="menu_consolidation" value="1"
									<?php checked( $s['menu_consolidation'] ); ?> />
								<?php esc_html_e( 'Group WFE-installed plugin menus under a "Plugin Settings" toggle in the sidebar', 'widget-finder-for-elementor' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wfx_max_active_unused"><?php esc_html_e( 'Keep max unused active plugins', 'widget-finder-for-elementor' ); ?></label>
						</th>
						<td>
							<input type="number" id="wfx_max_active_unused" name="max_active_unused"
								value="<?php echo esc_attr( $s['max_active_unused'] ); ?>"
								min="0" max="50" step="1" class="small-text" />
							<p class="description">
								<?php esc_html_e( 'Maximum WFE-installed plugins with 0 widget usage that may remain active. Oldest are deactivated when a new plugin is activated. Set to 0 to disable.', 'widget-finder-for-elementor' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wfx_max_inactive_unused"><?php esc_html_e( 'Keep max inactive unused plugins', 'widget-finder-for-elementor' ); ?></label>
						</th>
						<td>
							<input type="number" id="wfx_max_inactive_unused" name="max_inactive_unused"
								value="<?php echo esc_attr( $s['max_inactive_unused'] ); ?>"
								min="0" max="50" step="1" class="small-text" />
							<p class="description">
								<?php esc_html_e( 'Maximum WFE-installed plugins with 0 widget usage that may remain installed (inactive). Oldest are uninstalled when a new plugin is installed. Set to 0 to disable.', 'widget-finder-for-elementor' ); ?>
							</p>
						</td>
					</tr>

				</tbody>
				</table>

				<?php submit_button( __( 'Save Settings', 'widget-finder-for-elementor' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function render_status_badge( string $status ): void {
		$labels = [
			'active'        => [ __( 'Active', 'widget-finder-for-elementor' ),        'wfx-badge-active' ],
			'inactive'      => [ __( 'Inactive', 'widget-finder-for-elementor' ),       'wfx-badge-inactive' ],
			'not_installed' => [ __( 'Not installed', 'widget-finder-for-elementor' ),  'wfx-badge-missing' ],
		];

		[ $label, $class ] = $labels[ $status ] ?? [ ucfirst( $status ), 'wfx-badge-inactive' ];
		echo '<span class="wfx-badge ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}
}
