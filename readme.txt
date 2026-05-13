=== Widget Finder for Elementor ===
Contributors: dudaster
Author: dudaster
Tags: elementor, widgets, search, plugin manager, widget finder
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extends Elementor's widget search to surface widgets from installed, inactive, and not-yet-installed plugins.

== Description ==

Widget Finder for Elementor enhances the Elementor editor's widget panel with a powerful search experience that goes beyond the widgets you already have installed.

**Key features:**

* **Universal widget search** — Search across thousands of Elementor widgets, including those from plugins you haven't installed yet.
* **One-click install & activate** — Install and activate a plugin directly from the Elementor editor search panel, without leaving your design workflow.
* **Plugin Manager** — View and manage all plugins installed via Widget Finder from a dedicated admin page. Deactivate or uninstall them in bulk.
* **Automatic cleanup** — Configurable limits automatically deactivate or uninstall unused plugins to keep your site lean.
* **Notification Center** — Admin notices are collected in a bell icon instead of cluttering the top of every admin page.
* **Plugin Settings consolidation** — Optionally group all Widget Finder-installed plugin menus under a single "Plugin Settings" toggle in the admin sidebar.

== Installation ==

1. Upload the `widget-finder-for-elementor` folder to the `/wp-content/plugins/` directory, or install it through the WordPress Plugin Directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open the Elementor editor — a new **Widget Finder** search panel will appear in the widgets sidebar.
4. Search for any Elementor widget. Results include widgets from not-yet-installed plugins, shown with an install button.

== Frequently Asked Questions ==

= Does this plugin require Elementor? =

Yes. Widget Finder for Elementor is an add-on for the Elementor page builder and requires Elementor to be installed and active.

= Will installing plugins through Widget Finder affect my site's performance? =

Widget Finder's automatic cleanup feature (configurable in Settings) can automatically deactivate plugins you installed but are no longer using on any page.

= Where do I manage the plugins I installed via Widget Finder? =

Go to **Widget Finder → Plugin Manager** in the WordPress admin sidebar.

= Can I disable the Notification Center? =

Yes. Go to **Widget Finder → Settings** and uncheck the **Notification center** option.

== Screenshots ==

1. Widget Finder search panel inside the Elementor editor.
2. Plugin Manager — view and manage all Widget Finder-installed plugins.
3. Settings page.

== Changelog ==

= 1.7.0 =
* Plugin renamed to "Widget Finder for Elementor".
* Added: automatic updates via GitHub releases (Plugin Update Checker).

= 1.6.9 =
* Fixed: text domain updated to `widget-finder-for-elementor` throughout.
* Fixed: input sanitization hardened (`wp_unslash`, `sanitize_key`).
* Fixed: database queries use `esc_sql()` and `$wpdb->prepare()` correctly.
* Added: Notification Center can be enabled/disabled from Settings.
* Added: Plugin Settings menu consolidation in the admin sidebar.

= 1.6.0 =
* Added: automatic cleanup of unused installed plugins (configurable limits).
* Added: Notification Center bell icon for admin notices.
* Improved: Plugin Manager bulk actions.

= 1.5.0 =
* Added: Plugin Manager page to view and manage Widget Finder-installed plugins.
* Added: Settings page.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.6.9 =
Recommended update — includes security hardening and WordPress.org compliance fixes.
