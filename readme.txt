=== OIS Conversion Suite ===
Contributors: orangeinternetsolutions
Tags: analytics, conversion, tracking, seo, utm
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.73.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Centralized conversion intelligence and SEO toolkit for WordPress.

== Description ==

OIS Conversion Suite (Conversion Lab) provides admin dashboards, click and block metrics, UTM-oriented reporting, SEO audit helpers, and related tools. This release focuses on maintainability: modular admin PHP, i18n-ready strings, and documented requirements.

== Installation ==

1. Upload the plugin folder `ois-conversion-suite` to `/wp-content/plugins/` (main file `ois-conversion-suite.php`) or install the zip from the Plugins screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open **Conversion Lab** in the admin sidebar to configure and view reports.

== Frequently Asked Questions ==

= Where are translations loaded? =

The text domain is `ois-conversion-suite`. Place `.mo` files under the `languages/` directory inside the plugin (or use a standard WordPress languages location as appropriate for your workflow).

= Does uninstall delete my metrics? =

No, unless you explicitly enable it: set option `oiscl_delete_data_on_uninstall` to `1`, or define the PHP constant `OISCL_UNINSTALL_WIPE_DATA` as true before deleting the plugin. Otherwise `uninstall.php` exits without dropping tables.

== Changelog ==

= 0.73.6 =
* OIS Analytics: align previous-period day math with Global Dashboard; fix `mini_delta` for decimal ratios (Act./view); stop overwriting `prev_countries_map` without `uniques` (Audience country deltas); Top Cities uses `SUM(clicks)` like other view metrics; hourly chart tip clarifies multi-day aggregation; Chart.js v3 tab resize uses `Chart.getChart`.

= 0.73.5 =
* KPI header: replace misleading “CTR (ACTIONS) %” with **ACTIONS / PV** (ratio of SUM(clicks) on interactions vs pageviews); Track Pro header now uses SUM(clicks) like the global dashboard.
* AVG RETENTION: average `time_spent` only on `[Pageview]` and block/reading rows (Vista de Bloque / UTM equivalents), not every metric row.
* UTM header: unique users = distinct sessions with a `[Pageview]` in range (aligned with other dashboards).
* Analytics: define previous-period metrics for the header delta on ACTIONS/PV; CRO table column **Act./view** (ratio, no false percent).
* Advanced table: pagination arrows read active page from `#pag-wrap-{id}`; remove duplicate Track Pro pagination handlers.
* Changelog: versioning note for patch vs minor bumps; release notes for this build.

= 0.73.4 =
* Hotfix: restore stable global dashboard layout (revert experimental summary block that could fatal); keep version badge fix via `OISCL_PLUGIN_FILE` / `OISCL_VERSION`; trait order: charts before dashboard.

= 0.73.3 =
* Fix version label next to suite title (correct plugin file path from admin traits).

= 0.73.2 =
* Settings → Maintenance: UI to enable/disable deleting all OIS data when the plugin is removed from the Plugins screen (stored option; same rules as `uninstall.php`).

= 0.73.1 =
* Security: pulse AJAX requires `view_ois_analytics`; V2 inspector requires `manage_options` and same-site URL allowlist before `wp_remote_get`.
* Added `uninstall.php` (optional wipe via option or `OISCL_UNINSTALL_WIPE_DATA` constant).
* `OISCL_PLUGIN_FILE` / `OISCL_VERSION` constants; uninstall deactivate uses correct plugin basename.

= 0.73.0 =
* Split admin into composable traits under `admin/traits/`.
* Added missing `ajax_save_target_pages` AJAX handler (max 5 target pages).
* Declared Requires PHP 8.0+, WordPress 6.0+, text domain, and English description.
* Pulse endpoint: admin-only registration; dual-nonce verification; UTM screen nonce aligned.
* i18n for primary admin menu titles; `load_plugin_textdomain` on `plugins_loaded`.

For a fuller history see `CHANGELOG.md` in the plugin root.

== Upgrade Notice ==

= 0.73.0 =
Admin PHP is reorganized into multiple files. Database prefix `oiscl_` and options are unchanged. If you customized `class-oiscl-admin.php` directly, port changes into the relevant trait under `admin/traits/`.
