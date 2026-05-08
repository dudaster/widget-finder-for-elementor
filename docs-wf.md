# Widget Finder for Elementor — Developer Documentation

## Overview

Widget Finder for Elementor (WFE) extends the Elementor editor's widget panel with a universal search that surfaces widgets from installed, inactive, and not-yet-installed plugins. It bundles a curated dataset of ~6,800+ widgets, imports it into the WordPress database on activation, and exposes a REST API consumed by the Elementor panel UI.

---

## Architecture

```
widget-finder-for-elementor/
├── widget-finder-for-elementor.php   Entry point, constants, activation/deactivation hooks
├── readme.txt
├── assets/
│   ├── css/
│   │   ├── wfe-admin.css             Plugin Manager & Settings page styles
│   │   ├── wfe-notices.css           Notification Center bell icon styles
│   │   ├── wfe-panel.css             Elementor editor panel styles
│   │   └── wfe-menu-collapse.css     Sidebar menu consolidation styles
│   └── js/
│       ├── wfe-admin.js              Plugin Manager page logic (REST calls, bulk actions)
│       ├── wfe-notices.js            Notification Center (intercepts admin notices)
│       ├── wfe-panel.js              Elementor editor panel (search UI, install/activate flow)
│       └── wfe-menu-collapse.js      Sidebar menu group toggle
├── data/
│   └── widgets.json                  Bundled widget dataset (v1 format, ~251 KB compressed)
├── includes/
│   ├── class-wfe-plugin.php          Core singleton — boots subsystems, schedules cron
│   ├── class-wfe-admin.php           Admin menu, Plugin Manager page, Settings page
│   ├── class-wfe-database.php        Widget search query against the imported dataset
│   ├── class-wfe-importer.php        Imports widgets.json into DB tables on activation
│   ├── class-wfe-rest-api.php        REST API endpoints
│   ├── class-wfe-plugin-manager.php  Plugin install/activate/status detection
│   ├── class-wfe-plugin-tracker.php  Tracks WFE-installed plugins; usage detection; limit enforcement
│   ├── class-wfe-settings.php        Settings CRUD (wp_options)
│   ├── class-wfe-elementor-integration.php  Enqueues editor assets
│   └── class-wfe-menu-consolidator.php      Groups installed-plugin menus in sidebar
├── languages/
│   └── index.php
└── tools/
    └── build-widgets-json.py         Dev tool — rebuilds widgets.json from widgets.sqlite
```

---

## Constants

Defined in `widget-finder-for-elementor.php`:

| Constant | Value | Description |
|---|---|---|
| `WFE_VERSION` | `1.6.9` | Plugin version |
| `WFE_FILE` | `__FILE__` | Absolute path to main plugin file |
| `WFE_PATH` | `plugin_dir_path(__FILE__)` | Plugin directory path (trailing slash) |
| `WFE_URL` | `plugin_dir_url(__FILE__)` | Plugin directory URL (trailing slash) |
| `WFE_SLUG` | `widget-finder-for-elementor` | Plugin text domain / slug |

---

## Database Tables

All tables use the WordPress table prefix (`$wpdb->prefix`).

### `{prefix}wfe_widgets`

The imported widget dataset. Populated from `data/widgets.json` at activation. Read-only at runtime.

| Column | Type | Description |
|---|---|---|
| `id` | `mediumint UNSIGNED AUTO_INCREMENT` | Primary key |
| `plugin_slug` | `varchar(191)` | WordPress.org plugin slug |
| `plugin_name` | `varchar(255)` | Human-readable plugin name |
| `widget_type` | `varchar(191)` | Elementor widget registration key |
| `widget_title` | `varchar(255)` | Display name of the widget |
| `widget_title_norm` | `varchar(255)` | Curated aliases/synonyms for search |
| `icon_type` | `varchar(40)` | Icon type identifier (e.g. `elementor_icon_class`) |
| `icon_value` | `varchar(150)` | Icon class or value |
| `active_installs` | `int UNSIGNED` | WordPress.org active install count |
| `rating_average` | `decimal(3,1)` | WordPress.org rating (0.0–5.0) |
| `ranking_score` | `smallint UNSIGNED` | Pre-computed relevance score |
| `search_text` | `text` | Combined searchable string (slug + name + type + title + norm) |

Indexes: `PRIMARY KEY (id)`, `KEY idx_plugin (plugin_slug(50))`, `KEY idx_norm (widget_title_norm(50))`

