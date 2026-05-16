# OIS UTM Manager — QA checklist (Phase 1)

Use this list before marking **OIS UTM Manager** as production-ready. Environment: staging or production mirror with real tracking (`utm_campaign` in URLs), caches flushed after deploy.

## 1. Deploy & environment

- [ ] Plugin version deployed matches the tested build (PHP + `assets/js` / CSS).
- [ ] CDN / full-page cache / asset optimisation invalidated after upload (no stale `oiscl-trackpro*.js`).
- [ ] Production: **`WP_DEBUG_DISPLAY`** off; **`WP_DEBUG_LOG`** optional on staging only.
- [ ] Third-party plugins that spam deprecations (e.g. Track The Click `$plugin_screen_hook_suffix` on PHP 8.2+) patched or updated so QA isn’t blocked.

## 2. Smoke: navigation & permissions

- [ ] **Settings → Maintenance → Hosting & plugin health check** (Ping admin-ajax + UTM shortcuts). **Settings → Backup / Restore**: export dialog (all vs date range) + import smoke test if used.
- [ ] All main tabs load: **Overview**, **UTM Content & CRO**, **UTM Funnel**, **UTM Click Tracker**, **UTM Audience**, **UTM User Journey**.
- [ ] Header date range: preset links (Today / 7 days / …), prev/next arrows, custom range submit preserve **`tab`** and relevant params (**`uct_tab`** on Click Tracker, **`utm_filter`**, **`tp_page`** / **`tp_revision`** when set).
- [ ] **Roles:** user with only **`view_ois_analytics`** sees **OIS UTM Manager** but not **Suite Settings**; user with **`manage_options`** can save UTM references and Campaign alerts (see `utm-manager-roles-capabilities.md`).

## 3. Filter sweep (`utm_filter`)

Repeat each check with:

- [ ] **All companies & campaigns**
- [ ] One **company** (`lbl_…`)
- [ ] One **single campaign** slug from the dropdown

Expect: no DB errors in debug log; KPIs and charts/lists consistent with filter.

## 4. UTM Funnel

- [ ] **Global UTM funnel** card shows plausible Step 1 when traffic exists with stored `utm_campaign`.
- [ ] **Funnel by company** / **Funnel by campaign link** show rows with numeric cells (not blank columns).
- [ ] If Step 1 > 0 and Step 2 = 0 everywhere: verify Click Tracker **block view** events exist on landing templates (see on-page guidance notice).
- [ ] **Export funnel** downloads CSV (`export_csv=utm_funnel&funnel_scope=company|campaign|both|global|complete`) with rows aligned to visible funnel sections and respecting **`utm_filter`** + dates (`complete` = global block + company table + campaign table).
- [ ] Step definitions for sign-off match `utm-manager-funnel-semantics.md` (Steps 1–3 and global vs per-link conversion rules).

## 5. UTM Click Tracker

- [ ] Sub-tabs **Overview / Clicks / Reading Map** keep selection when changing **Tracked page**, **Config version**, **UTM filter**, and date navigation.
- [ ] Charts/tables populate when data exists; no SQL errors (previous issues: `ORDER BY … AND utm_campaign` fixed server-side).

## 6. UTM Audience

- [ ] Device/OS/browser/resolution charts render when data exists.
- [ ] Top lists show entries or an explicit empty state.
- [ ] **Export CSV** per list downloads and opens with expected columns (`export_csv=utm_audience&audience_list=…`).

## 7. UTM User Journey

- [ ] Table loads; accordion rows expand/collapse.
- [ ] **Attribution** selector (`utm_attr`) updates URL and results.
- [ ] **Export CSV** (standard + **full census** when offered) works for permitted roles.

## 8. Campaign alerts (`OISCL_Utm_Alerts`)

- [ ] **Settings** → **UTM Manager** shows **Campaign alerts** (enable, email, drop threshold %, compare window days, zero-traffic hours); save returns success and values persist after reload.
- [ ] With alerts **enabled** and a valid email: behaviour matches intent — daily cron hook `oiscl_utm_daily_alerts` evaluates saved **UTM references** vs **`oiscl_block_metrics`** (drop vs prior period when prior clicks ≥ 5; “zero traffic” when no hits in the configured hours but traffic existed in the last ~30 days).
- [ ] Users with **`view_ois_analytics`** see a **warning admin notice** listing up to five messages on **`oiscl-utm-tracker`**, **`oiscl-settings`**, or **`oiscl-intro`** when computed alerts exist (stored option refreshed after cron).
- [ ] With alerts **disabled**, notices are not expected from this subsystem (no spam); re-enable after confirming mail/debug if you use email delivery.

## 9. Diagnostics (staging only)

- [ ] With `define('OISCL_UTM_DIAG', true);` in `wp-config.php`, diagnostic panel appears on relevant tabs and proves rows/`utm_campaign` visibility.
- [ ] Remove or set `OISCL_UTM_DIAG` to `false` before go-live.

## 10. Front-end tracking sanity

- [ ] Landing URL with full UTM query hits **`oiscl_block_metrics`** with expected `utm_*` (session cookie `oiscl_utm_v1` behaviour optional check in Application tab).
- [ ] Internal navigation without query still carries UTM on subsequent beacons (persistence).

## 11. Sign-off

- [ ] No unresolved PHP warnings/errors in log during the above flows.
- [ ] Product owner accepts funnel definitions (Steps 1–3) as documented in the admin funnel guidance panel.

---

**Already in repo:** `composer test` runs PHPUnit (`tests/`, e.g. `UtmQueryHelperTest` for SQL fragment injection + funnel CSV section flags). `.github/workflows/phpunit.yml` runs that suite on PHP 8.1–8.3 for pushes to `main` / `master` / `develop` and for pull requests.

**Further backlog (Phase 2+):** see `utm-manager-roadmap.md`.
