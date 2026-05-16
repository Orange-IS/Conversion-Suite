# OIS UTM Manager — Business question → screen map

Quick reference: where to look in **wp-admin** when someone asks a day-to-day question about UTM performance.

| Business question | Where to go |
|-------------------|------------|
| How is overall UTM traffic trending vs last period? | **OIS UTM Manager** → **Overview** (header KPIs, charts). |
| Which landing URLs or blocks convert best for a campaign? | **UTM Content & CRO** (content/CRO tables for saved links). |
| Do visitors who land with UTM see our key blocks and then click “conversion” targets? | **UTM Funnel** (global card + **Funnel by company** / **Funnel by campaign link**). |
| I need funnel numbers in a spreadsheet for stakeholders. | **UTM Funnel** → **Export funnel** → CSV (**global funnel** row matching the overview card, **by company**, **by campaign link**, **both company+campaign**, or **complete report**: global + company table + campaign table). Uses current date range and `utm_filter`. |
| How many clicks and reads happened on tracked blocks for pages carrying UTMs? | **UTM Click Tracker** (Overview / Clicks / Reading Map). |
| What devices, geo, or UTM dimensions dominate traffic? | **UTM Audience** (charts + top lists; per-list CSV export). |
| What path did sessions take after landing with UTMs? | **UTM User Journey** (accordion sessions + attribution selector + CSV). |
| Why does funnel Step 2 show zero while Step 1 has traffic? | **UTM Funnel** guidance notice + confirm Click Tracker block views on the landing template; optional **`OISCL_UTM_DIAG`** (staging). |
| Configure saved links, labels, conversion click targets | **Settings** → **UTM Manager** tab. |
| Get warned when a campaign’s clicks collapse vs the prior window, or when traffic goes quiet after history | **Campaign alerts** under **Settings** → **UTM Manager** (daily cron stores results; optional **email** when enabled and alerts exist). Summaries also surface as **admin notices** on **OIS UTM Manager**, **Settings**, and the Conversion Lab intro screen when there are active alerts. |

For scripted QA steps (deploy, filters, exports), see `utm-manager-qa-checklist.md`. For phased backlog and engineering priorities, see `utm-manager-roadmap.md`. Precise definitions of funnel Steps 1–3 are in `utm-manager-funnel-semantics.md`. Roles and capabilities (`view_ois_analytics`, `manage_options`, etc.) are summarized in `utm-manager-roles-capabilities.md`.