### `{prefix}wfe_plugin_map`

Canonical `plugin_slug → widget_key` mapping from the dataset. Used by the tracker to snapshot widget types at install time.

| Column | Type | Description |
|---|---|---|
| `plugin_slug` | `varchar(191)` | WordPress.org plugin slug |
| `widget_key` | `varchar(191)` | Elementor widget registration key |

Index: `PRIMARY KEY (plugin_slug, widget_key)`

### `{prefix}widget_finder_plugins`

Tracks plugins installed via WFE.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint UNSIGNED AUTO_INCREMENT` | Primary key |
| `plugin_slug` | `varchar(191) UNIQUE` | WordPress.org plugin slug |
| `plugin_name` | `varchar(255)` | Plugin display name |
| `plugin_file` | `varchar(255)` | Relative plugin file (e.g. `slug/slug.php`) |
| `installed_at` | `datetime` | Installation timestamp |
| `installed_by` | `bigint UNSIGNED` | WordPress user ID |

### `{prefix}widget_finder_plugin_widget_map`

Local snapshot of `plugin_slug → widget_type` taken at install time. Used for usage detection without querying the WFE dataset.

| Column | Type | Description |
|---|---|---|
| `plugin_slug` | `varchar(191)` | Plugin slug |
| `widget_type` | `varchar(191)` | Elementor widget type string |

Index: `PRIMARY KEY (plugin_slug, widget_type)`, `KEY widget_type (widget_type)`

---

## REST API

Base namespace: `wfe/v1`  
All endpoints require a `wp_rest` nonce passed as `X-WP-Nonce` header.

### `GET /wfe/v1/search`

Search the widget dataset.

**Capability required:** `edit_posts`

**Parameters:**

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `q` | string | yes | — | Search term (min 1 character) |
| `limit` | integer | no | `20` | Results per page (1–50) |
| `offset` | integer | no | `0` | Pagination offset |

**Response:**

```json
{
  "items": [
    {
      "widget_raw_id": 123,
      "plugin_slug": "royal-elementor-addons",
      "plugin_name": "Royal Elementor Addons",
      "widget_title": "Woo Grid",
      "widget_type": "wpr-woo-grid",
      "icon_type": "elementor_icon_class",
      "icon_value": "eicon-gallery-grid",
      "description_short": null,
      "active_installs": 200000,
      "rating_average": 4.8,
      "status": "active",
      "plugin_file": "royal-elementor-addons/royal-elementor-addons.php"
    }
  ],
  "has_more": true
}
```

`status` values: `active` | `inactive` | `not_installed`

---

### `POST /wfe/v1/install`

Install a plugin from WordPress.org.

**Capability required:** `install_plugins`

**Body:**

| Param | Type | Required | Description |
|---|---|---|---|
| `slug` | string | yes | WordPress.org plugin slug |
| `plugin_name` | string | no | Display name (used in tracker) |

**Response:**

```json
{ "success": true, "plugin_file": "slug/slug.php" }
```

---

### `POST /wfe/v1/activate`

Activate an installed plugin. Suppresses setup-wizard redirects set during activation.

**Capability required:** `activate_plugins`

**Body:**

| Param | Type | Required | Description |
|---|---|---|---|
| `plugin_file` | string | yes | Relative plugin file (e.g. `slug/slug.php`) |

**Response:** `{ "success": true }`

---

### `POST /wfe/v1/deactivate`

Deactivate a plugin.

**Capability required:** `activate_plugins`

**Body:** `plugin_file` (string, required)

**Response:** `{ "success": true }`

---

### `POST /wfe/v1/uninstall`

Deactivate and delete a plugin's files, then remove it from the tracker.

**Capability required:** `delete_plugins`

**Body:**

| Param | Type | Required | Description |
|---|---|---|---|
| `slug` | string | yes | Plugin slug |
| `plugin_file` | string | yes | Relative plugin file |

**Response:** `{ "success": true }`

> Uses `WP_Filesystem` directly instead of `delete_plugins()` to avoid crashes from broken uninstall scripts.

---

### `POST /wfe/v1/remove`

Remove a plugin from the WFE tracker without touching plugin files.

**Capability required:** `manage_options`

**Body:** `slug` (string, required)

**Response:** `{ "success": true }`

---

### `GET /wfe/v1/debug`

Returns diagnostic data: widget-type map, widget types found in Elementor pages, plugins with missing map entries.

**Capability required:** `manage_options`

---

## Classes

### `WFE_Plugin`

Core singleton. Boots all subsystems from `plugins_loaded`.

- Instantiates `WFE_Rest_API`
- Hooks `WFE_Elementor_Integration::init()` on `elementor/loaded`
- Registers and handles `wfe_daily_cleanup` cron event
- Suppresses editor setup-wizard redirects on `admin_init`

---

### `WFE_Admin`

Registers the admin menu structure and handles the Settings form POST.

**Menu slugs:**

| Constant | Value |
|---|---|
| `MENU_SLUG` | `widget-finder` |
| `PM_SLUG` | `widget-finder-plugins` |
| `SETTINGS_SLUG` | `widget-finder-settings` |

**Actions handled:**

- `admin_post_wfe_save_settings` → `handle_save_settings()` (verifies `wfe_save_settings` nonce via `check_admin_referer`)

---

### `WFE_Database`

Provides the search query against `{prefix}wfe_widgets`.

```php
WFE_Database::search( string $query, int $limit = 20, int $offset = 0 ): array
```

**Search strategy:**
- Single `LIKE` on pre-combined `search_text` column for filtering
- `CASE` expression assigns a relevance tier (0–4) per matched column
- Results ordered: `relevance DESC, ranking_score DESC, active_installs DESC, id ASC`

---

### `WFE_Importer`

Imports `data/widgets.json` into DB tables.

```php
WFE_Importer::create_tables(): void   // idempotent via dbDelta
WFE_Importer::maybe_import(): void    // re-imports if DATA_VERSION changed
```

**`DATA_VERSION`** — bump this constant after updating `widgets.json` to trigger a re-import on the next page load.

**JSON format (v1):**

```json
{
  "v": 1,
  "it": ["elementor_icon_class", "custom_icon_class"],
  "p": {
    "plugin-slug": [
      "Plugin Name",
      50000,
      48,
      58,
      [
        ["widget-type", "Widget Title", "eicon-value", "alias norm"],
        ["widget-type", "Widget Title", [1, "custom-cls"], "alias norm"]
      ],
      ["map-key1", "map-key2"]
    ]
  }
}
```

Plugin data array positions: `[0]` name, `[1]` active_installs, `[2]` rating×10, `[3]` ranking_score, `[4]` widget tuples, `[5]` map keys.

Widget tuple positions: `[0]` widget_type, `[1]` widget_title, `[2]` icon (string = default type; array = `[type_index, value]`), `[3]` widget_title_norm.

---

### `WFE_Plugin_Manager`

Detects plugin installation/activation status and handles install/activate actions.

```php
WFE_Plugin_Manager::get_status( string $slug ): array
WFE_Plugin_Manager::get_statuses( array $slugs ): array  // batch — single get_plugins() call
WFE_Plugin_Manager::install( string $slug ): true|WP_Error
WFE_Plugin_Manager::activate( string $plugin_file ): true|WP_Error
```

**Status constants:** `STATUS_ACTIVE`, `STATUS_INACTIVE`, `STATUS_NOT_INSTALLED`

`activate()` suppresses transients and new option rows containing redirect/setup keywords created during activation, preventing plugins from hijacking the admin redirect after install.

---

### `WFE_Plugin_Tracker`

Tracks WFE-installed plugins and computes per-plugin widget usage across Elementor pages.

```php
WFE_Plugin_Tracker::record_install( string $slug, string $plugin_name, ?string $plugin_file ): void
WFE_Plugin_Tracker::remove( string $slug ): void
WFE_Plugin_Tracker::record_widget_types( string $slug ): void  // snapshot from wfe_plugin_map
WFE_Plugin_Tracker::rebuild_all_maps(): void
WFE_Plugin_Tracker::get_all(): array         // cached via wp_cache
WFE_Plugin_Tracker::get( string $slug ): ?object
WFE_Plugin_Tracker::get_usage_count( string $slug ): int  // cached via transient (1h)
WFE_Plugin_Tracker::clear_usage_cache( string $slug ): void
WFE_Plugin_Tracker::enforce_active_limit( int $max ): void
WFE_Plugin_Tracker::enforce_inactive_limit( int $max ): void
```

**Usage detection** — scans `_elementor_data` postmeta for widget type strings matching the local `widget_finder_plugin_widget_map` snapshot. Uses `wp_cache` for `get_all()` (invalidated on write) and transients for `get_usage_count()`.

**Limit enforcement** — called after install/activate and daily via cron:
- `enforce_active_limit($max)` — deactivates oldest zero-usage active plugins above `$max`
- `enforce_inactive_limit($max)` — uninstalls oldest zero-usage inactive plugins above `$max`

---

### `WFE_Settings`

Settings stored under `widget_finder_settings` in `wp_options`.

```php
WFE_Settings::get(): array    // returns merged with defaults
WFE_Settings::update( array $data ): bool
```

**Available settings:**

| Key | Type | Default | Description |
|---|---|---|---|
| `widgets_per_page` | int | `20` | Results per page in Elementor panel (5–50) |
| `show_plugin_info_button` | bool | `true` | Show plugin description/ratings in widget cards |
| `notification_center` | bool | `true` | Collect admin notices in bell icon |
| `menu_consolidation` | bool | `false` | Group WFE-installed plugin menus in sidebar |
| `max_active_unused` | int | `5` | Max active plugins with 0 widget usage (0 = disabled) |
| `max_inactive_unused` | int | `5` | Max inactive plugins with 0 widget usage (0 = disabled) |

---

### `WFE_Elementor_Integration`

Enqueues editor assets via `elementor/editor/before_enqueue_scripts`.

Passes `wfeData` to `wfe-panel.js`:
- REST URLs for search, install, activate, deactivate
- `wp_rest` nonce
- Settings: `widgetsPerPage`, `showPluginInfoButton`
- i18n strings

---

### `WFE_Menu_Consolidator`

Groups admin menus of WFE-installed plugins under a single "Plugin Settings" toggle in the sidebar.

**Toggle slug:** `wfe-plugin-settings-group`

Detection uses two passes at `admin_menu` priority `PHP_INT_MAX`:
1. **Top-level scan** — reflects `add_menu_page` callbacks against WFE plugin directories
2. **Submenu parent scan** — reflects `add_submenu_page` callbacks to catch plugins that only attach under CPT-generated menu items

PHP `ReflectionFunction` / `ReflectionMethod` maps each callback to its source file, which is compared against tracked plugin directories.

---

## WordPress Hooks

### Actions registered

| Hook | Callback | Priority | Description |
|---|---|---|---|
| `plugins_loaded` | `wfe_init()` | 10 | Bootstraps entire plugin |
| `admin_menu` | `WFE_Admin::register_menu()` | 10 | Registers WFE menu pages |
| `admin_menu` | `WFE_Menu_Consolidator::consolidate()` | `PHP_INT_MAX` | Moves plugin menus into group |
| `admin_enqueue_scripts` | `WFE_Admin::enqueue_assets()` | 10 | Admin page assets |
| `admin_enqueue_scripts` | `WFE_Admin::enqueue_notice_manager()` | 10 | Notification center assets |
| `admin_enqueue_scripts` | `WFE_Menu_Consolidator::enqueue()` | 10 | Menu collapse assets |
| `admin_post_wfe_save_settings` | `WFE_Admin::handle_save_settings()` | 10 | Settings form POST |
| `rest_api_init` | `WFE_Rest_API::register_routes()` | 10 | REST route registration |
| `elementor/editor/before_enqueue_scripts` | `WFE_Elementor_Integration::enqueue_editor_assets()` | 10 | Editor panel assets |
| `admin_init` | closure | 1 | Suppress redirects in Elementor editor |
| `wfe_daily_cleanup` | `WFE_Plugin::run_auto_delete()` | 10 | Daily cron: enforce plugin limits |

### Filters applied (outgoing)

| Filter | Purpose |
|---|---|
| `wp_redirect` | Suppressed to empty string during plugin activation to block setup-wizard redirects |

---

## Cron

**Event:** `wfe_daily_cleanup`  
**Schedule:** `daily`  
**Handler:** `WFE_Plugin::run_auto_delete()`  
**Action:** Calls `enforce_active_limit()` and `enforce_inactive_limit()` with values from settings.

Scheduled on first `plugins_loaded` after activation. Unscheduled on plugin deactivation.

---

## Dataset Update Workflow

1. Edit `data/widgets.sqlite` (source of truth)
2. Run: `python3 tools/build-widgets-json.py` → regenerates `data/widgets.json`
3. Bump `WFE_Importer::DATA_VERSION` in `class-wfe-importer.php`
4. On next WordPress page load, `maybe_import()` detects the version change, truncates both `wfe_widgets` and `wfe_plugin_map`, and re-imports from scratch

> `data/widgets.sqlite` and `tools/` are development-only and excluded from the plugin distribution package.

---

## Security

- All admin forms use `wp_nonce_field()` / `check_admin_referer()`
- All `$_POST` values are passed through `wp_unslash()` before sanitization
- All dynamic SQL values use `$wpdb->prepare()`
- Table names constructed from `$wpdb->prefix . 'fixed_name'` and sanitized via `esc_sql()`
- REST endpoints enforce WordPress capability checks via `permission_callback`
- Plugin file paths validated against `WP_PLUGIN_DIR` before deletion

---

## Examples & Use Cases

### 1. Search widgets via REST API (JavaScript)

The Elementor panel (`wfe-panel.js`) calls this on every keystroke (debounced). You can replicate it from any JS context that has a `wp_rest` nonce.

```js
const response = await fetch(wfeData.restUrl + '?q=slider&limit=10&offset=0', {
  headers: { 'X-WP-Nonce': wfeData.nonce },
});
const { items, has_more } = await response.json();

