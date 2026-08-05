# Claude Code Routines — blog, knowledge base and base revision

The Claude route runs as **cloud Routines** (Claude Code on the web →
Routines). Each firing starts a fresh session in the environment that has
`aicountly/reach-aicountly` and `aicountly/aicountly-com` connected. Cloud
Routines fire at most **hourly** — the requested every-30-minutes cadence is
not possible, so the blog routine writes N blogs per hourly run
(superadmin decision 2026-08-05: start with 1/run ≈ 12/day and ramp via the
`BLOGS_PER_RUN` number inside the prompt).

## Environment prerequisites (one-time)

Add these **environment variables** to the Claude Code cloud environment
(Environment → Settings → Environment variables) — they must match the
values in the Reach server `.env` on cPanel:

```
REACH_API_BASE=https://reach.aicountly.org/api
REACH_AUTOMATION_TOKEN=<same 32+ char token as server .env>
```

All calls authenticate with the header `X-Automation-Token: $REACH_AUTOMATION_TOKEN`.

Endpoints the routines use:

| Endpoint | Purpose |
|---|---|
| `GET  $REACH_API_BASE/v1/automation/content-base` | strategy base + upcoming blog index |
| `GET  $REACH_API_BASE/v1/automation/gallery/status` | cover deficit + per-entry prompts |
| `GET  $REACH_API_BASE/v1/automation/link-registry` | linkable URLs for internal links |
| `GET  $REACH_API_BASE/v1/automation/kb-plan` | today's per-product KB quotas |
| `POST $REACH_API_BASE/v1/automation/blog-drafts` | submit a verified blog draft |
| `POST $REACH_API_BASE/v1/automation/kb-drafts` | submit a verified KB draft |

## Routine 1 — hourly marketing blog (~12/day)

Ask Claude (in a session bound to the environment):

> Create a Routine named `reach-blog-hourly` with cron `30 3-14 * * *`
> (UTC = 09:00–20:00 IST hourly), fresh session per fire, with this prompt:

```
You are the AICOUNTLY blog routine. Generate exactly 1 marketing blog
(BLOGS_PER_RUN=1) this run.

1. GET $REACH_API_BASE/v1/automation/content-base with header
   X-Automation-Token: $REACH_AUTOMATION_TOKEN. Pick the first index entry
   with status "planned" whose key you have not already submitted (a
   submission for an existing content_base_key returns already_ingested —
   then take the next entry). If none remain, stop and report.
2. GET .../v1/automation/link-registry and pick 2-4 relevant internal URLs.
3. Write the article yourself (Claude Opus): follow the entry's
   brief_prompt and the strategy rules in base_markdown; 900-1800 words;
   clean HTML (h2/h3/p/ul/ol/table only); weave the internal links in
   naturally; every factual claim must be verifiable against current Indian
   law/authority sources.
4. Verification (two-model rule): spawn a Sonnet subagent with the draft and
   instruction "Adversarially fact-check every claim; return JSON
   {claims:[{claim,verdict,reason}], pass_rate, corrections}". Apply its
   corrections, re-run it once if pass_rate < 0.95. Do not submit below 0.95.
5. GET .../v1/automation/gallery/status — note the cover prompt for your
   entry (covers are assigned server-side from the gallery; nothing to
   upload here).
6. POST .../v1/automation/blog-drafts with JSON:
   {title, slug_hint, body_html, body_markdown, summary,
    seo:{slug, meta_title, meta_description}, category, tags,
    portfolio_stream, funnel_stage, content_base_key,
    verification_report:{model:"claude-sonnet", claims, pass_rate},
    provenance:{generated_by_model:"claude-opus", reviewed_by_model:
    "claude-sonnet", routine_run_id:"<session id>"}}
7. Report the API response (content_item_id, slug) in one line.
Never invent facts, never submit without the verification report, and stop
after BLOGS_PER_RUN successful submissions.
```

## Routine 2 — knowledge base (twice daily, ~23 articles/day)

> Create a Routine named `reach-kb-daily` with cron `30 4,10 * * *`
> (UTC = 10:00 & 16:00 IST), fresh session per fire, with this prompt:

```
You are the AICOUNTLY knowledge-base routine (Claude-only route; no
third-party AI is permitted for KB content).

1. GET $REACH_API_BASE/v1/automation/kb-plan with header
   X-Automation-Token: $REACH_AUTOMATION_TOKEN.
2. For each product with remaining_today > 0, take up to remaining_today
   pending_topics (work products in the listed order). For each topic:
   read the product's actual repository (it is available in this
   environment or via add_repo) to describe the REAL current functionality
   — menus, flows, validations. Never describe features you cannot see in
   the code.
3. Write the article (Opus): getting_started/how_to structure with
   prerequisites[], steps[] (each step an object {text}), troubleshooting[];
   400-900 words of body_html; plain, second-person instructions.
4. Verify with a Sonnet subagent (same two-model rule as blogs): it must
   check every instruction against the product code. Fix findings.
5. POST .../v1/automation/kb-drafts with JSON:
   {title, product_slug, article_type, content_base_key, body_html, summary,
    seo:{slug, meta_title, meta_description}, prerequisites, steps,
    troubleshooting, difficulty_level,
    verification_report:{model:"claude-sonnet", claims:[], pass_rate:1},
    provenance:{generated_by_model:"claude-opus",
    reviewed_by_model:"claude-sonnet"}}
6. Stop when every product's remaining_today is 0 or pending topics run out.
   Report a per-product summary table.
```

## Routine 3 — daily base revision (the "how is daily revision possible" answer)

> Create a Routine named `reach-base-revision` with cron `30 2 * * *`
> (UTC = 08:00 IST daily), fresh session per fire, with this prompt:

```
You are the AICOUNTLY content-base curator. Repos reach-aicountly and
aicountly-com are in this environment; product repos can be attached with
add_repo when needed.

1. In reach-aicountly, read server-php/content-base/** (blog base + index,
   knowledge-base index, community question seeds).
2. Mark entries whose content was published (check
   GET $REACH_API_BASE/v1/automation/content-base sync states) as
   status:"published". Keep the blog index topped up to at least 8
   planned entries with target dates over the next 4 days: derive new
   topics from the strategy in base.md, seasonal Indian compliance
   deadlines, and CHANGES in the product repos since yesterday (git log).
   Each new entry needs key, title, slug_hint, portfolio_stream,
   product_slug, funnel_stage, search_intent, seed_keyword, target_date,
   brief_prompt (2-4 sentences), cover_prompt (one visual sentence, no
   text-in-image).
3. Similarly add KB topics for genuinely new/changed product functionality
   and 2-3 fresh community question seeds per week.
4. Commit to main with message "content-base: daily revision <date>" and
   push. The next deploy rsyncs it; reach:content-base-sync ingests it
   post-deploy and daily at 06:00.
Never delete published entries; retire outdated ones with status:"retired".
```

## Volume ramp

Raise `BLOGS_PER_RUN` in Routine 1's prompt (2 → ~24/day, 4 → ~48/day) only
after Search Console shows the current volume being indexed and gaining
impressions — Google's scaled-content-abuse policy is the constraint, not
the pipeline.

## Server-side counterpart

The API route (OpenAI ⇄ Gemini + Perplexity) is entirely cron-driven on
cPanel — see `docs/operations/REACH_WORKER_AND_CRON.md`. The routines above
only ever call the `v1/automation/*` endpoints; they never write to the
database or cPanel directly.
