<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Manages a dedicated wpdb connection to the widgetfinder database.
 *
 * We create a separate wpdb instance instead of switching $wpdb->select()
 * to avoid polluting the global WordPress DB connection.
 */
class WFE_Database {

	private static ?wpdb $db = null;

	/**
	 * Returns (and lazily creates) the custom wpdb instance.
	 */
	public static function connection(): wpdb {
		if ( null === self::$db ) {
			self::$db = new wpdb( WFE_DB_USER, WFE_DB_PASSWORD, WFE_DB_NAME, WFE_DB_HOST );
			self::$db->show_errors( false );
		}
		return self::$db;
	}

	/**
	 * Run a search query against runtime_widgets_search.
	 *
	 * Uses FULLTEXT BOOLEAN MODE for performance. Falls back to LIKE for
	 * short queries (< 3 chars) which FULLTEXT ignores by default.
	 *
	 * @param string $query  Raw search term from the user.
	 * @param int    $limit  Max results to return.
	 * @return array<object> Raw DB rows.
	 */
	public static function search( string $query, int $limit = 20, int $offset = 0 ): array {
		$db    = self::connection();
		$query = trim( $query );

		if ( strlen( $query ) < 3 ) {
			return self::search_like( $db, $query, $limit, $offset );
		}

		return self::search_fulltext( $db, $query, $limit, $offset );
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	private static function search_fulltext( wpdb $db, string $query, int $limit, int $offset ): array {
		$ft_query = self::build_boolean_query( $query );

		$sql = $db->prepare(
			"SELECT
				widget_raw_id, plugin_slug, plugin_name,
				widget_title, widget_type,
				icon_type, icon_value,
				description_short,
				active_installs, rating_average,
				MATCH(widget_title, widget_title_norm, search_text, plugin_name, widget_type)
					AGAINST (%s IN BOOLEAN MODE) AS relevance
			FROM runtime_widgets_search
			WHERE MATCH(widget_title, widget_title_norm, search_text, plugin_name, widget_type)
				AGAINST (%s IN BOOLEAN MODE)
			ORDER BY
				relevance        DESC,
				ranking_score    DESC,
				active_installs  DESC,
				rating_average   DESC,
				widget_raw_id    ASC
			LIMIT %d OFFSET %d",
			$ft_query,
			$ft_query,
			$limit,
			$offset
		);

		$results = $db->get_results( $sql );
		return $results ?: [];
	}

	private static function search_like( wpdb $db, string $query, int $limit, int $offset ): array {
		$like = '%' . $db->esc_like( $query ) . '%';

		$sql = $db->prepare(
			"SELECT
				widget_raw_id, plugin_slug, plugin_name,
				widget_title, widget_type,
				icon_type, icon_value,
				description_short,
				active_installs, rating_average,
				0 AS relevance
			FROM runtime_widgets_search
			WHERE widget_title LIKE %s
				OR widget_title_norm LIKE %s
				OR widget_type LIKE %s
			ORDER BY ranking_score DESC, active_installs DESC, widget_raw_id ASC
			LIMIT %d OFFSET %d",
			$like, $like, $like, $limit, $offset
		);

		$results = $db->get_results( $sql );
		return $results ?: [];
	}

	/**
	 * Builds a FULLTEXT BOOLEAN MODE query string.
	 * Each word gets a '+' prefix (must contain) and '*' suffix (prefix match).
	 */
	private static function build_boolean_query( string $query ): string {
		$words = preg_split( '/\s+/', trim( $query ), -1, PREG_SPLIT_NO_EMPTY );
		$parts = [];

		foreach ( $words as $word ) {
			// Strip special FULLTEXT boolean chars to avoid syntax errors
			$clean = preg_replace( '/[+\-><()\~*"@]/', '', $word );
			if ( strlen( $clean ) >= 2 ) {
				$parts[] = '+' . $clean . '*';
			}
		}

		// Fall back to a plain query if all words were too short
		return $parts ? implode( ' ', $parts ) : $query;
	}
}
