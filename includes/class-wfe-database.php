<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Read-only access to the bundled SQLite widget database.
 *
 * The database lives at data/widgets.sqlite inside the plugin directory.
 * It contains two tables:
 *   widgets        — one row per widget (search data, icons, metadata)
 *   plugin_widgets — plugin_slug → internal_widget_key mapping
 */
class WFE_Database {

	private static ?PDO $db = null;

	/**
	 * Returns (and lazily opens) the SQLite PDO connection.
	 * Returns null if PDO SQLite is unavailable or the file is missing.
	 */
	public static function connection(): ?PDO {
		if ( null !== self::$db ) {
			return self::$db;
		}

		if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
			return null;
		}

		$file = WFE_PATH . 'data/widgets.sqlite';
		if ( ! file_exists( $file ) ) {
			return null;
		}

		try {
			self::$db = new PDO( 'sqlite:' . $file );
			self::$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			self::$db->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ );
		} catch ( \Throwable $e ) {
			self::$db = null;
		}

		return self::$db;
	}

	/**
	 * Search widgets by keyword.
	 *
	 * Matches against widget_title, widget_title_norm, widget_type,
	 * plugin_name, and search_text.  Results are ordered by match quality
	 * (title match > type/name match > body match), then by ranking_score
	 * and active_installs.
	 *
	 * @param string $query  Raw search term.
	 * @param int    $limit  Max rows to return.
	 * @param int    $offset Pagination offset.
	 * @return array<object>
	 */
	public static function search( string $query, int $limit = 20, int $offset = 0 ): array {
		$db = self::connection();
		if ( ! $db ) {
			return [];
		}

		$query = trim( $query );
		if ( $query === '' ) {
			return [];
		}

		$like = '%' . self::esc_like( $query ) . '%';

		$sql = "
			SELECT
				widget_raw_id, plugin_slug, plugin_name,
				widget_title, widget_type,
				icon_type, icon_value,
				description_short,
				active_installs, rating_average,
				CASE
					WHEN widget_title_norm LIKE :like THEN 4
					WHEN widget_title      LIKE :like THEN 3
					WHEN widget_type       LIKE :like THEN 2
					WHEN plugin_name       LIKE :like THEN 1
					ELSE 0
				END AS relevance
			FROM widgets
			WHERE widget_title_norm LIKE :like
			   OR widget_title      LIKE :like
			   OR widget_type       LIKE :like
			   OR plugin_name       LIKE :like
			   OR search_text       LIKE :like
			ORDER BY
				relevance       DESC,
				ranking_score   DESC,
				active_installs DESC,
				widget_raw_id   ASC
			LIMIT :limit OFFSET :offset
		";

		try {
			$stmt = $db->prepare( $sql );
			$stmt->bindValue( ':like',   $like,   PDO::PARAM_STR );
			$stmt->bindValue( ':limit',  $limit,  PDO::PARAM_INT );
			$stmt->bindValue( ':offset', $offset, PDO::PARAM_INT );
			$stmt->execute();
			return $stmt->fetchAll();
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/**
	 * Escape a string for use in a SQLite LIKE pattern.
	 */
	private static function esc_like( string $value ): string {
		return str_replace( [ '\\', '%', '_' ], [ '\\\\', '\\%', '\\_' ], $value );
	}
}
