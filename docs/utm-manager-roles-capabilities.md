# OIS UTM Manager — Roles & capabilities

Reference for **who can see what** in wp-admin. Paths refer to **OIS Conversion Suite** (“Conversion Lab”). Keep this file aligned with `add_submenu_page` / `current_user_can` checks when behaviour changes.

## Custom capabilities

| Capability | Typical use in this plugin |
|------------|----------------------------|
| **`view_ois_analytics`** | Read-only analytics surface: Global Dashboard, OIS Analytics, Click Tracker, **OIS UTM Manager** (all tabs), Suite areas that register with this cap. Required for UTM CSV exports and pulse widget refresh scoped to UTM. |
| **`manage_ois_marketing`** | **Custom Dashboards**, **OIS SEO Audit**, **Send Reports** submenus (not required for UTM Manager). |
| **`manage_options`** | **Suite Settings** (`oiscl-settings`), including **Settings → UTM Manager** (saved links, URL builder, Campaign alerts form). Also several privileged AJAX handlers and the optional **`OISCL_UTM_DIAG`** SQL panel. |

WordPress **Administrator** already has `manage_options`; **Editor** does not, unless extended by another plugin.

## Grants on plugin activation

Defined in `OISCL_Activator::activate()` (`includes/class-oiscl-activator.php`):

| WP role | Caps added |
|---------|------------|
| **Administrator** | `view_ois_analytics`, `manage_ois_marketing` |
| **Editor** | `view_ois_analytics`, `manage_ois_marketing` |
| **New role `ois_client`** | `read`, `view_ois_analytics` only |

**Note:** Editors receive **`manage_ois_marketing`** by default. That exposes Custom Dashboards / SEO / Reports, not Suite Settings. If that is too broad for your org, remove or adjust caps with a roles plugin after activation.

## UTM Manager — matrix

| Action / screen | Capability | Code reference (indicative) |
|-----------------|------------|---------------------------|
| Submenu **OIS UTM Manager** (`oiscl-utm-tracker`) | `view_ois_analytics` | `trait-oiscl-admin-core.php` — `add_submenu_page` |
| All UTM tabs (Overview, Content & CRO, Funnel, Click Tracker, Audience, User Journey) | `view_ois_analytics` | Same menu cap; no extra check on render |
| CSV exports: `utm_journey`, `utm_audience`, `utm_funnel` | `view_ois_analytics` | `trait-oiscl-admin-core.php` — `handle_csv_export` |
| AJAX **pulse** (`oiscl_get_pulse_data`), including `scope=utm` | `view_ois_analytics` + valid nonce | `trait-oiscl-admin-ajax.php` — `ajax_get_pulse_data` |
| **Campaign alerts** admin notices (`oiscl-utm-tracker`, `oiscl-settings`, `oiscl-intro`) | `view_ois_analytics` | `class-oiscl-utm-alerts.php` — `render_admin_notices` |
| **Suite Settings** page (any tab, including UTM Manager settings) | `manage_options` | `trait-oiscl-admin-core.php` — Suite Settings submenu |
| Save / delete **UTM references** (POST / GET delete) | `manage_options` | `trait-oiscl-admin-utm.php` — `oiscl_process_utm_settings_request` |
| Save **Campaign alerts** (`admin_post_oiscl_save_utm_alerts`) | `manage_options` | `class-oiscl-utm-alerts.php` — `handle_save_settings` |
| AJAX **UTM raw activity log** (`oiscl_utm_raw_log`) | `manage_options` + nonce | `trait-oiscl-admin-utm.php` — `ajax_oiscl_utm_raw_log` |
| **`OISCL_UTM_DIAG`** metrics debug panel | `manage_options` + constant | `trait-oiscl-admin-utm.php` — `oiscl_render_utm_block_metrics_diag` |

## Front-end tracking (not wp-admin)

Public beacon **`oiscl_track_click`** (`nopriv` + logged-in): gated by **`oiscl_track_nonce`**, not by user capabilities — visitors without accounts still record metrics. See `class-oiscl-metrics-ajax.php` — `handle_track_click`.

## Practical roles

| Persona | Suggested caps |
|---------|----------------|
| **Stakeholder / client read-only** | `view_ois_analytics` only (e.g. custom role or **`ois_client`**). Can use UTM Manager reports and exports; cannot open Suite Settings or raw log AJAX. |
| **Marketer with dashboard builder** | `view_ois_analytics` + **`manage_ois_marketing`** (matches default Editor grant). Still **cannot** change UTM saved links without **`manage_options`**. |
| **Site admin / implementor** | **`manage_options`** (Administrator): full Settings, UTM configuration, diagnostics. |

## Related docs

- `utm-manager-roadmap.md`
- `utm-manager-qa-checklist.md` (permissions smoke)
- `utm-manager-funnel-semantics.md`
