<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hooks into Elementor editor to inject Widget Finder panel assets.
 */
class WFE_Elementor_Integration {

	public static function init(): void {
		add_action( 'elementor/editor/before_enqueue_scripts', [ __CLASS__, 'enqueue_editor_assets' ] );
	}

	public static function enqueue_editor_assets(): void {
		wp_enqueue_style(
			'wfe-panel',
			WFE_URL . 'assets/css/wfe-panel.css',
			[],
			WFE_VERSION
		);

		wp_enqueue_script(
			'wfe-panel',
			WFE_URL . 'assets/js/wfe-panel.js',
			[ 'jquery', 'elementor-editor' ],
			WFE_VERSION,
			true  // in footer
		);

		$settings = WFE_Settings::get();

		wp_localize_script( 'wfe-panel', 'wfeData', [
			'restUrl'      => rest_url( 'wfe/v1/search' ),
			'installUrl'   => rest_url( 'wfe/v1/install' ),
			'activateUrl'  => rest_url( 'wfe/v1/activate' ),
			'deactivateUrl' => rest_url( 'wfe/v1/deactivate' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'settings'    => [
				'widgetsPerPage'       => (int) $settings['widgets_per_page'],
				'showPluginInfoButton' => (bool) $settings['show_plugin_info_button'],
			],
			'i18n'        => [
				// Category / search states
				'title'                => __( 'Widget Finder', 'widget-finder' ),
				'searching'            => __( 'Searching…', 'widget-finder' ),
				'no_results'           => __( 'No widgets found.', 'widget-finder' ),

				// Status dot labels (used in title/aria-label)
				'status_active'        => __( 'Active', 'widget-finder' ),
				'status_inactive'      => __( 'Inactive – click to activate', 'widget-finder' ),
				'status_missing'       => __( 'Not installed – click to install', 'widget-finder' ),

				// Buttons
				'btn_activate'         => __( 'Activate Plugin', 'widget-finder' ),
				'btn_activating'       => __( 'Activating…', 'widget-finder' ),
				'btn_install'          => __( 'Install Plugin', 'widget-finder' ),
				'btn_installing'       => __( 'Installing…', 'widget-finder' ),
				'btn_cancel'           => __( 'Cancel', 'widget-finder' ),

				// Modal — activate
				'modal_activate_title' => __( 'Activate Plugin', 'widget-finder' ),
				'modal_activate_body'  => __( 'The plugin %s is installed but not active. Activate it to use this widget.', 'widget-finder' ),

				// Modal — install
				'modal_install_title'  => __( 'Install Plugin', 'widget-finder' ),
				'modal_install_body'   => __( 'This widget requires %s which is not installed. Install it now from WordPress.org?', 'widget-finder' ),

				// Progress messages
				'installing_progress'  => __( 'Installing %s…', 'widget-finder' ),
				'activating_progress'  => __( 'Activating plugin…', 'widget-finder' ),

				// Error messages
				'err_install'          => __( 'Installation failed. Please try again from the Plugins page.', 'widget-finder' ),
				'err_activate'         => __( 'Activation failed. Please try again from the Plugins page.', 'widget-finder' ),

				// Success notices
				'success_title'        => __( 'Widget Finder', 'widget-finder' ),
				'activate_success'     => __( '%s activated successfully!', 'widget-finder' ),

				// Active widget notice
				'available_notice'     => __( 'is available — find it in the widgets list above.', 'widget-finder' ),

				// Pagination
				'show_more'            => __( 'Show more widgets', 'widget-finder' ),

				// Plugin conflict detection
				'conflict_title'          => __( 'Plugin Conflict Detected', 'widget-finder' ),
				'conflict_body'           => __( '%s was automatically deactivated because it caused errors in the Elementor editor. Try searching for a different plugin with similar widgets.', 'widget-finder' ),
				'conflict_search_another' => __( 'Search for another plugin', 'widget-finder' ),
			],
		] );
	}
}
