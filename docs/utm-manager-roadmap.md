# OIS UTM Manager — Roadmap

Central reference for **scope**, **release criteria**, and **backlog**. Product context: **OIS Conversion Suite** (formerly Conversion Lab); day-to-day navigation stays in `utm-manager-business-map.md`, QA sign-off in `utm-manager-qa-checklist.md`.

---

## Phase 1 — Shipped surface (maintenance mode)

**Goal:** Production-ready UTM intelligence in wp-admin with documented QA.

**In scope (screens & flows):**

- Overview, UTM Content & CRO, UTM Funnel (+ CSV exports), UTM Click Tracker, UTM Audience (+ CSV), UTM User Journey (+ attribution + CSV).
- Settings → **UTM Manager**: saved links, labels, conversion targets, **Campaign alerts** (cron + optional email + admin notices on `oiscl-utm-tracker`, `oiscl-settings`, `oiscl-intro`).
- Filters (`utm_filter`), date navigation, staging diagnostics (`OISCL_UTM_DIAG`).

**Exit criteria:** Phase 1 checklist in `utm-manager-qa-checklist.md` completed on staging (or production mirror) and product owner sign-off on funnel step definitions (Steps 1–3 per admin guidance).

**Tests / CI today:** `composer test` (PHPUnit: `UtmQueryHelperTest` and related); `.github/workflows/phpunit.yml` on PHP 8.1–8.3 for `main` / `master` / `develop` and PRs.

---

## Phase 2 — Hardening & clarity (next)

Prioritize in order when bandwidth allows.

| Track | Item | Notes |
|-------|------|--------|
| **Docs** | Funnel semantics one-pager | **Done** — `utm-manager-funnel-semantics.md` (keep in sync with funnel PHP). Global funnel UI note corrected for Step 3 vs per-link conversion labels. |
| **Docs** | Roles & capabilities | Short matrix: which screens need `view_ois_analytics` vs `manage_options`; where Campaign alerts notices vs settings save apply. |
| **Tests** | PHPUnit beyond `Utm_Query_Helper` | Prefer extracting **pure helpers** (date windows, threshold math, CSV flags already covered) and testing without a full WordPress DB where possible. |
| **Tests** | Alerts logic | Unit-test alert rules (drop % vs prior window, zero-traffic window) with injected/stubbed metrics rows if logic is refactored into testable methods. |
| **Maintenance** | Admin PHP cleanup | Review legacy comments / dead paths in `trait-oiscl-admin-utm.php` (e.g. old handler markers); no behaviour change without QA pass. |

---

## Phase 3 — Icebox (ideas, not committed)

Only pick up after Phase 2 priorities or explicit product ask.

- Alternative alert channels (Slack, webhook) or digest scheduling.
- Deeper journey/funnel SQL regression suite (fixtures or wp-env–style integration tests).
- UX polish: empty states, loading states, unified export UX across tabs.

---

## How to use this file

1. **Planning:** Treat Phase 2 rows as the default backlog order unless business overrides.
2. **Releases:** Patch/minor version bumps stay aligned with `CHANGELOG.md`; UTM-specific QA still runs from `utm-manager-qa-checklist.md`.
3. **Updates:** When a Phase 2 row ships, move it to Phase 1 narrative or delete the row and mention it in the changelog.
