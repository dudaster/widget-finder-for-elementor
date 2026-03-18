<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registers the Widget Finder admin menu, Plugin Manager page, and Settings page.
 */
class WFE_Admin {

	const MENU_SLUG     = 'widget-finder';
	const PM_SLUG       = 'widget-finder-plugins';
	const SETTINGS_SLUG = 'widget-finder-settings';

	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_notice_manager' ] );
		add_action( 'admin_post_wfe_save_settings', [ __CLASS__, 'handle_save_settings' ] );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		// Top-level menu (hidden — used only for the page hook)
		add_menu_page(
			__( 'Widget Finder', 'widget-finder' ),
			__( 'Widget Finder', 'widget-finder' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_plugin_manager' ],
			'dashicons-search',
			58
		);

		// Plugin Manager sub-page (same as parent)
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Plugin Manager — Widget Finder', 'widget-finder' ),
			__( 'Plugin Manager', 'widget-finder' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_plugin_manager' ]
		);

		// Settings sub-page
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings — Widget Finder', 'widget-finder' ),
			__( 'Settings', 'widget-finder' ),
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
			'wfe-admin',
			WFE_URL . 'assets/css/wfe-admin.css',
			[],
			WFE_VERSION
		);

		wp_enqueue_script(
			'wfe-admin',
			WFE_URL . 'assets/js/wfe-admin.js',
			[],
			WFE_VERSION,
			true
		);

		wp_localize_script( 'wfe-admin', 'wfeAdmin', [
			'restUrl'      => rest_url( 'wfe/v1/' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'i18n'         => [
				'confirmDeactivate' => __( 'Deactivate this plugin? Elementor widgets from it will stop working on the frontend.', 'widget-finder' ),
				'confirmUninstall'  => __( 'Uninstall this plugin? This will delete all plugin files. This cannot be undone.', 'widget-finder' ),
				'confirmRemove'     => __( 'Remove this plugin from Widget Finder\'s list? The plugin will remain installed and active.', 'widget-finder' ),
				'confirmBulk'       => __( 'Apply this action to %d plugin(s)?', 'widget-finder' ),
				'deactivating'      => __( 'Deactivating…', 'widget-finder' ),
				'uninstalling'      => __( 'Uninstalling…', 'widget-finder' ),
				'removing'          => __( 'Removing…', 'widget-finder' ),
				'done'              => __( 'Done', 'widget-finder' ),
				'error'             => __( 'Error: %s', 'widget-finder' ),
				'uninstallCriticalError' => __( 'The plugin\'s uninstall script crashed. Delete it from WP Admin › Plugins, then use "Remove from list" here to clean up.', 'widget-finder' ),
			],
		] );
	}

	/**
	 * Enqueue the notification manager on every admin page.
	 * Skips the Elementor editor (which has its own panel UI).
	 */
	public static function enqueue_notice_manager(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && in_array( $_GET['action'], [ 'elementor', 'elementor-preview' ], true ) ) {
			return;
		}

		wp_enqueue_style(
			'wfe-notices',
			WFE_URL . 'assets/css/wfe-notices.css',
			[],
			WFE_VERSION
		);

		wp_enqueue_script(
			'wfe-notices',
			WFE_URL . 'assets/js/wfe-notices.js',
			[],
			WFE_VERSION,
			true  // footer
		);

