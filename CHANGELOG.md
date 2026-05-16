# Changelog

All notable changes to **OIS Conversion Suite** are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

**Versioning note:** use `0.73.x` patch bumps for small fixes (45, 46, …); bump the middle segment (`0.74.x`) for larger feature or behavior changes.

## [Unreleased]

### Added

- **Docs:** `utm-manager-funnel-semantics.md` (UTM Funnel Steps 1–3, aligned with metrics logic).
- **Docs:** `utm-manager-roles-capabilities.md` (UTM menus, exports, AJAX, alerts vs `view_ois_analytics` / `manage_options`).
- **`OISCL_Utm_Alert_Rules`:** pure predicates for campaign drop / zero-window alerts; PHPUnit `UtmAlertRulesTest`.
- **PHPUnit:** `TrackingNormalizeAnchorTest` for `OISCL_Tracking::normalize_anchor_for_storage`.
- **Repo:** `.gitignore` excludes `vendor/` and `.phpunit.result.cache` until Composer is used locally.
- **Send Reports (MVP):** schedule email snapshots from **Custom Dashboard** templates (`oiscl_scheduled_report_jobs`, cron `oiscl_scheduled_reports_tick`); CSV attachment when the board includes tabular columns; date presets via `OISCL_Report_Date_Ranges`; **daily** cadence option (`CADENCE_DAILY`). Dashboard metric dictionary moved to `OISCL_Dashboard_Dictionary`.

### Changed

- **Send Reports:** Admin page and scheduled email copy default to **English** (replacing Spanish strings).

- **Send Reports:** Per-job **preferred send time** (site timezone), **pause/resume** without deleting jobs, **next run** aligned to cadence + clock (`compute_next_run_after`), and **richer notification emails** (summary table, CSV size when attached, links to Custom Dashboards and Send Reports).

- **Send Reports:** **Delivery format** (email-only, CSV, HTML snapshot, print-ready HTML for Save as PDF), presets **Yesterday** / **Today**, default range **Yesterday**, Daily cadence auto-suggests Yesterday in the form, **Send now** from the form or each saved job (does not change schedules), and shared tabular export for CSV/HTML attachments (`get_tabular_export`).

- **Admin UTM trait:** Proper docblock on `oiscl_process_utm_settings_request` (replaces obsolete inline marker).

### Fixed

- **Send Reports:** Email attachments no longer keep the host `.tmp` name — files are renamed to `oiscl-report-{dashboard}-{start}-to-{end}.csv` or `.html` (print-ready adds `-print`) so clients open or save with the correct type.

- **Capabilities:** Administrator now receives **`manage_ois_marketing`** whenever it is missing (bootstrap runs independently of `view_ois_analytics`). Restores access to Custom Dashboards, Send Reports, and SEO when WordPress showed “Sorry, you are not allowed to access this page.”

- **Capabilities:** `map_meta_cap` treats **`manage_options`** as satisfying **`view_ois_analytics`** and **`manage_ois_marketing`** so site admins retain menu access when role capabilities were stripped by another plugin or host tooling.

- **UTM Funnel:** Global card footnote for Step 3 now matches behaviour — conversion labels apply on **Funnel by campaign link** rows; global and company rollups use the broad Step 3 rule.

## [0.73.6] - 2026-05-12

### Fixed

- **OIS Analytics:** Previous comparison window now uses the same inclusive day count as Global Dashboard (`diff_days` + `prev_start` / `prev_end`).
- **`mini_delta`:** Cast to float so “vs past” on **Act./view** (decimals) is not truncated to integers.
- **Audience → Top Countries:** Removed a second query that overwrote `$prev_countries_map` with rows that had **views only** (no `uniques`), so “Uniques vs past” always showed 0.
- **Chart.js 3:** Tab switch resize no longer relies on removed `Chart.instances`; uses `Chart.getChart(canvas)`.

### Changed

- **Top Cities (internal list):** `COUNT(*)` → `SUM(clicks)` for consistency with other “views” metrics.
- **Hourly Traffic widget:** Tooltip copy explains that each hour buckets **all days** in the selected range.

## [0.73.5] - 2026-05-12

### Fixed

- **Advanced table pagination:** prev/next looked for `.tp-page-num.active` inside the `<table>`; controls live in `#pag-wrap-{id}` outside the table, so `cur` was always undefined. Handlers now read the active page from `#pag-wrap-{id}`. Removed duplicate Track Pro pagination `$(document).on` + second `setupOisTable` (already initialized in `layout_end`) to avoid double-stepping.
- **Analytics header:** `format_kpi_delta` for the engagement KPI used a previous-period value that was not computed before the header render.

### Changed

