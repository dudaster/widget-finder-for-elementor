<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Live discovery from the WordPress.org Plugins API.
 *
 * Results supplement the bundled dataset so the plugin is not limited
 * to a static snapshot — any Elementor-compatible plugin on WP.org can
 * be surfaced even if it was released after the last dataset update.
 */
class WFX_WPOrg_API {

	const TRANSIENT_PREFIX = 'wfx_wporg_';
	const CACHE_DURATION   = HOUR_IN_SECONDS;
	const API_URL          = 'https://api.wordpress.org/plugins/info/1.2/';

	/**
	 * Query the WordPress.org Plugins API for plugins related to $query.
	 * Results are cached for 1 hour per unique query string.
	 *
	 * @param  string $query Raw search term.
	 * @return array<array{plugin_slug:string,plugin_name:string,active_installs:int|null,rating_average:float|null,source:string}>
	 */
	public static function search( string $query ): array {
		$cache_key = self::TRANSIENT_PREFIX . md5( $query );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = add_query_arg(
			[
				'action'                             => 'query_plugins',
				'request[search]'                    => $query . ' elementor',
				'request[tag]'                       => 'elementor',
				'request[per_page]'                  => 5,
				'request[fields][sections]'          => 0,
				'request[fields][description]'       => 0,
				'request[fields][short_description]' => 1,
				'request[fields][icons]'             => 0,
				'request[fields][banners]'           => 0,
			],
			self::API_URL
		);

		$response = wp_remote_get( $url, [ 'timeout' => 5 ] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['plugins'] ) || ! is_array( $body['plugins'] ) ) {
			return [];
		}

		$results = [];
		foreach ( $body['plugins'] as $plugin ) {
			$slug = $plugin['slug'] ?? '';
			if ( ! $slug ) {
				continue;
			}

			$results[] = [
				'plugin_slug'     => sanitize_text_field( $slug ),
				'plugin_name'     => sanitize_text_field( $plugin['name'] ?? $slug ),
				'active_installs' => isset( $plugin['active_installs'] ) ? (int) $plugin['active_installs'] : null,
				'rating_average'  => isset( $plugin['rating'] ) ? round( (float) $plugin['rating'] / 20, 1 ) : null,
				'source'          => 'live',
			];
		}

		set_transient( $cache_key, $results, self::CACHE_DURATION );
		return $results;
	}
}