items.forEach(item => {
  console.log(item.widget_title, item.plugin_name, item.status);
  // e.g. "Advanced Slider", "Royal Elementor Addons", "not_installed"
});
```

---

### 2. Search widgets via REST API (PHP / server-side)

Useful for building admin tools, reports, or automated tests.

```php
$response = wp_remote_get( rest_url( 'wfe/v1/search' ), [
    'headers' => [
        'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ),
    ],
    'body' => [ 'q' => 'slider', 'limit' => 5 ],
] );

$body  = json_decode( wp_remote_retrieve_body( $response ), true );
$items = $body['items'] ?? [];
```

---

### 3. Search widgets directly in PHP (internal use)

If you need search results inside a server-side context (e.g. a WP-CLI command or another plugin), call `WFE_Database::search()` directly — no HTTP round-trip.

```php
// Requires WFE to be active and its classes loaded.
$results = WFE_Database::search( 'accordion', 20, 0 );

foreach ( $results as $row ) {
    printf(
        "%-40s %-30s relevance=%d\n",
        $row->widget_title,
        $row->plugin_name,
        $row->relevance
    );
}
```

Output example:
```
Accordion                                Royal Elementor Addons         relevance=3
Advanced Accordion                       Essential Addons               relevance=3
Accordion Widget                         Premium Addons for Elementor   relevance=3
Collapsible / Accordion                  HT Mega Addons                 relevance=0
```

---

### 4. Install and activate a plugin programmatically

This is exactly what the Elementor panel does when the user clicks "Install Plugin". Can be used in WP-CLI scripts or admin tools.

```php
// Step 1: Install from WordPress.org
$result = WFE_Plugin_Manager::install( 'royal-elementor-addons' );