- **KPI “CTR (ACTIONS)” → “ACTIONS / PV”:** The old value was `(SUM(clicks) on non-pageview rows ÷ SUM(clicks) on [Pageview]) × 100`. That is **not** a classical CTR cap at 100%; with many tracked interactions per load it explodes (e.g. 2299 “%”). It is now shown as a **ratio** (same division, **no ×100**, 2 decimals), e.g. `22.99`, labeled **ACTIONS / PV** (actions per pageview-click sum). Track Pro header now uses **SUM(clicks)** for actions (aligned with Global Dashboard / Analytics), not `COUNT(*)`.
- **AVG RETENTION (header KPI):** `AVG(time_spent)` now only includes dwell-style rows: Global Dashboard / Analytics / Track Pro use `[Pageview]` and `[Vista de Bloque]`; UTM stats table uses `[Pageview]`, `[Bloque]`, and `Reading`.
- **UTM header:** “Actions” for the ratio use **SUM(clicks)** (same filters as before); **unique users** for that header now count distinct `session_id` on `[Pageview]` rows in the range (aligned with other screens). Ratio KPI shown as **ACTIONS / PV** without a false percent.
- **Quick overview / CRO tables:** per-page “CTR” column relabeled to **Act./view** and shows the same ratio (no `%`), with heat thresholds scaled to the ratio (0.02 / 0.05).

### Added

- **Backups:** ZIP `.oiscl` export (`manifest.json` + `metrics.jsonl`), streaming import, upload error messages, legacy JSON size guard (~80 MB).

## [0.73.4] - 2026-05-12

### Fixed

- Admin white screen / fatal: reverted the experimental “Summary + top interactions” block on the global dashboard; restored the previous V2 preview section after `layout_end` so the page matches the last known-good structure.
- Version badge: read plugin metadata from `OISCL_PLUGIN_FILE` when `OISCL_VERSION` is empty (avoids wrong `dirname` depth from trait paths).
- Trait composition: `OISCL_Admin_UI_Charts_Trait` is listed before `OISCL_Admin_Dashboard_Trait` so shared helpers like `format_kpi_delta` resolve reliably.

## [0.73.3] - 2026-05-12

### Fixed

- Admin header version badge: use `OISCL_VERSION` or `get_plugin_data( OISCL_PLUGIN_FILE )` so the label shows e.g. `v0.73.3` instead of a bare `v`.

### Changed

- Global dashboard: experimental integrated summary + “Top interactions” block (superseded in **0.73.4** after stability issues).

## [0.73.2] - 2026-05-12

### Added

- **Settings → Maintenance**: form to save `oiscl_delete_data_on_uninstall` (checkbox + “Save uninstall preference”) with nonce, `manage_options`, and success notice. Matches behavior documented in `uninstall.php`.

## [0.73.1] - 2026-05-12

### Added

- `uninstall.php`: optional full data removal when `oiscl_delete_data_on_uninstall` is set to `1` or constant `OISCL_UNINSTALL_WIPE_DATA` is true; otherwise uninstall leaves the database intact.
- `OISCL_PLUGIN_FILE` and `OISCL_VERSION` constants in the main plugin file for reliable paths (e.g. deactivate on cleanup).

### Changed

- `ajax_get_pulse_data`: requires `view_ois_analytics` after valid nonce.
- V2 AJAX: all handlers require `manage_options`; `wp_remote_get` in the inspector only runs for URLs on the same host as `home_url()` / `site_url()` (SSRF mitigation); JSON error payloads use arrays; `ajax_v2_save_settings` success response is structured for consistency.
- Full uninstall cleanup uses `plugin_basename( OISCL_PLUGIN_FILE )` instead of a hardcoded plugin path.

## [0.73.0] - 2026-05-12

### Added

- Modular admin code: `OISCL_Admin` composed from PHP traits under `admin/traits/` (dashboards, analytics, TrackPro, SEO, settings, AJAX, UTM, v2 inspector, shared UI).
- `ajax_save_target_pages` handler: saves TrackPro target page IDs from the admin UI (max 5 entries, `manage_options`, `oiscl_admin_nonce`).
- Plugin headers: `Requires at least`, `Requires PHP`, `Text Domain`, `Domain Path`.
- `load_plugin_textdomain( 'ois-conversion-suite' )` and `languages/` directory for translations.
- `readme.txt` (WordPress-style) alongside this changelog.

### Changed

- Admin menu strings wrapped for i18n (`ois-conversion-suite`); English source strings for new/updated labels.
- Pulse AJAX (`oiscl_get_pulse_data`): removed `nopriv` registration; nonce verification accepts `oiscl_admin_nonce` or `oiscl_track_nonce` for backward compatibility; UTM screen uses `oiscl_admin_nonce`.
- Public-facing plugin description (header) in English.
- Asset cache-bust fallbacks aligned to `0.73.0`.

### Fixed

- `enqueue_admin_assets` asset URLs when admin code lives under `admin/traits/` (paths resolve to plugin root via `dirname( __FILE__, 2 )`).
- Pulse JSON payload now includes `online_users` (approximate distinct sessions in the last 5 minutes, aligned with the pulse chart window) so the UTM tracker header widget can update.

## [0.72.31] and earlier

- Legacy monolithic `class-oiscl-admin.php` and prior behavior; see git history if available.
