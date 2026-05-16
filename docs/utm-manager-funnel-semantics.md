# OIS UTM Manager — Funnel semantics (Steps 1–3)

Internal reference aligned with **UTM Funnel** in wp-admin (`oiscl_render_utm_funnel_guidance_panel`) and the session logic in `oiscl_utm_analyze_funnel_sessions`. If PHP behaviour changes, update this file and the admin notice copy together.

## Scope

- **Grain:** One **session** (`session_id`) counted at most once per funnel slice.
- **Window:** `DATE(created_at)` between the dashboard **start** and **end** dates (inclusive).
- **Filters:** Dashboard **`utm_filter`** is applied via the same SQL fragments as the rest of UTM Manager (`$filter_sql_stats`).
- **Data:** `oiscl_block_metrics` (pageviews, block views, clicks). UTM persistence must already store **`utm_campaign`** on rows in range.

## Step 1 — Landing

**Definition:** Sessions that have at least one **`[Pageview]`** row (`OISCL_Plan::EVENT_PAGEVIEW`) with **non-empty `utm_campaign`**, inside the date window and filters.

**Query shape:** Sessions are grouped from metrics; each row includes `pv_at` = first qualifying pageview time in range. Step 1 count = number of such sessions included in the funnel query result set.

**Tables:**

- **Global UTM funnel:** Any non-empty `utm_campaign` (including campaigns **not** saved under Settings). Implemented via `oiscl_utm_fetch_funnel_session_rows_any_utm`.
- **Funnel by company / by campaign link:** Only campaigns that appear in **saved references**, resolved against live metrics (`oiscl_utm_resolve_funnel_campaigns_from_metrics`). Uses `oiscl_utm_fetch_funnel_session_rows` with optional `utm_term` constraint per saved link.

## Step 2 — Block view

**Definition:** Same session as Step 1 must also have a **tracked block view** timestamp **`block_at`** **strictly after** `pv_at`.

**Anchors counted as block view:** `[Vista de Bloque]` and `[Bloque]` (`OISCL_Plan::EVENT_BLOCK_VIEW`, `EVENT_BLOCK_LEGACY`), matching `OISCL_Plan::sql_block_view_anchor_in()` — the same signals used for block dwell / Click Tracker maps.

**If Step 1 > 0 and Step 2 = 0 everywhere:** Landings recorded UTM + pageview, but **no block view fired** after that pageview on tracked sections/blocks. Confirm Click Tracker configuration on the **landing template** (blocks wired to emit block-view beacons). The UTM Funnel tab shows an inline notice for this pattern.

## Step 3 — Conversion click

**Definition:** After `block_at`, the session trail must include at least one **interaction hit** that is **not** treated as a system/dwell row.

**Excluded anchors (never count as conversion):** `[Pageview]`, `[Vista de Bloque]`, `[Bloque]`, `Reading`, `[Error 404]` — see `$system_clicks` in `oiscl_utm_analyze_funnel_sessions`.

**Conversion label (`conv_anchor`) — where it applies:**

- **Global UTM funnel** card and **Funnel by company:** `oiscl_utm_analyze_funnel_sessions` is called with an **empty** conversion label. Step 3 = **any** non-system interaction after `block_at`.
- **Funnel by campaign link:** If **Conversion click label** is set on that saved reference, Step 3 only counts when a post-block hit matches that text on **anchor**, **context**, or **destination** (`oiscl_utm_hit_matches_conv`). If the label is empty, **any** non-system interaction after the block counts (same rule as the global card).

## Order and sequencing

Steps are **sequential**, not independent totals:

1. Every session in the funnel set counts toward **Step 1**.
2. **Step 2** only increments if `block_at` exists and `block_at >= pv_at` (same session).
3. **Step 3** only increments if Step 2 would have counted for that session **and** a qualifying hit appears **after** `block_at` in chronological trail order.

Percentages shown in the UI (e.g. Step 2→3, Overall 1→3) use these three counts as documented next to the funnel bars.

## CSV exports

Funnel CSV downloads honor the same date range and **`utm_filter`**. Scopes (`funnel_scope`): `global`, `company`, `campaign`, `both`, `complete` — see `OISCL_Utm_Query_Helper::funnel_csv_sections` and `utm-manager-qa-checklist.md`.

## Related files (maintainers)

| Area | PHP |
|------|-----|
| Guidance copy | `OISCL_Admin_Utm_Trait::oiscl_render_utm_funnel_guidance_panel` |
| Session fetch | `oiscl_utm_fetch_funnel_session_rows`, `oiscl_utm_fetch_funnel_session_rows_any_utm` |
| Step counts | `oiscl_utm_analyze_funnel_sessions` |
| Company / link rollups | `oiscl_utm_analyze_company_funnel`, `oiscl_utm_analyze_link_funnel` |
| CSV | `oiscl_export_utm_funnel_csv` |

See also `utm-manager-business-map.md` and `utm-manager-roadmap.md`.
