<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Manages the plugin's settings stored in wp_options.
 */
class WFE_Settings {

	const OPTION_KEY = 'widget_finder_settings';

	const DEFAULTS = [
		'widgets_per_page'         => 20,
		'pagination_type'          => 'show_more', // 'show_more' | 'paginate'
		'default_sort'             => 'relevance', // 'relevance' | 'installs' | 'rating'
		'show_plugin_info_button'  => true,
		// Max number of WFE-installed plugins (with 0 widget usage) allowed to be
		// ACTIVE at once.  Oldest are deactivated when a new plugin is activated.
		// 0 = disabled.
		'max_active_unused'        => 5,
		// Max number of WFE-installed plugins (with 0 widget usage) allowed to be
		// INACTIVE (still installed) at once.  Oldest are uninstalled when a new
		// plugin is installed.  0 = disabled.
		'max_inactive_unused'      => 5,
	];

	/**
	 * Get all settings, merged with defaults.
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		return wp_parse_args( is_array( $saved ) ? $saved : [], self::DEFAULTS );
	}

	/**
	 * Update one or more settings. Only known keys are accepted.
	 */
	public static function update( array $data ): bool {
		$current   = self::get();
		$sanitized = [];

		foreach ( self::DEFAULTS as $key => $default ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$sanitized[ $key ] = $current[ $key ];
				continue;
			}

			$value = $data[ $key ];

			if ( is_bool( $default ) ) {
				$sanitized[ $key ] = (bool) $value;
			} elseif ( is_int( $default ) ) {
				// Allow 0 (= disabled) for limit settings; enforce min=1 for others.
				$int_val = (int) $value;
				$sanitized[ $key ] = ( $int_val < 0 ) ? 0 : $int_val;
			} else {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return update_option( self::OPTION_KEY, $sanitized );
	}
}
