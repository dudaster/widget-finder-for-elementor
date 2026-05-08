<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hooks into Elementor editor to inject Widget Finder panel assets.
 */
class WFX_Elementor_Integration {

	public static function init(): void {
		add_action( 'elementor/editor/before_enqueue_scripts', [ __CLASS__, 'enqueue_editor_assets' ] );
	}

	public static function enqueue_editor_assets(): void {
		wp_enqueue_style(
			'wfx-panel',
			WFX_URL . 'assets/css/wfe-panel.css',
			[],
			WFX_VERSION
		);

		wp_enqueue_script(
			'wfx-panel',
			WFX_URL . 'assets/js/wfe-panel.js',
			[ 'jquery', 'elementor-editor' ],
			WFX_VERSION,
			true  // in footer
		);

		$settings = WFX_Settings::get();

		wp_localize_script( 'wfx-panel', 'wfxData', [
			'restUrl'      => rest_url( 'wfx/v1/search' ),
			'installUrl'   => rest_url( 'wfx/v1/install' ),
			'activateUrl'  => rest_url( 'wfx/v1/activate' ),
			'deactivateUrl' => rest_url( 'wfx/v1/deactivate' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'settings'    => [
				'widgetsPerPage'       => (int) $settings['widgets_per_page'],
				'showPluginInfoButton' => (bool) $settings['show_plugin_info_button'],
			],
			'i18n'        => [
				// Category / search states
				'title'                => __( 'Widget Finder', 'widget-finder-for-elementor' ),
				'searching'            => __( 'Searching…', 'widget-finder-for-elementor' ),
				'no_results'           => __( 'No widgets found.', 'widget-finder-for-elementor' ),

				// Status dot labels (used in title/aria-label)
				'status_active'        => __( 'Active', 'widget-finder-for-elementor' ),
				'status_inactive'      => __( 'Inactive – click to activate', 'widget-finder-for-elementor' ),
				'status_missing'       => __( 'Not installed – click to install', 'widget-finder-for-elementor' ),

				// Buttons
				'btn_activate'         => __( 'Activate Plugin', 'widget-finder-for-elementor' ),
				'btn_activating'       => __( 'Activating…', 'widget-finder-for-elementor' ),
				'btn_install'          => __( 'Install Plugin', 'widget-finder-for-elementor' ),
				'btn_installing'       => __( 'Installing…', 'widget-finder-for-elementor' ),
				'btn_cancel'           => __( 'Cancel', 'widget-finder-for-elementor' ),

				// Modal — activate
				'modal_activate_title' => __( 'Activate Plugin', 'widget-finder-for-elementor' ),
				/* translators: %s is the plugin name. */
				'modal_activate_body'  => __( 'The plugin %s is installed but not active. Activate it to use this widget.', 'widget-finder-for-elementor' ),

				// Modal — install
				'modal_install_title'  => __( 'Install Plugin', 'widget-finder-for-elementor' ),
				/* translators: %s is the plugin name. */
				'modal_install_body'   => __( 'This widget requires %s which is not installed. It will be downloaded and installed directly from WordPress.org using the standard WordPress installer.', 'widget-finder-for-elementor' ),

				// Progress messages
				/* translators: %s is the plugin name. */
				'installing_progress'  => __( 'Installing %s…', 'widget-finder-for-elementor' ),
				'activating_progress'  => __( 'Activating plugin…', 'widget-finder-for-elementor' ),

				// Error messages
				'err_install'          => __( 'Installation failed. Please try again from the Plugins page.', 'widget-finder-for-elementor' ),
				'err_activate'         => __( 'Activation failed. Please try again from the Plugins page.', 'widget-finder-for-elementor' ),

				// Success notices
				'success_title'        => __( 'Widget Finder', 'widget-finder-for-elementor' ),
				/* translators: %s is the plugin name. */
				'activate_success'     => __( '%s activated successfully!', 'widget-finder-for-elementor' ),

				// Active widget notice
				'available_notice'     => __( 'is available — find it in the widgets list above.', 'widget-finder-for-elementor' ),

				// Pagination
				'show_more'            => __( 'Show more widgets', 'widget-finder-for-elementor' ),

				// Plugin conflict detection
				'conflict_title'          => __( 'Plugin Conflict Detected', 'widget-finder-for-elementor' ),
				/* translators: %s is the plugin name. */
				'conflict_body'           => __( '%s was automatically deactivated because it caused errors in the Elementor editor. Try searching for a different plugin with similar widgets.', 'widget-finder-for-elementor' ),
				'conflict_search_another' => __( 'Search for another plugin', 'widget-finder-for-elementor' ),
			],
		] );
	}
}
