# Claude Code Routines — blog, knowledge base and base revision

The Claude route runs as **cloud Routines** (Claude Code on the web →
Routines), all on **Claude Sonnet**. Each firing starts a fresh session in the
environment that has `aicountly/reach-aicountly` and `aicountly/aicountly-com`
connected; product repositories are attached with `add_repo` when an article
needs them. Cloud Routines fire at most **hourly**.

The routines only **write**. Reach decides: every submission is vetted by
**Gemini** (Settings → AI & Automation picks the model; its decision is final,
no human review), approved under the login-disabled *Reach System Bot*, given
a DALL·E cover (blogs) and published to aicountly.com. The old two-model rule
(Opus writes, a Sonnet subagent fact-checks, Reach requires a
`verification_report` from a different model) is retired: a
`verification_report` is still accepted and stored if a routine sends one, but
the Gemini vetting run is the verification of record.

| Routine | Prompt file | Cron (UTC) | IST | Volume |
|---|---|---|---|---|
| `reach-blog` | [`routines/reach-blog.md`](routines/reach-blog.md) | `30 3-14 * * *` | hourly 09:00–20:00 | 1 per run, stops at `automation.blog.daily_limit` (6/day) |
| `reach-kb` | [`routines/reach-kb.md`](routines/reach-kb.md) | `30 4,10 * * *` | 10:00 and 16:00 | up to 5 per run → `automation.knowledge_base.daily_limit` (10/day) |
| `reach-community-qa` | [`routines/reach-community-qa.md`](routines/reach-community-qa.md) | `30 2-14/2 * * *` | every 2 h 08:00–20:00 | member questions first, then up to 3 FAQs per run → `automation.community.daily_limit` (10/day) |
| `reach-release-notes` | [`routines/reach-release-notes.md`](routines/reach-release-notes.md) | `30 15 * * *` | 21:00 | one note per product with user-visible changes since its last note |
| `reach-workflow-guides` | [`routines/reach-workflow-guides.md`](routines/reach-workflow-guides.md) | `30 0 * * 1` | Monday 06:00 | refreshes / adds launch-app workflow guides |
| `reach-growth-strategy` | [`routines/reach-growth-strategy.md`](routines/reach-growth-strategy.md) | `30 12 * * 0` | Sunday 18:00 | weekly strategy memo from AI Insights + AI visibility |
| `reach-base-revision` | below | `30 2 * * *` | 08:00 | optional — topic supply no longer depends on it |

To install a routine, open a session bound to the environment and ask Claude to
"Create a Routine named `<name>` with cron `<cron>`, fresh session per fire,
model Sonnet, with the prompt in `docs/automation/routines/<name>.md`".

## Environment prerequisites (one-time)

In the Claude Code cloud environment the routines run in (environment menu → Edit):

1. **Network access → Allowed domains:** `reach.aicountly.org` (plus `www.aicountly.com`, `aicountly.com`
   if routines should check live pages). Keep "Allow package managers" ticked.
2. **Network secrets → Add secret:** type Bearer, allowed website `reach.aicountly.org`, path prefix
   `/api/v1/automation/`, header `Authorization`, prefix `Bearer`, value = the server's
   `REACH_AUTOMATION_TOKEN` (32+ chars). The environment's proxy adds the header to every request to
   those paths, so routines never see or send the token themselves.

The API base is `https://reach.aicountly.org/api`. Reach's `AutomationTokenFilter` accepts the token as
`Authorization: Bearer <token>` or as `X-Automation-Token: <token>`.

Endpoints the routines use:

| Endpoint | Purpose |
|---|---|
| `GET  https://reach.aicountly.org/api/v1/automation/blog-plan` | blog quota left today (`stop`), last 200 titles/slugs for de-duplication, priority topics (launch apps; Indian compliance calendar for the next 30 days), internal links |
| `GET  https://reach.aicountly.org/api/v1/automation/kb-plan` | KB quota, per-product cadence (launch apps first), topic seeds, existing articles |
| `GET  https://reach.aicountly.org/api/v1/automation/link-registry` | linkable URLs for internal links |
| `GET  https://reach.aicountly.org/api/v1/automation/content-base` | strategy base + legacy blog index (optional) |
| `GET  https://reach.aicountly.org/api/v1/automation/gallery/status` | cover-gallery deficit (informational) |
| `POST https://reach.aicountly.org/api/v1/automation/blog-drafts` | submit a blog draft |
| `POST https://reach.aicountly.org/api/v1/automation/kb-drafts` | submit a KB draft |
| `GET  https://reach.aicountly.org/api/v1/automation/community-plan` | community quota, open questions, recent titles, topic mix |
| `POST https://reach.aicountly.org/api/v1/automation/community-qa` | submit a desk FAQ (question + answer) for vetting and publishing |
| `POST https://reach.aicountly.org/api/v1/automation/community-answers` | submit the desk's answer to an open member question |
| `GET  https://reach.aicountly.org/api/v1/automation/release-plan` | per product: repo, last published version + git ref, next versions |
| `POST https://reach.aicountly.org/api/v1/automation/release-notes` | submit a release note (Gemini-vetted, auto-published) |
| `GET  https://reach.aicountly.org/api/v1/automation/workflow-plan` | per launch app: published guides + last-updated dates |
| `POST https://reach.aicountly.org/api/v1/automation/workflow-guides` | submit a workflow guide (Gemini-vetted, auto-published) |
| `GET  https://reach.aicountly.org/api/v1/automation/insights-digest` | AI Insights + AI visibility digest for the strategy routine |
| `POST https://reach.aicountly.org/api/v1/automation/strategy-memos` | store the weekly strategy memo |

