<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Core singleton — boots all subsystems.
 */
class WFX_Plugin {

	private static ?WFX_Plugin $instance = null;

	public static function instance(): WFX_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_components();
	}

	private function init_components(): void {
		// REST API — runs early via rest_api_init
		WFX_Rest_API::instance();

		// Block setup-wizard redirects while the user is in the Elementor editor.
		// Plugins set a transient at activation time and redirect from admin_init.
		// We suppress wp_redirect() at priority 1 so our filter runs before theirs.
		add_action( 'admin_init', static function () {
			$action  = sanitize_key( (string) filter_input( INPUT_GET, 'action' ) );
			$page    = sanitize_key( (string) filter_input( INPUT_GET, 'page' ) );
			$in_editor = ( $action === 'elementor' )
				|| ( $action === 'elementor-preview' )
				|| ( $page   === 'elementor' );

			if ( $in_editor ) {
				add_filter( 'wp_redirect', static fn() => '', PHP_INT_MAX );
			}
		}, 1 );

		// Elementor panel integration
		if ( did_action( 'elementor/loaded' ) ) {
			// elementor/loaded already fired (Elementor loads at plugins_loaded priority 0,
			// we load at priority 10) — call init directly
			WFX_Elementor_Integration::init();
		} else {
			// Elementor not yet loaded — hook for when it fires
			add_action( 'elementor/loaded', [ WFX_Elementor_Integration::class, 'init' ] );
		}

		// Daily cron for auto-delete
		add_action( 'wfx_daily_cleanup', [ $this, 'run_auto_delete' ] );
		if ( ! wp_next_scheduled( 'wfx_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'wfx_daily_cleanup' );
		}
	}

	/**
	 * Daily cron: enforce active/inactive unused-plugin limits as a background
	 * safety net (e.g. after a plugin starts being used, freeing up a slot).
	 */
	public function run_auto_delete(): void {
		$s = WFX_Settings::get();

		if ( $s['max_active_unused'] > 0 ) {
			WFX_Plugin_Tracker::enforce_active_limit( $s['max_active_unused'] );
		}

		if ( $s['max_inactive_unused'] > 0 ) {
			WFX_Plugin_Tracker::enforce_inactive_limit( $s['max_inactive_unused'] );
		}
	}
}
