# Reach Blog Publishing Operations Guide

## Overview

This guide covers day-to-day operations for blog content publication, including readiness checks, publication triggering, monitoring deployments, and handling failures.

---

## Pre-Publication Checklist

Before triggering publication for a blog post:

1. **Approve the content** — Navigate to Approvals and verify the content version has `approved` status. Publication is blocked without approval.
2. **Run readiness check** — Go to Publishing → Readiness, enter the content ID, click "Check Readiness". All blockers must be resolved.
3. **Verify SEO profile** — Go to Publishing → SEO Editor for the content. Ensure:
   - `slug` is set (lowercase, hyphens only)
   - `meta_title` ≤ 70 characters
   - `meta_description` ≤ 165 characters
   - `primary_keyword` is set
   - `canonical_preference` is correct
4. **Check connections** — Go to Publishing → Connections. The active connection must show `healthy` status.

---

## Public listing quality

`aicountly.com/blogs` renders the payload's `excerpt`, `featured_image_url` and
`reading_time_minutes` on each card, so a placeholder draft that reaches the
site looks exactly like a real post ("Untitled draft........"). Three
safeguards keep that off the listing:

1. **Readiness gate** — `BlogReadinessService` blocks any item whose body is a
   stub, is under `BLOG_MIN_PUBLISH_WORDS` (default 300), or is mostly
   punctuation. It warns when no cover image is assigned.
2. **Payload guard** — `BlogPublicationPayloadBuilder` re-runs the same check on
   the sanitised body at build time. A failure throws, the deployment is marked
   failed, and nothing is sent to the public site. The excerpt falls back
   through profile excerpt → version summary → meta description → derived
   excerpt, skipping any candidate that is not real prose.
3. **Audit command** — for content that is already live. The guards above are
   preventive: they stop the *next* bad publish, and do nothing about a post
   already on the site. Use this to clean those up:

   ```bash
   php spark reach:blog-listing-audit                     # report only
   php spark reach:blog-listing-audit --unpublish --fix   # take down + re-draft
   php spark reach:blog-dispatch --force
   php spark reach:work --queue blog,publishing --limit 40
   ```

   The report flags placeholder bodies, unusable excerpts, missing covers and
   missing alt text per content item. `--unpublish` takes placeholder articles
   off the public site immediately (only placeholder bodies — a real article
   with a missing cover is not pulled down). `--fix` re-queues a genuine draft.

   Each finding reports its `takedown` outcome:

   | Value | Meaning |
   |---|---|
   | `unpublished` | Removed from the public site |
   | `no_active_deployment` | Not published through Reach's connector — remove it on aicountly.com directly |
   | `unpublish_rejected_by_public_site` | The site refused; check connection health |
   | `unpublish_failed: …` | Local error, e.g. the deployment has no `public_content_id` |

   **The rewritten article does not republish by itself.** Redrafting resets the
   item to `outline_ready` with `approval_status = pending`, so it re-enters the
   normal human approval gate. That gate is deliberate and this command does not
   bypass it — after the worker generates the new draft, approve it in Reach and
   publish as usual.

### Where cover binaries live

`MEDIA_STORAGE_PATH` must point at a directory **outside the document root** —
the same convention as `blog_uploads`:

```bash
mkdir -p /home/<cpanel-user>/cover_images
# then in public_html/api/.env:
MEDIA_STORAGE_PATH=/home/<cpanel-user>/cover_images
```

Deploys rsync `public_html/api/` with `--delete`. Covers used to live under
`writable/uploads/`, inside that tree, protected only by a filter rule that
matched the directory entry and not its contents — so every binary was deleted
on each release while its database row survived, and the gallery listed images
that could not be served. A path outside the document root is not reachable by
that command at all; the filter rules are now belt to that braces.

Existing rows keep their stored absolute path, so changing this setting does
not orphan anything that is still on disk. Rows whose file is already gone are
cleared with:

```bash
php spark reach:media-reconcile          # report
php spark reach:media-reconcile --fix    # retire them, then re-upload
```

A retired row leaves the rotation, which also stops the deficit report
counting covers that do not exist.

### Cover images (no human in the loop, since 18 Oct 2026)

