<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Imports the bundled widget dataset (data/widgets.json) into two custom
 * WordPress database tables on activation or when DATA_VERSION changes.
 *
 * Tables created/owned by this class:
 *   {prefix}wfe_widgets    — widget search data (plugin info + widget tuples)
 *   {prefix}wfe_plugin_map — canonical plugin → Elementor widget-key mapping
 *
 * ── JSON format v1 ───────────────────────────────────────────────────────────
 *
 *   {
 *     "v":  1,
 *     "it": ["elementor_icon_class", "custom_icon_class", ...],  // icon-type lookup
 *     "p":  {
 *       "plugin-slug": [
 *         "Plugin Name",   // 0  plugin_name
 *         50000,           // 1  active_installs
 *         48,              // 2  rating_average × 10  (÷10 at import)
 *         58,              // 3  ranking_score (rounded integer)
 *         [                // 4  widgets
 *           ["type", "Title", "icon-val", "norm/alias list"],
 *           ["type", "Title", [1,"cls"],  "norm"],   ← non-default icon type
 *         ],
 *         ["map-key1", ...]  // 5  plugin_widgets (Elementor registration keys)
 *       ]
 *     }
 *   }
 *
 * Widget tuple positions:
 *   [0] widget_type  (Elementor widget registration slug)
 *   [1] widget_title
 *   [2] icon: string → icon_value with default icon_type (it[0])
 *              array  → [icon_type_index, icon_value]
 *   [3] widget_title_norm (curated aliases / synonyms used for searching)
 *
 * ── Compression applied ───────────────────────────────────────────────────────
 *   • Plugin metadata stored once per plugin (not once per widget).
 *   • Array tuples eliminate repeated key strings per widget row.
 *   • Icon-type lookup: most-common type stored implicitly (no index stored).
 *   • rating_average stored ×10 as integer (no decimal point in JSON).
 *   • description_short omitted (always NULL in current dataset).
 *   • Raw search_text omitted; combined search_text column reconstructed at
 *     import from: plugin_slug + plugin_name + widget_type + widget_title
 *     + widget_title_norm  (covers all searchable fields without redundancy).
 *
 * ── Updating the dataset ──────────────────────────────────────────────────────
 *   1. Rebuild data/widgets.json with tools/build-widgets-json.py.
 *   2. Bump DATA_VERSION below.
 *   The next page load will truncate + re-import automatically.
 */
class WFE_Importer {

	/** Bump this constant whenever data/widgets.json is updated. */
	const DATA_VERSION = '1.0';

