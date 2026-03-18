<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Search interface for the widget dataset stored in {prefix}wfe_widgets.
 *
 * Data is imported from data/widgets.json at activation time by WFE_Importer.
 * This class provides only the read path used by the REST API.
 *
 * ── Search strategy ──────────────────────────────────────────────────────────
 *   WHERE  : single LIKE on the pre-combined search_text column
 *            (avoids five separate OR conditions at match time)
 *   CASE   : individual column checks for relevance tier assignment:
 *              4 → widget_title_norm match  (curated alias/synonym hit)
 *              3 → widget_title match       (title hit)
 *              2 → widget_type match        (Elementor slug hit)
 *              1 → plugin_name match        (plugin-level hit)
 *              0 → body match only          (search_text catch-all)
 */
class WFE_Database {

	/**
	 * Search widgets by keyword.
	 *
	 * @param string $query  Raw search term.
	 * @param int    $limit  Max rows to return.
	 * @param int    $offset Pagination offset.
	 * @return array<object>
	 */
	public static function search( string $query, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$query = trim( $query );
		if ( $query === '' ) {
			return [];
		}

		$table = $wpdb->prefix . WFE_Importer::TABLE_WIDGETS;
		$like  = '%' . $wpdb->esc_like( $query ) . '%';

		$sql = $wpdb->prepare(
			"SELECT
			    id               AS widget_raw_id,
			    plugin_slug,
			    plugin_name,
			    widget_title,
			    widget_type,
			    icon_type,
			    icon_value,
			    NULL            AS description_short,
			    active_installs,
			    rating_average,
			    CASE
			        WHEN widget_title_norm LIKE %s THEN 4
			        WHEN widget_title      LIKE %s THEN 3
			        WHEN widget_type       LIKE %s THEN 2
			        WHEN plugin_name       LIKE %s THEN 1
			        ELSE 0
			    END AS relevance
			FROM {$table}
			WHERE search_text LIKE %s
			ORDER BY relevance DESC, ranking_score DESC, active_installs DESC, id ASC
			LIMIT %d OFFSET %d",
			$like, $like, $like, $like,
			$like,
			$limit, $offset
		);

		return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