Nobody makes, uploads or approves covers. Reach generates them with the
configured OpenAI GPT Image model (`ai.image.model`, default
`gpt-image-2.5-flare`; `ai.image.size` 1536x1024; `ai.image.quality`), puts the
official AICOUNTLY logo on them, keeps the Cover Gallery stocked, and decides
per article whether to reuse a gallery cover or draw a fresh one.

**Brand style.** Every prompt (`CoverPromptBuilder`) is a flat editorial vector
illustration in the green/white brand palette (AICOUNTLY greens `#8cc854`,
`#1b7f00`, navy `#071a33` accents), one focal concept, and *strictly no text, no
letters, no logos*. The model never draws the brand: `CoverBranding`
composites the real logo (aicountly.com `assests/images/ai-logo.png`, bundled at
`server-php/app/Assets/brand/aicountly-logo.png`) as a bottom-right corner mark
on a ≈96 % white rounded plate, 15 % of the image width, 2.2 % margin — legible
on dark and light art. The unbranded original is kept (`original_file_path`).
Without GD the cover is stored unbranded and logged; branding never costs a
cover. `covers.brand.logo_enabled` switches the mark off.

#### Reuse or generate — the rule for every new blog (routine and calendar)

`CoverSelectionService` (called by `RoutineCoverService` for Claude-routine
blogs and by the `GENERATE_IMAGE` work block for calendar/pipeline blogs):

1. **Reuse a relevant gallery cover.** Candidates are active
   `gallery_upload` and `ai_gallery` assets outside the reuse cooldown.
   `CoverRelevanceScorer` scores them against the article: a cover stocked for
   the article's own content-base entry (`source_key` = the item's
   `content_base_key`) +20, a category tag +6, each tag keyword shared with the
   title/category/tags +3, prompt keywords up to +3. The **topical** score must
   reach `CoverRelevanceScorer::minScore()` = 6 (a category tag, two shared tag
   keywords, or the entry key). A portfolio-stream match only orders relevant
   covers — it never makes one relevant (every routine blog is stream
   `marketing`; the old floor of 3 let a stream match alone attach any
   marketing image to any article). A never-used cover beats a used one; ties
   go to the least recently used. A legacy unbranded upload is branded on the
   way out.
2. **Otherwise generate a fresh cover** from the article itself: current title,
   summary and section headings (`## …` / `<h2>`, FAQ/conclusion dropped), plus
   the routine's `cover_prompt` scene note when it sent one. The published
   category is the theme — never the portfolio stream. The new cover is
   branded, stored as an `ai_gallery` asset tagged with the article's keywords
   and marked used, so a later article on the same topic can reuse it after the
   cooldown. Generation follows `automation.blog.ai_cover` (default on) with the
   `BLOG_IMAGE_GENERATION_ENABLED=false` kill switch; Claude-routine items also
   honour `BLOG_ROUTINE_AI_COVER_FALLBACK`. OpenAI GPT Image is the provider
   (Gemini image only when no OpenAI key is configured).
3. **Neither** → the article has no cover and says why (see below). Nothing is
   marked as having a cover it does not have; an unconfigured or failing image
   provider stores nothing.

`MEDIA_GALLERY_ALLOW_UNMATCHED_FALLBACK` ("any free cover") is retired and
ignored. `MEDIA_GALLERY_MIN_RELEVANCE` can raise the floor but not lower it
below 6.

#### Keeping the gallery stocked (hourly)

`reach.cover_gallery_autofill` (`blog` queue, key
`cover-gallery-autofill-<Y-m-d-H>`) reads the same deficit the console shows —
upcoming blog-index entries in the next `MEDIA_GALLERY_LOOKAHEAD_DAYS` (14)
versus active, matchable gallery covers — and generates covers for the entries
that have none, earliest target date first, from each entry's title, brief and
`cover_prompt`. Each is an active `ai_gallery` asset with `source_key` = the
entry key and the entry's keywords as tags; the article later written from that
entry finds it by key.

- **Cost:** at most `min(deficit, covers.auto.daily_limit − generated today, 5)`
  per run; `covers.auto.daily_limit` (default 10) also bounds
  `reach:covers-refresh`. A new article no gallery cover fits still gets its
  cover (counted, not blocked).
- **Idempotent per entry:** an entry with a stocked cover (any status) or whose
  article already has a cover is never generated again; a unique index on
  `(source_key) WHERE kind='ai_gallery'` backs this up.