Responses the routines act on: **201** stored (and usually vetted already —
see `pipeline.status`), **200** `already_ingested` (content key reused),
**409** duplicate topic, **422** invalid draft, **429** today's quota is used
(stop), **403** automation switched off (stop).

## What Reach does with a submission

`App\Libraries\Automation\Routine\RoutineContentPipeline`, state in
`reach_routine_submissions`:

1. **Ingest** — validate, idempotency by `content_base_key`, duplicate-title
   check, daily quota (IST day; rejected drafts do not count).
2. **Vet** — `ContentVettingService` with the `blog` / `knowledge_base` rubric.
   `revise` is re-vetted exactly once; publish only on `approve`. A second
   `revise` or a `reject` retires the draft with Gemini's reasons. Gemini
   unavailable → the draft stays `pending_vetting` and is retried with backoff
   (5 min doubling to 6 h); unvetted content is never published.
3. **Approve** — under `SystemApprover`, bound to the checksum of the FINAL
   (possibly corrected) text; blog approval rows carry `vetting_run_id`. This
   replaces CROSS_REVIEW and HUMAN_REVIEW_GATE for routine items.
4. **Profiles** — SEO profile, publication profile with author
   `automation.blog.author_name` ("AICOUNTLY Editorial Team").
5. **Cover (blogs)** — a fresh DALL·E image (`ai.image.model`, prompt from
   `CoverPromptBuilder`: title, summary, category, the routine's
   `cover_prompt`; no text in the image) with alt text; the gallery only if
   generation fails. With neither, the article waits (`awaiting_cover`) and is
   retried hourly; it publishes coverless only when the readiness gate allows
   it (cover rule off, or an operator waived the `cover_image` validation).
   KB articles get no image: the aicountly.com KB receiver
   (`syncKnowledgeBaseArticle`, `help-article.php`) has no image field and its
   sanitizer strips `<img>`.
6. **Publish** — `PublicationDeploymentService` (the normal publisher path and
   readiness gate), then **verify** the public copy's checksum and mark it
   live.

The scheduled sweep (`reach.routine_content_sweep`, enqueued by
`reach:schedule` every 5 minutes; `php spark reach:routine-sweep` by hand)
carries every stage forward. The Blog Command Centre → Verification →
**Routine Articles** and Publishing → **Knowledge Bases** pages show each
submission's decision, reasons, scores, cover and status, with **Retry**.

### High-risk content — the decision of record

Gemini's approval is final. The old "high/critical risk never auto-publishes"
ban is kept only where the vetting model itself says the content is risky: an
approved article with a compliance score below
`automation.vetting.min_compliance_score` (1–10 scale, default 5; 0 disables)
or a reason starting `HIGH_RISK:` is **held** (`held_high_risk`, item
`risk_level=high`, blog in the Verification Queue) for a person to approve or
reject. Reach's own heuristics do not override a Gemini approval; routine
items default to LOW risk.

### Switches (Settings → AI & Automation, group "Blog & KB routines")

| Setting | Default | Hard kill-switch (explicit `false` in `.env`) |
|---|---|---|
| `automation.blog.enabled` / `automation.knowledge_base.enabled` | on | — |
| `automation.blog.daily_limit` / `automation.knowledge_base.daily_limit` | 6 / 10 | — |
| `automation.blog.auto_publish` | on | `BLOG_AUTO_PUBLISH_ENABLED`, `BLOG_PUBLIC_PUBLISHER_ENABLED` |
| `automation.blog.ai_cover` | on | `BLOG_IMAGE_GENERATION_ENABLED` |
| `automation.knowledge_base.auto_publish` | on | `KB_AUTO_PUBLISH_ENABLED` |
| `automation.blog.author_name` | AICOUNTLY Editorial Team | — |
| `automation.vetting.min_compliance_score` | 5 | — |

An env variable that is unset (or `true`) defers to the setting. Older
`.env` files copied from `.env.example` contain
`BLOG_AUTO_PUBLISH_ENABLED=false` etc. — delete those lines for routine
content to publish. `KB_AUTO_APPROVE` is no longer read for routine items.

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
   GET https://reach.aicountly.org/api/v1/automation/content-base sync states) as
   status:"published". Optionally keep the blog index topped up with
   planned entries (the blog routine proposes its own topics, so this is a
   source of suggestions, not a dependency) with target dates over the next 4 days: derive new
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

Raise `automation.blog.daily_limit` (and, past 12/day, the articles per run in
`routines/reach-blog.md`) only after Search Console shows the current volume being indexed and gaining
impressions — Google's scaled-content-abuse policy is the constraint, not
the pipeline.

## Server-side counterpart

The API route (OpenAI ⇄ Gemini + Perplexity) is entirely cron-driven on
cPanel — see `docs/operations/REACH_WORKER_AND_CRON.md`. The routines above
only ever call the `v1/automation/*` endpoints; they never write to the
database or cPanel directly.