	const TABLE_WIDGETS = 'wfe_widgets';
	const TABLE_MAP     = 'wfe_plugin_map';

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Create the custom tables (idempotent via dbDelta).
	 * Call from the activation hook so tables exist before import runs.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$sql_widgets = "CREATE TABLE {$wpdb->prefix}" . self::TABLE_WIDGETS . " (
			id                mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
			plugin_slug       varchar(191)          NOT NULL DEFAULT '',
			plugin_name       varchar(255)          NOT NULL DEFAULT '',
			widget_type       varchar(191)          NOT NULL DEFAULT '',
			widget_title      varchar(255)          NOT NULL DEFAULT '',
			widget_title_norm varchar(255)          NOT NULL DEFAULT '',
			icon_type         varchar(40)           NOT NULL DEFAULT '',
			icon_value        varchar(150)          NOT NULL DEFAULT '',
			active_installs   int(10) unsigned      NOT NULL DEFAULT 0,
			rating_average    decimal(3,1)          NOT NULL DEFAULT '0.0',
			ranking_score     smallint(5) unsigned  NOT NULL DEFAULT 0,
			search_text       text                  NOT NULL,
			PRIMARY KEY (id),
			KEY idx_plugin    (plugin_slug(50)),
			KEY idx_norm      (widget_title_norm(50))
		) {$charset};";

		$sql_map = "CREATE TABLE {$wpdb->prefix}" . self::TABLE_MAP . " (
			plugin_slug varchar(191) NOT NULL DEFAULT '',
			widget_key  varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY (plugin_slug, widget_key)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_widgets );
		dbDelta( $sql_map );
	}

	/**
	 * Import data/widgets.json if the stored DATA_VERSION doesn't match.
	 *
	 * Safe to call on every plugins_loaded — exits immediately when up-to-date.
	 * On version mismatch, truncates both tables and re-imports from scratch.
	 */
	public static function maybe_import(): void {
		if ( get_option( 'wfe_data_version' ) === self::DATA_VERSION ) {
			return;
		}

		self::create_tables();
		self::run_import();
		update_option( 'wfe_data_version', self::DATA_VERSION, false );
	}

	// ── Import ─────────────────────────────────────────────────────────────────

	private static function run_import(): void {
		$file = WFE_PATH . 'data/widgets.json';
		if ( ! file_exists( $file ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw  = file_get_contents( $file );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ( $data['v'] ?? 0 ) !== 1 ) {
			return; // unrecognised format version
		}

		global $wpdb;

		$w_table = $wpdb->prefix . self::TABLE_WIDGETS;
		$m_table = $wpdb->prefix . self::TABLE_MAP;

		// Truncate before re-import; these are read-only reference tables.
		$wpdb->query( "TRUNCATE TABLE {$w_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE {$m_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$icon_types = $data['it'] ?? [ 'elementor_icon_class' ];
		$default_it = $icon_types[0] ?? '';
		$plugins    = $data['p']  ?? [];

		$widget_rows = [];
		$map_rows    = [];

		foreach ( $plugins as $slug => $plugin_data ) {
			[ $pname, $installs, $rating10, $score, $widgets, $map_keys ] = $plugin_data;

			$rating = round( (int) $rating10 / 10, 1 );

			foreach ( $widgets as $w ) {
				$type  = $w[0] ?? '';
				$title = $w[1] ?? '';
				$icon  = $w[2] ?? '';
				$norm  = $w[3] ?? '';

				if ( is_array( $icon ) ) {
					$itype  = $icon_types[ $icon[0] ] ?? $default_it;
					$ivalue = $icon[1] ?? '';
				} else {
					$itype  = $default_it;
					$ivalue = $icon;
				}

				// Pre-build combined search_text so queries need one LIKE instead of five.
				$search_text = implode( ' ', array_filter( array_unique( [
					$slug, $pname, $type, $title, $norm,
				] ) ) );

				$widget_rows[] = [
					'plugin_slug'       => $slug,
					'plugin_name'       => $pname,
					'widget_type'       => $type,
					'widget_title'      => $title,
					'widget_title_norm' => $norm,
					'icon_type'         => $itype,
					'icon_value'        => $ivalue,
					'active_installs'   => (int) $installs,
					'rating_average'    => $rating,
					'ranking_score'     => (int) $score,
					'search_text'       => $search_text,
				];
			}

			foreach ( (array) $map_keys as $key ) {
				$map_rows[] = [ 'plugin_slug' => $slug, 'widget_key' => $key ];
			}
		}

		// Formats aligned to the column order above.
		self::batch_insert( $w_table, $widget_rows,
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%s' ],
			100
		);

		self::batch_insert( $m_table, $map_rows, [ '%s', '%s' ], 200 );
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Multi-row batch INSERT — far faster than individual $wpdb->insert() calls
	 * for large datasets.
	 *
	 * @param string   $table      Full table name including prefix.
	 * @param array    $rows       Rows as associative arrays (all with same keys).
	 * @param string[] $formats    printf-style formats matching the column order.
	 * @param int      $chunk_size Rows per INSERT statement.
	 */
	private static function batch_insert( string $table, array $rows, array $formats, int $chunk_size ): void {
		global $wpdb;

		if ( empty( $rows ) ) {
			return;
		}

		$cols     = array_keys( $rows[0] );
		$col_list = '`' . implode( '`,`', $cols ) . '`';
		$row_ph   = '(' . implode( ',', $formats ) . ')';

		foreach ( array_chunk( $rows, $chunk_size ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), $row_ph ) );
			$values       = [];

			foreach ( $chunk as $row ) {
				foreach ( $cols as $col ) {
					$values[] = $row[ $col ];
				}
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table} ({$col_list}) VALUES {$placeholders}",
				$values
			) );
		}
	}
}
