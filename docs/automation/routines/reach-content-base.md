# Routine: `reach-content-base` — daily content-base curator

| | |
|---|---|
| **Name** | `reach-content-base` |
| **Model** | Claude Sonnet |
| **Cron (UTC)** | `30 2 * * *` — 02:30 UTC |
| **Cron (IST)** | 08:00 IST daily, before the first blog / KB runs |
| **Session** | fresh session per fire |
| **Repos in the environment** | `aicountly/reach-aicountly`, `aicountly/aicountly-com`; attach the product repos with `add_repo`: `aicountly/books-react-app` (Smart Books), `aicountly/billing-aicountly` (Billing), `aicountly/Inventory-aicountly` (Inventory), `aicountly/notes-aicountly` (Notes), `aicountly/drive-react-app` (Drive), `aicountly/manage-aicountly` (Manage) |
| **Environment** | network access to `reach.aicountly.org` + the Reach network secret (see `../CLAUDE_ROUTINES.md`) — no environment variables needed |
| **Replaces** | `reach-base-revision` (it edited the JSON files and pushed to `main`; delete that routine if it exists) |

The content base is **in Reach's database**, not in git. This routine never edits a file, never commits
and never pushes. It keeps the queue of topics the blog, KB and community routines write from full and
current, by proposing new topics through the API from what really changed in the products, and by
retiring seeds that no longer match the product.

## Endpoints used

All calls: no auth header of your own — the environment's network secret adds `Authorization: Bearer <token>` to every reach.aicountly.org/api/v1/automation/ request.

| Endpoint | Purpose |
|---|---|
| `GET  https://reach.aicountly.org/api/v1/automation/content-base` | strategy base (`base_markdown`), topic counts per type (`living`), last proposal per type, routine activity |
| `GET  https://reach.aicountly.org/api/v1/automation/content-base/next?type=blog\|kb\|community&limit=50` | the planned topics in queue order (what the writers will take next) |
| `POST https://reach.aicountly.org/api/v1/automation/content-base/propose` | add up to 10 topics per call: `{type, items:[{key, title, brief, product_slug?, category?, target_date?, evidence:[…], …}], routine_run_id}` |
| `POST https://reach.aicountly.org/api/v1/automation/content-base/produced` | retire a planned topic that does not match the product: `{type, key, outcome:"not_applicable", reason, routine_run_id}` |

Responses: `propose` → `201` when at least one topic was accepted, `200` otherwise; each item's
`results[].status` is `accepted`, `exists` (key used), `duplicate` (same title as a topic or a
published article), `invalid` (reason says what to fix) or `limit_reached` (today's cap,
`automation.content_base.propose_daily_limit`, default 20 per type). `produced` → `200`, `404`
unknown key, `409` not planned, `422` missing reason.

## Prompt

```
You are the Aicountly content-base curator. Once a day you keep the queue of topics that
the blog, knowledge-base (KB) and community routines write from full and true to the
products as they are today. You work only through Reach's API. You never edit files,
never commit and never push to any repository.

Every call goes to https://reach.aicountly.org/api — no auth header of your own — the environment's network secret adds `Authorization: Bearer <token>` to every reach.aicountly.org/api/v1/automation/ request.

1. GET /v1/automation/content-base. Read base_markdown (the strategy), living (counts per
   type and last_proposal_at) and routines (which writers are active).
2. GET /v1/automation/content-base/next?type=blog&limit=50, then type=kb and
   type=community. These are the topics still planned.
3. Find out what really changed. For each launch app (Smart Books = books-react-app,
   Billing = billing-aicountly, Inventory = Inventory-aicountly, Notes = notes-aicountly,
   Drive = drive-react-app, Manage = manage-aicountly): attach the repo with add_repo
   (owner "aicountly"), clone it, find the default branch (git remote show origin) and run
   git log --no-merges --since=<living.knowledge_base.last_proposal_at, or 7 days ago>
   origin/<branch>. Read the diffs of anything user-facing (routes, pages, menus, labels,
   forms, reports, settings). Only a change you can see in the code counts. Never invent a
   feature, screen, label, price, integration or date.
4. Retire planned KB topics that do not match the product: for each planned KB topic of a
   launch app, check its feature exists in the current code. If it clearly does not (no
   route, page or component for it on the default branch), POST
   /v1/automation/content-base/produced {"type":"kb","key":"<key>","outcome":"not_applicable",
   "reason":"<what you looked for and where>","routine_run_id":"<this session id>"}.
   When unsure, leave it.
5. Propose new topics (POST /v1/automation/content-base/propose, at most 10 items per call):
   a) type "kb": one topic per genuinely new or changed user task you found in step 3 that
      no planned topic or published article already covers. key "kb-<product_slug>-<task>",
      title "How to <task> in <App>", brief (2–4 sentences: the task, the screens involved,
      what changed and when), product_slug (books, billing, inventory, notes, drive, manage),
      category = article type (how_to | troubleshooting | concept | configuration), and
      evidence: [{"repo":"aicountly/<repo>","commits":["<sha7>",…],"file":"<path>","note":"<what it shows>"}].
   b) type "blog": keep at least 8 planned blog topics. Add timely ones from base_markdown's
      strategy: Indian compliance deadlines in the next 2–4 weeks (GST, TDS/TCS, advance tax,
      PF/ESI, ITR, MCA), and real product changes that solve a common problem. key
      "blog-<YYYYMMDD>-<slug>", title, brief, portfolio_stream (marketing | product |
      problem_to_product), funnel_stage, search_intent, seed_keyword, target_date
      (YYYY-MM-DD), cover_prompt (one visual sentence, no text in the image), evidence
      (the repo/commits for product topics, or {"url":"https://<official source>","note":…}
      for compliance topics — check dates against the official portal).
   c) type "community": keep at least 5 planned questions. Add 2–3 a week that small
      businesses and accountants in India really ask (plain words, one question each):
      key "faq-<slug>", title = the question, brief = the context, category (gst,
      income-tax, tds-tcs, payroll-hr, company-law, accounting, audit, saas-product-help),
      product_slug when it is about an app, evidence = an official source url (or the repo
      for product questions).
   Skip anything that duplicates a planned topic or an existing title. Read each result:
   fix "invalid" items once; "exists"/"duplicate" → move on; "limit_reached" → stop
   proposing that type today.
6. Report a table: type | key | title | result, then one line per type: planned before →
   after, and which products had user-facing changes.
```

## Operator notes

- Nothing here needs a deploy. The git files under `server-php/content-base/` are only the seed
  baseline; Reach imports them hourly (`reach.content_base_sync`) and never resets a topic's progress.
- The Content Base page (Quality Centre → Content Base) shows each topic's origin (git seed / routine),
  its status and when each routine last reached Reach.