if ( is_wp_error( $result ) ) {
    error_log( 'WFE install failed: ' . $result->get_error_message() );
    return;
}

// Step 2: Detect the plugin file after install
$status = WFE_Plugin_Manager::get_status( 'royal-elementor-addons' );
// $status = [ 'status' => 'inactive', 'plugin_file' => 'royal-elementor-addons/royal-elementor-addons.php' ]

// Step 3: Record in tracker
WFE_Plugin_Tracker::record_install(
    'royal-elementor-addons',
    'Royal Elementor Addons',
    $status['plugin_file']
);
WFE_Plugin_Tracker::record_widget_types( 'royal-elementor-addons' );

// Step 4: Activate
$result = WFE_Plugin_Manager::activate( $status['plugin_file'] );
```

---

### 5. Batch-resolve plugin statuses

When rendering a list of plugins, use `get_statuses()` instead of calling `get_status()` in a loop — it calls `get_plugins()` only once.

```php
$slugs = [ 'royal-elementor-addons', 'essential-addons-for-elementor-lite', 'jeg-elementor-kit' ];

$statuses = WFE_Plugin_Manager::get_statuses( $slugs );

foreach ( $statuses as $slug => $info ) {
    printf( "%s → %s (%s)\n", $slug, $info['status'], $info['plugin_file'] ?? 'n/a' );
}
// royal-elementor-addons → active (royal-elementor-addons/royal-elementor-addons.php)
// essential-addons-for-elementor-lite → inactive (essential-addons-for-elementor-lite/essential_adons_elementor.php)
// jeg-elementor-kit → not_installed (n/a)
```

---

### 6. Check how many pages use a plugin's widgets

```php
$slug  = 'royal-elementor-addons';
$count = WFE_Plugin_Tracker::get_usage_count( $slug );

