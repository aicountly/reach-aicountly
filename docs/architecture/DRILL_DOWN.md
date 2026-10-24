# Drill-down across Reach

**Rule:** every number Reach shows leads to the records behind it. A KPI tile,
a chart bar or slice, a grouped table row, a count in a badge — clicking it
narrows to what was counted, and each step can be narrowed again until the
viewer reaches a single meaningful record (a lead, a post, a job, a Search
Console row) and its detail page.

## How a drill step works

1. **Filters live in the URL.** A drilled view is a route plus query params
   (`/leads?status=failed&source=blog`). It survives reload, can be shared,
   and the browser's Back button walks back up the path. Never keep a filter
   that a drill link sets only in component state.
2. **The destination applies every param it is linked with.** A link that
   lands on an unfiltered list looks like the count was wrong. Each list page
   reads its filters with `useSearchParams` (or `useDrillFilters`) and passes
   them to its API call; the API supports the same param names.
3. **The trail is visible.** Drilled lists show the active filters as
   removable chips (`DrillPath`, or the existing `badge … ✕` chips) so the
   viewer always sees why the list is short and can step back.
4. **Counts and lists come from the same scope.** A tile and the list it
   opens must use the same filter on the server so the numbers match.
5. **Zero is not a link.** Don't offer a drill into an empty set.

## The kit (`web/src/components/drill`, `web/src/utils/drill.js`)

| Piece | Use |
|---|---|
| `drillHref(path, params)` | Build a link: `drillHref(ROUTES.LEADS, { status: 'failed' })`. Empty values are dropped. |
| `useDrillFilters(keys)` | URL-backed filter state for an in-page drill: `filters`, `steps` (in the order chosen), `drill(patch)` (push — Back returns), `set(k, v)` / `set({…})` (replace, for typed inputs), `upTo(i)`, `clear(k)`. |
| `useUrlFilter(key, fallback?)` | Drop-in for `useState` on a list filter: `const [status, setStatus] = useUrlFilter('status')`. Reads the param, writes it back (replace), drops it at the fallback. |
| `<DrillPath drill={…} rootLabel labels format />` | The breadcrumb trail of an in-page drill; each step goes back to that level, ✕ drops one step. |
| `<DrillLink to=… / onClick=…>` | A value that drills: dotted underline, keyboard reachable. Plain text when no target. |
| `DataTable` `columns[i].drill(row)` | Make a cell drill (`'/path?x=y'`, `{ to }`, `{ onClick }`, or `null`). `onRowClick` rows are now focusable and open on Enter. |
| `KPICard` `to` / `onClick` | Card becomes a link to its records ("View records ›"). |
| `button.stat-tile` / `a.stat-tile` | A `.stat-tile` rendered as a button or link gets hover/focus styling. |
| `BarChart` / `PieChart` / `LineChart` `onSelect(row, i)` | Bars, slices + legend rows, and line steps become drill targets (click / Enter). |

CSS: `.drill-link`, `.drill-path`, `tr.is-drillable`, `th.is-sortable`,
`.kpi-card-link` in `web/src/index.css`.

## Two shapes of drill-down

- **Cross-page:** an aggregate links to an existing list page with filters
  (dashboard tile → `/admin/jobs?status=failed`). Row → detail page.
- **In-page explorer:** for analytics without a natural list page, the page
  regroups the same data by the next dimension. Reference implementation:
  Search Console (`pages/intelligence/SiteWideSearchPanel.jsx` →
  `SearchExplorer.jsx`, API `GET v1/intelligence/search/site/explore`):

  `day → queries that day → pages for one query → devices → countries →
  the single Search Console row`, with sortable columns, a contains-filter,
  paging, and actions at the leaf (open the page, search Google for the query).

## Checklist for a page

- [ ] Every KPI / stat tile with a count links to its records (or is clearly
      a non-count such as "Freshest day").
- [ ] Every chart has `onSelect` when its categories map to a filter.
- [ ] Grouped/summary rows drill; list rows open their detail page.
- [ ] The destination reads every linked param from the URL and the API
      honours it — add a test like `pages/__tests__/DashboardTileDeepLinks.test.jsx`.
- [ ] Active filters are visible and removable.

## Coverage (October 2026 rollout)

Every area follows the rules above; the notable chains:

| Area | From → to the record |
|---|---|
| Search Console | overview tile / day / position band / query / page → `SearchExplorer` (day → queries → pages → device → country) → single row; open the page or search Google |
| Command centre & dashboard | tiles, bars, quotas, roadmap items → filtered lists (`/content`, `/social/calendar`, `/approvals?area=`, `/admin/jobs?…`, `/distribution/orchestration?status=`) → detail |
| Bot plan | each plan metric (`metricRecordsHref` in `pages/bot/botPlan/model.js`) → the records that metric counts |
| Analytics & outcomes | outcome tiles and breakdown rows → `/leads/outcomes?campaign_id|source|seller_code|content_identity_id|stage|paid` → `/leads?lead_id=` |
| Leads | campaign, landing page, ad (`utm_*` + `created_from/to`), social inbox → `/leads?…` (no lead detail page; `lead_id` is the deepest level) |
| Blog Command Centre | each pipeline metric → the stages it summed; roadmap run → scored topics / decisions → `/content/:id` |
| Social | performance cells → `/social/calendar?status=&channel=`; inbox day tiles filter in place |
| AI | dashboard tiles, budgets, usage rows → `/ai/generations?…` → `/ai/generations/:uuid` |
| Community & knowledge | status rows → questions/answers lists → workspace; product → claims / brand rules; source → citations |
| Distribution, video, SEO, readiness | counts → dispatches, suppressions (`channel`, `reason`), pipeline (`status`, `format`), SEO tracker `?show=`, findings (`severity`, `resolution_status`), failing jobs |

| Leads (detail) | every lead reference → `/leads/:id`: contact, source, push attempts, outcomes, attribution touchpoints, inbox origin, audit trail |
| Attribution | total / attributed / unattributed / channel bars → `/intelligence/attribution/conversions?…` → lead |
| GA4 traffic | KPI cards, trend points, channel slices, top pages, lead events → `/analytics/traffic` (day → channel → source → page → device → country → event) → single GA4 report row |
| Analytics records | legacy blog tiles → `/analytics/blog-posts?bucket=`; engagement → `/analytics/snapshots` |
| SEO records | backlinks → `/seo-centre/backlinks/:id`; sitemap → `/seo-centre/sitemap/:id/entries`; IndexNow → `/seo-centre/indexnow/submissions` → attempts; indexing → `/seo-centre/indexing` |
| AI visibility | every overview figure → runs → `/intelligence/visibility/runs/:id` (answer, observations, citations); competitors → `/intelligence/competitors/:id` |
| Ads | tile → campaigns by figure → `/social/ads/:id` → day results row → leads |
| Readiness & ops | backlog → `/readiness/recommendations/:id`; workflows; migrations; jobs → `/admin/jobs/:id`; video → `/video/pipeline/:uuid`; work blocks → `/blog-command-centre/operations/work-blocks` |
| Content performance | rows → `/intelligence/content/:id` → day × page → Search Console explorer |

Remaining limits are about missing source data, not missing links: external
backlinks / referring domains (no provider stores them), GA4 per-content
metrics (the GA4 ingest is a stub; content performance uses Search Console),
AI cost (the orchestrator records 0, so budgets show nothing to drill), job
attempt history before the latest error, and per-step video timestamps.
Where a count and its list cannot share a predicate the link says so in its
title (dispatch bounces → per-channel suppressions; command-centre revenue →
all outcomes).