- Stops at the first "not configured" answer and after two failures in a run.
- **Switches:** `covers.auto.enabled` (default on); `BLOG_IMAGE_GENERATION_ENABLED=false`
  in `.env` also stops it.
- Every paid attempt (success or failure) is a row in
  `reach_cover_generation_attempts` (`purpose` = `gallery_stock` / `article` /
  `refresh`) — what the cap, the daily report and the alert read.

#### Parked articles are released automatically

With `BLOG_REQUIRE_COVER_IMAGE=true` (the default) an article with no cover does
not walk on to publication: a failed `cover_image` validation is recorded with
the reason (`gallery_no_relevant_cover; …`, `no_image_provider_configured`,
`image_generation_disabled`, or the provider error), the item is parked, and
readiness gates 6 and 11b block every publish path.

Release is automatic. `reach.blog_cover_sweep` (hourly) re-runs the cover
block for parked calendar articles (one retry key per article per day,
**re-armed whenever a new gallery cover has landed since the last attempt**),
and the autofill runs the sweep right after it stocks covers. Claude-routine
blogs waiting for a cover retry hourly through `RoutineContentPipeline` with
the same reuse-or-generate rule. A waiver (Daily Approval Centre →
`POST /v1/approval-queue/:id/waive-validation`) still exists for an article
that should go out without a hero — but on aicountly.com such a post shows a
random picsum.photos stock image (see below), so prefer letting Reach generate.

#### What reaches a person