if ( $count === 0 ) {
    echo 'No pages use widgets from this plugin — safe to deactivate.';
} else {
    printf( '%d page(s) contain widgets from %s.', $count, $slug );
}
```

The result is cached in a transient for 1 hour. To force a fresh count:

```php
WFE_Plugin_Tracker::clear_usage_cache( $slug );
$fresh_count = WFE_Plugin_Tracker::get_usage_count( $slug );
```

---

### 7. Manually enforce cleanup limits

WFE runs this daily via cron, but you can trigger it on demand — e.g. after a bulk import or migration.

```php
$settings = WFE_Settings::get();

// Deactivate oldest zero-usage active plugins until at most 3 remain.
WFE_Plugin_Tracker::enforce_active_limit( 3 );

// Uninstall oldest zero-usage inactive plugins until at most 3 remain.
WFE_Plugin_Tracker::enforce_inactive_limit( 3 );
```

---

### 8. Read and update settings

```php
// Read all settings (merged with defaults)
$s = WFE_Settings::get();
echo $s['widgets_per_page'];        // 20
echo $s['notification_center'];     // true

// Update specific settings (unknown keys are ignored)
WFE_Settings::update( [
    'widgets_per_page'    => 30,
    'menu_consolidation'  => true,
    'max_active_unused'   => 0,     // 0 = disabled
] );
```

---

### 9. Rebuild widget-type maps after a data change

If you update `widgets.json` and bump `DATA_VERSION`, the import runs automatically. But if you need to force a re-snapshot of widget-type maps for all tracked plugins (e.g. after fixing exclusion logic):

```php
// Bump the map version key in widget-finder-for-elementor.php to trigger this
// automatically on the next page load, OR call directly:
WFE_Plugin_Tracker::rebuild_all_maps();
```

---

### 10. Listen for WFE cron cleanup (integration example)

If a third-party plugin needs to react when WFE deactivates or uninstalls a plugin:

```php
// WFE deactivates plugins using deactivate_plugins() — hook into the standard action.
add_action( 'deactivated_plugin', function ( string $plugin_file ) {
    // $plugin_file e.g. "royal-elementor-addons/royal-elementor-addons.php"
    // Check if this plugin is WFE-tracked:
    $slug    = dirname( $plugin_file );
    $tracked = WFE_Plugin_Tracker::get( $slug );
    if ( $tracked ) {
        // Do something — e.g. log or notify.
    }
} );
```

---

### 11. Add a custom icon type to the dataset

The JSON format supports multiple icon types via the `it` lookup array. By default only `elementor_icon_class` is used. To add a custom icon system:

In `widgets.json`:
```json
{
  "v": 1,
  "it": ["elementor_icon_class", "svg_inline"],
  "p": {
    "my-plugin": [
      "My Plugin", 5000, 45, 40,
      [
        ["my-widget", "My Widget", "eicon-star", "star widget"],
        ["my-svg-widget", "SVG Widget", [1, "<svg>...</svg>"], "custom svg"]
      ],
      ["my-widget", "my-svg-widget"]
    ]
  }
}
```

Widget tuple `[1, "<svg>..."]` means: use `it[1]` = `"svg_inline"` as `icon_type`, and the SVG string as `icon_value`. The panel JS reads both fields to render the icon.

---

### 12. Disable WFE features selectively via settings

All major features can be turned off without deactivating the plugin:

| Goal | Setting | Value |
|---|---|---|
| Disable notification bell | `notification_center` | `false` |
| Disable menu consolidation | `menu_consolidation` | `false` |
| Disable active plugin cleanup | `max_active_unused` | `0` |
| Disable inactive plugin cleanup | `max_inactive_unused` | `0` |
| Hide plugin info buttons | `show_plugin_info_button` | `false` |

```php
WFE_Settings::update( [
    'notification_center' => false,
    'max_active_unused'   => 0,
    'max_inactive_unused' => 0,
] );
```