		wp_localize_script( 'wfe-notices', 'wfeNotices', [
			'i18n' => [
				'title' => __( 'Notifications', 'widget-finder' ),
				'close' => __( 'Close', 'widget-finder' ),
			],
		] );
	}

	// ── Settings save handler ─────────────────────────────────────────────────

	public static function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'widget-finder' ) );
		}

		check_admin_referer( 'wfe_save_settings' );

		$data = [
			'widgets_per_page'        => absint( $_POST['widgets_per_page'] ?? 20 ),
			'pagination_type'         => sanitize_key( $_POST['pagination_type'] ?? 'show_more' ),
			'default_sort'            => sanitize_key( $_POST['default_sort'] ?? 'relevance' ),
			'show_plugin_info_button' => isset( $_POST['show_plugin_info_button'] ),
			'max_active_unused'       => max( 0, (int) ( $_POST['max_active_unused'] ?? 5 ) ),
			'max_inactive_unused'     => max( 0, (int) ( $_POST['max_inactive_unused'] ?? 5 ) ),
		];

		WFE_Settings::update( $data );

		wp_safe_redirect( add_query_arg( [ 'page' => self::SETTINGS_SLUG, 'updated' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Plugin Manager page ───────────────────────────────────────────────────

	public static function render_plugin_manager(): void {
		$tracked = WFE_Plugin_Tracker::get_all();

		// Clear usage cache on every page load so counts are always fresh.
		foreach ( $tracked as $plugin ) {
			WFE_Plugin_Tracker::clear_usage_cache( $plugin->plugin_slug );
		}

		// Enrich with live status
		$slugs    = array_column( $tracked, 'plugin_slug' );
		$statuses = $slugs ? WFE_Plugin_Manager::get_statuses( $slugs ) : [];

		?>
		<div class="wrap wfe-admin-wrap">
			<h1 class="wp-heading-inline">
				<span class="wfe-logo-icon dashicons dashicons-search"></span>
				<?php esc_html_e( 'Widget Finder — Plugin Manager', 'widget-finder' ); ?>
			</h1>
			<hr class="wp-header-end">

			<?php if ( empty( $tracked ) ) : ?>
				<div class="wfe-empty-state">
					<span class="dashicons dashicons-info-outline"></span>
					<p><?php esc_html_e( 'No plugins have been installed via Widget Finder yet.', 'widget-finder' ); ?></p>
					<p class="description"><?php esc_html_e( 'When you install a plugin from the Elementor editor search, it will appear here.', 'widget-finder' ); ?></p>
				</div>
			<?php else : ?>

			<form method="post" id="wfe-pm-form">
				<?php wp_nonce_field( 'wfe_bulk_action', 'wfe_bulk_nonce' ); ?>

				<div class="wfe-table-actions">
					<label for="wfe-bulk-select" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'widget-finder' ); ?></label>
					<select id="wfe-bulk-select" name="bulk_action">
						<option value=""><?php esc_html_e( 'Bulk Actions', 'widget-finder' ); ?></option>
						<option value="deactivate"><?php esc_html_e( 'Deactivate', 'widget-finder' ); ?></option>
						<option value="remove"><?php esc_html_e( 'Remove from list', 'widget-finder' ); ?></option>
						<option value="uninstall"><?php esc_html_e( 'Uninstall', 'widget-finder' ); ?></option>
					</select>
					<button type="button" id="wfe-bulk-apply" class="button"><?php esc_html_e( 'Apply', 'widget-finder' ); ?></button>
				</div>

				<table class="wfe-plugins-table wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="wfe-select-all" /></td>
							<th class="column-name"><?php esc_html_e( 'Plugin', 'widget-finder' ); ?></th>
							<th class="column-status"><?php esc_html_e( 'Status', 'widget-finder' ); ?></th>
							<th class="column-installed"><?php esc_html_e( 'Installed', 'widget-finder' ); ?></th>
							<th class="column-usage"><?php esc_html_e( 'Pages Using', 'widget-finder' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'widget-finder' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $tracked as $plugin ) :
						$slug        = $plugin->plugin_slug;
						$status_info = $statuses[ $slug ] ?? [ 'status' => 'not_installed', 'plugin_file' => null ];
						$status      = $status_info['status'];
						$plugin_file = $status_info['plugin_file'] ?? $plugin->plugin_file;
						$installed   = mysql2date( get_option( 'date_format' ), $plugin->installed_at );
						$usage       = WFE_Plugin_Tracker::get_usage_count( $slug );
					?>
						<tr class="wfe-plugin-row" data-slug="<?php echo esc_attr( $slug ); ?>" data-plugin-file="<?php echo esc_attr( $plugin_file ?? '' ); ?>">
							<td class="check-column">
								<input type="checkbox" name="plugins[]" value="<?php echo esc_attr( $slug ); ?>" />
							</td>
							<td class="column-name">
								<strong><?php echo esc_html( $plugin->plugin_name ?: $slug ); ?></strong>
								<div class="wfe-slug"><?php echo esc_html( $slug ); ?></div>
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
									><?php esc_html_e( 'Deactivate', 'widget-finder' ); ?></button>
								<?php endif; ?>
								<button type="button"
									class="button button-small button-link-delete wfe-btn-uninstall"
									data-slug="<?php echo esc_attr( $slug ); ?>"
									data-plugin-file="<?php echo esc_attr( $plugin_file ?? '' ); ?>"
								><?php esc_html_e( 'Uninstall', 'widget-finder' ); ?></button>

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
		$s = WFE_Settings::get();
		?>
		<div class="wrap wfe-admin-wrap">
			<h1>
				<span class="wfe-logo-icon dashicons dashicons-search"></span>
				<?php esc_html_e( 'Widget Finder — Settings', 'widget-finder' ); ?>
			</h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'widget-finder' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wfe_save_settings" />
				<?php wp_nonce_field( 'wfe_save_settings' ); ?>

				<table class="form-table wfe-settings-table">
					<tbody>

						<tr>
							<th scope="row">
								<label for="widgets_per_page"><?php esc_html_e( 'Widgets per page', 'widget-finder' ); ?></label>
							</th>
							<td>
								<input type="number" id="widgets_per_page" name="widgets_per_page"
									value="<?php echo esc_attr( $s['widgets_per_page'] ); ?>"
									min="5" max="50" step="5" class="small-text" />
								<p class="description"><?php esc_html_e( 'How many results to show in the Elementor panel before paginating (5–50).', 'widget-finder' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Pagination style', 'widget-finder' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="pagination_type" value="show_more"
											<?php checked( $s['pagination_type'], 'show_more' ); ?> />
										<?php esc_html_e( '"Show more" button', 'widget-finder' ); ?>
									</label><br>
									<label>
										<input type="radio" name="pagination_type" value="paginate"
											<?php checked( $s['pagination_type'], 'paginate' ); ?> />
										<?php esc_html_e( 'Numbered pages', 'widget-finder' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="default_sort"><?php esc_html_e( 'Default sort order', 'widget-finder' ); ?></label>
							</th>
							<td>
								<select id="default_sort" name="default_sort">
									<option value="relevance" <?php selected( $s['default_sort'], 'relevance' ); ?>><?php esc_html_e( 'Relevance', 'widget-finder' ); ?></option>
									<option value="installs"  <?php selected( $s['default_sort'], 'installs' ); ?>><?php esc_html_e( 'Active Installs', 'widget-finder' ); ?></option>
									<option value="rating"    <?php selected( $s['default_sort'], 'rating' ); ?>><?php esc_html_e( 'Rating', 'widget-finder' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Widget cards', 'widget-finder' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="show_plugin_info_button" value="1"
										<?php checked( $s['show_plugin_info_button'] ); ?> />
									<?php esc_html_e( 'Show plugin info (description, ratings) in widget cards', 'widget-finder' ); ?>
								</label>
							</td>
						</tr>

					<tr>
						<th scope="row">
							<label for="wfe_max_active_unused"><?php esc_html_e( 'Keep max unused active plugins', 'widget-finder' ); ?></label>
						</th>
						<td>
							<input type="number" id="wfe_max_active_unused" name="max_active_unused"
								value="<?php echo esc_attr( $s['max_active_unused'] ); ?>"
								min="0" max="50" step="1" class="small-text" />
							<p class="description">
								<?php esc_html_e( 'Maximum WFE-installed plugins with 0 widget usage that may remain active. Oldest are deactivated when a new plugin is activated. Set to 0 to disable.', 'widget-finder' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wfe_max_inactive_unused"><?php esc_html_e( 'Keep max inactive unused plugins', 'widget-finder' ); ?></label>
						</th>
						<td>
							<input type="number" id="wfe_max_inactive_unused" name="max_inactive_unused"
								value="<?php echo esc_attr( $s['max_inactive_unused'] ); ?>"
								min="0" max="50" step="1" class="small-text" />
							<p class="description">
								<?php esc_html_e( 'Maximum WFE-installed plugins with 0 widget usage that may remain installed (inactive). Oldest are uninstalled when a new plugin is installed. Set to 0 to disable.', 'widget-finder' ); ?>
							</p>
						</td>
					</tr>

				</tbody>
				</table>

				<?php submit_button( __( 'Save Settings', 'widget-finder' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function render_status_badge( string $status ): void {
		$labels = [
			'active'        => [ __( 'Active', 'widget-finder' ),        'wfe-badge-active' ],
			'inactive'      => [ __( 'Inactive', 'widget-finder' ),       'wfe-badge-inactive' ],
			'not_installed' => [ __( 'Not installed', 'widget-finder' ),  'wfe-badge-missing' ],
		];

		[ $label, $class ] = $labels[ $status ] ?? [ ucfirst( $status ), 'wfe-badge-inactive' ];
		echo '<span class="wfe-badge ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}
}