The old 09:00 email ("Cover-image gallery deficit: N image(s) needed … Prompts
to generate … Upload at: Reach console → Quality Centre → Cover Gallery") is
gone. Instead:

- the daily analytics report (08:00 IST) has a **Cover images** section:
  `Covers: N generated automatically (24h), gallery has M active, deficit D`
  (+ failed attempts and the last error when there were any);
- `reach.gallery_deficit_alert` (09:00 UTC, same job type) emails **only** when
  generation keeps failing — at least `covers.alerts.failure_threshold` (3)
  failures in 24 h with no recovery, or no OpenAI key while covers are needed —
  and says what failed. It is informational; Reach keeps retrying.
  `covers.alerts.enabled` turns it off; recipients are
  `covers.alerts.recipients` (env `BLOG_GALLERY_ALERT_EMAIL`, the old email's
  address), else `reports.daily.recipients`.

#### Replacing stock / logo-less covers on live posts

```bash
php spark reach:covers-refresh --dry-run            # list what would change, spend nothing
php spark reach:covers-refresh --limit=10           # regenerate + republish (cover only)
php spark reach:work --queue=publishing --once --limit=10
```

A live post (`published`, `verification_pending`, `live`) is refreshed when its
cover is missing, not a Reach media URL, points at a missing/retired asset, is
an operator upload or legacy image (provenance not `aicountly_generated`), or
has no logo. It gets a fresh, branded cover drawn from its own title, summary
and headings (never a gallery reuse), and is re-published through the normal
publisher with the **same content version** — only the cover changes. Bounded
by `covers.auto.daily_limit`; the rest is reported as deferred for the next run.
A republish the publisher refuses is reported as `regenerated_republish_refused`
with the reason — the live post keeps its old image until a publish succeeds.

#### Why aicountly.com showed unrelated photos

`aicountly-com/includes/blog/Helpers.php` → `blog_image_url()` returns
`https://picsum.photos/seed/<crc32(slug)>/800/450` (a random Lorem Picsum stock
photo — cyclists, raindrops, pineapples) whenever a post has no ingested hero,
and `blog/article.php` falls back to the same URL in the `<img onerror>`. A
post ends up with no hero when Reach sent no `featured_image_url`
(cover waived / not required) or when the receiver's `HeroImageFetcher` could
not download it (signed media URL 404 — `MEDIA_SIGNING_KEY` unset, the binary
deleted by an rsync `--delete`, or the asset retired). Reach now always sends a
branded cover; `reach:covers-refresh` repairs the live posts. The picsum
fallback itself lives in the aicountly-com repository and should be replaced
with a branded default there.

Tuning (optional `.env` overrides; see [REACH_ENV_REFERENCE.md](REACH_ENV_REFERENCE.md)):

| Variable | Default | Effect |
|---|---|---|
| `BLOG_REQUIRE_COVER_IMAGE` | `true` | No blog publishes without a cover; a miss parks the article until one is generated |
| `MEDIA_GALLERY_MIN_RELEVANCE` | `6` | Topical score floor for reusing a gallery cover (cannot go below 6) |
| `MEDIA_GALLERY_REUSE_COOLDOWN_DAYS` | `30` | Minimum gap before a cover is reused |
| `MEDIA_GALLERY_LOOKAHEAD_DAYS` | `14` | How far ahead the deficit / autofill looks |
| `BLOG_IMAGE_GENERATION_ENABLED` | unset | Only an explicit `false` matters: a kill switch for all cover generation (articles, autofill, refresh) |
| `BLOG_ROUTINE_AI_COVER_FALLBACK` | `true` | Claude-routine items: generate when no gallery cover is relevant |
| `BLOG_MIN_PUBLISH_WORDS` | `300` | Minimum publishable article length |

Settings (Settings → AI & Automation → Images): `covers.auto.enabled`,
`covers.auto.daily_limit`, `covers.brand.logo_enabled`, `covers.alerts.enabled`,
`covers.alerts.recipients`, `covers.alerts.failure_threshold`, plus
`automation.blog.ai_cover` under Blog & KB routines.

---

## Triggering Publication

Publication is triggered from the Blog Publishing list page:

1. Navigate to Publishing → Blogs.
2. Find the content item and click "Publish".
3. A `PublicationJob` is enqueued in `reach_jobs`.
4. The deployment status changes to `queued` → `sending` → `accepted` → `published`.
5. A verification job runs automatically after publication.

---

## Monitoring Deployments

### Deployment List

Navigate to Publishing → Deployments to view all deployment records. Filter by:
- Status: `queued`, `sending`, `published`, `verified`, `failed`, `rolled_back`
- Content type: blog

### Verification List

Navigate to Publishing → Verifications to view verification results. A `verified` result means the canonical URL is live and accessible.

### Alert Conditions

Monitor for:
- Deployments stuck in `sending` for more than 10 minutes
- Deployments in `failed` status
- Verifications in `failed` status
- Connections with `unhealthy` or `degraded` health status

---

## Handling Failures

### Retryable Failures

If a deployment shows `failed` with a retryable error (server_error, timeout, network_error), the retry service automatically re-queues the job with exponential backoff. No manual intervention needed for up to 5 attempts.

### Non-Retryable Failures

If the deployment fails with a non-retryable error (auth_error, validation_error), manual investigation is required:

1. Check `reach_content_deployments.error_category` for the error type.
2. For `auth_error`: verify `AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN` and `AICOUNTLY_PUBLIC_SITE_SIGNING_KEY` are correct.
3. For `validation_error`: check the content payload for missing required fields.
4. Re-queue manually after fixing the root cause.

### Connection Health Failure

If the connection shows `unhealthy`:

1. Navigate to Publishing → Connections.
2. Click "Check Health" to run an immediate health check.
3. Review `last_health_error` for the failure reason.
4. Verify aicountly.com `AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN` and `AICOUNTLY_PUBLIC_SITE_SIGNING_KEY` match Reach's configuration.

---

## Rollback

To unpublish a blog post:

1. Navigate to Publishing → Deployments.
2. Find the deployment record for the published content.
3. Click "Rollback".
4. Confirm the action. A rollback job is enqueued immediately.
5. The deployment status changes to `rolled_back`.
6. The public site sets the content to `unpublished`.

**Note:** Rollback does not delete content from the public site database; it marks it as unpublished and excludes it from the sitemap.

---

## Slug Changes

If a blog post's slug is changed after publication:

1. The `CanonicalUrlPolicy` detects the slug change and marks `requiresRedirect = true`.
2. A redirect record is created in `reach_content_redirects` (old slug → new slug).
3. The new canonical URL is used for the update deployment.
4. The public site (if configured) should serve a 301 redirect from the old URL.

## Blog categories and product_slug (taxonomy of 23 Oct 2026)

Every blog carries ONE canonical category slug (15, in three groups) and, when app-led, the
`product_slug` of the app it is about — `App\Libraries\Blog\BlogTaxonomy` is the source of
truth (aliases map every old value/label; products come from `ProductCatalog`, the copy of
aicountly.com's `saas_products()`). The payload to aicountly.com sends both; the routine is
refused (422) for an unknown category / product_slug. The table and the 50/50 app-led/topic
mix are in `docs/automation/routines/reach-blog.md`.

Existing posts are refiled once with:

```bash
php spark reach:blog-recategorise                        # dry run: each change + evidence, before/after counts
php spark reach:blog-recategorise --apply                # write category / product_slug on the profiles (text untouched)
php spark reach:blog-recategorise --apply --republish    # …and queue a republish of every changed live post
php spark reach:blog-recategorise --id=N --category=inventory-stock --product=inventory --apply --republish
                                                        # the owner's own choice for one post (category slug or label;
                                                        # --product=<aicountly.com slug> or none) instead of the inferred one
php spark reach:work --queue=publishing --once --limit=20   # (the worker cron does this on its own)
```

product_slug is only filled when derivable with evidence: the title names a live app, or the
topic candidate's `[product]` note / content-base entry names one AND the post is app-led
(stream product / problem_to_product, or an AICOUNTLY category). A marketing-stream post with
only a product affinity stays a topic article (product_slug NULL). Posts whose category maps to
nothing are listed and left as they are.

## Content clean-up (re-vetting live and approved content)

Every blog publish is gated by `BlogPublishVetGate` (Gemini must approve the exact version; see
`docs/automation/CLAUDE_ROUTINES.md`). To clear out content that went out before the gate:

```bash
php spark reach:content-revet --heuristics-only          # free: list what the cheap checks flag
php spark reach:content-revet --dry-run --community      # Gemini verdicts, nothing changed
php spark reach:content-revet                            # apply
php spark reach:content-revet --ids=1,5                  # just these items
php spark reach:content-revet --type=none --community --dry-run   # community answers only: verdict + would
php spark reach:content-revet --type=none --community --answer-ids=1,2   # fix just these live answers
```

| Item | Verdict | What happens |
|---|---|---|
| approved, not live | approve / revise→approve | adopted into the routine pipeline (origin `revet`, no quota): cover, publish, verify |
| approved, not live | reject / unresolved revise | approval revoked, item `rejected`, queued for a same-slug rewrite |
| live | reject | withdrawn on aicountly.com (`unpublish`: the receiver answers 410 and keeps the slug), item `unpublished`, queued for a same-slug rewrite |
| live (blog/KB) | revise→approve with changed text | Gemini's corrected text becomes a new current version (approved under the SystemApprover, vetting run bound) and is republished at the same slug and public record: `revision_applied+republish_queued` (`revision_failed` + reason on an error; nothing changes). Slug never changes; Gemini's title only when it is still the topic; never taken down |
| live | unresolved revise, no changed text, HIGH_RISK, release note / guide | `flagged_for_owner` (reason in the report) |
| live | approve | recorded only |
| any | vetting unavailable | nothing changes |
| community answer, live | reject | `withdrawn+requeued`: unpublished on aicountly.com (reversible: the receiver's restore endpoint) and withdrawn in Reach, then offered to the community-qa routine as a priority re-answer (no quota); the new text is a correction of the same answer, republished at the same URL |
| community answer, live | revise with revised text | `corrected`: the vetting model's revision is published as a correction version ("Updated for accuracy.") through vet → SystemApprover → publish → verify; the current text stays live until then (`correction_pending` while vetting/publishing; `correction_failed+requeued` if it does not pass — the old text stays live) |
| community answer, live | revise without text | `kept_live+requeued`: stays live, queued for a re-answer |
| community answer, live | tier 4 (prohibited) | a reject is withdrawn but not re-answered; a revise is `held_high_risk_left_as_is` |

The blog and KB routines take queued rewrites first (`GET v1/automation/regenerate/next`) and
submit them with `regeneration_id`; the rewrite becomes a new version of the same item (the
version history says what it replaced), gets a fresh cover and is republished at the same slug
(the receiver updates the same public record). Each verdict is stored in
`reach_content_quality_checks`; the queue is `reach_content_regenerations`. The daily
`reach.content_quality_sweep` (01:00 UTC) re-vets what went live in the last 48 hours and takes a
page down only on a Gemini reject; the daily report shows one informational line.
