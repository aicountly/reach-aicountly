# Release notes ("What's new") and workflow guides

Every product's version upgrades reach aicountly.com's "What's new", and the guides on
aicountly.com/guides are refreshed weekly, without anyone writing or approving them by hand:

1. A Claude Routine (Sonnet) reads the product repositories and POSTs a draft to Reach —
   [`routines/reach-release-notes.md`](routines/reach-release-notes.md) (daily 21:00 IST) and
   [`routines/reach-workflow-guides.md`](routines/reach-workflow-guides.md) (Mondays 06:00 IST).
2. Reach checks it against the aicountly.com receiver's own format rules
   (`StructuredPayloadValidator`, a copy of aicountly-com `includes/publisher/StructuredContent.php`) and,
   for release notes, the version rule below.
3. Gemini vets it with the `release_note` / `workflow_guide` rubric (`App\Libraries\Ai\Vetting\Rubrics`).
   A `revise` is re-vetted once; only `approve` is published. The decision is final — nobody reviews it
   afterwards, and the public site does not claim a person did.
4. The approval is recorded under the login-disabled **Reach System Bot** (`SystemApprover`) with the
   vetting run id, and the note/guide is published to aicountly.com with `content_type` `release_note` /
   `workflow_guide`. Success is reported only when the receiver's verification says the page is `live`.

Code: `app/Libraries/Automation/Structured/` (`StructuredSubmissionService` is the pipeline).

## Storage

One table, `reach_structured_content_submissions` (migration `2026-10-17-500001`): one row per routine
POST — the payload as submitted, the vetted payload, status, the deciding vetting run
(`vetting_run_id`, plus every run in `vetting_run_ids`), reasons, the public URL, retry bookkeeping and
the git range a release note covers (`source_json`, `source_to_ref`).

An approved submission also gets the ordinary publishing records, so the existing pipeline
(`PublicationDeploymentService`, deployments, attempts, the Deployments screen) publishes it unchanged:
a `reach_content_items` row per public page (content types `release_note` / `workflow_guide`; one item per
release note, one per guide reused across its weekly updates) and a `reach_content_versions` row whose
`structured_payload` is exactly what is sent. Why this split: the submission table carries what is specific
to routine content (external key, version rule, vetting outcome, retry), while publishing reuses the
versioned content model the receiver contract depends on (`reach_content_version_id` must grow with each
update) instead of a second publisher.

| Status | Meaning |
|---|---|
| `pending_vetting` | stored; vetting not done (Gemini unavailable). The sweep retries it. |
| `rejected` | vetting rejected it (or still wanted changes after one revision, or its revision no longer fits the site's format). Stored with reasons; never published; not retryable. |
| `approved` | signed off by the system approver; being published. |
| `published` | live on aicountly.com (`public_url`). |
| `failed` | approved but not live — the publish failed or verification said `live: false`. Retried with backoff (15 min doubling to 6 h, 10 attempts) while the cause is transient (`server_error`, `timeout`, `network_error`, `rate_limited`, `configuration_error`, `not_live`). |
| `superseded` | a newer submission for the same guide (or a newer/same release version) replaced it before it went live. |

## Endpoints

Routine side (`X-Automation-Token`):

| Endpoint | |
|---|---|
| `GET v1/automation/release-plan` | per catalogued product (launch apps first): repo (`aicountly/<repo>`), `last_published` {version, slug, date_iso, source `reach`/`site`, to_ref}, `diff_from` {ref \| since}, `in_flight`, `next_versions` |
| `POST v1/automation/release-notes` | `{product_slug, version, title, date_iso, intro, sections[{heading, bullets[]\|paragraphs[]}], source{repo, from_ref, to_ref, commits_count}, external_key}` |
| `GET v1/automation/workflow-plan` | per launch app: guide hub URL, published guides (slug, title, `source` `site`/`reach`, last_updated, public_url, and the published `content` for Reach guides), `in_flight` |
| `POST v1/automation/workflow-guides` | the receiver's `workflow_guide` payload (`product_slug, slug, title, blurb, audience, outcome, prerequisites[], steps[{title, text, tip?, image_url?}], faqs[{q, a}], diagram_mermaid?, image_url?`) + `external_key` + optional `source{repo, ref}` |

Answers: **201** published (`data.public_url` is live) · **200** rejected by vetting (`data.reasons`), an
idempotent replay (`data.idempotent_replay`), or a guide that is already published with exactly this
content (`data.status = "unchanged"`) · **202** stored but not live yet — do not resubmit · **403** the
type is switched off · **409** version rule or external-key conflict (`details`) · **422** invalid payload
(`details` lists fields) · **429** today's cap (`automation.release_note.daily_limit` /
`automation.workflow_guide.daily_limit`, default 40 each, IST day) is used — the routine stops.

The release slug is derived, not sent: `{product}-v{major}-{minor}[-{patch}]` (`smart-books-v…` for
Books, the hand-written releases' style). `external_key` is unique per type; the same key with the same
content replays, with different content is a 409.

Operator side (Console JWT): Publishing → **Release notes** and **Workflow guides**
(`GET v1/publishing/release-notes|workflow-guides`, `publishing.view`) list every submission with status,
vetting decision, run id, reasons, last error and public link; **Retry**
(`POST …/{id}/retry`, `publishing.publish`) re-runs vetting for a pending one or the publish for an
approved/failed one. A rejection has no Retry.

## Versioning

Per product, `MAJOR.MINOR` or `MAJOR.MINOR.PATCH` (a `.0` patch is dropped: `3.5.0` is `3.5`):

| The period contains | Next version |
|---|---|
| at least one new feature or visible improvement | next minor — 3.4 → **3.5** |
| only fixes and small polish | next patch — 3.4 → **3.4.1** |
| a redesign / new generation (rare; only when the commits say so) | next major — 3.4 → **4.0** |

The routine proposes the version; Reach accepts only one of those three steps up from the highest
version that is **published or in flight** for the product (`release-plan` → `next_versions`). Anything
else — the same version, a lower one, or a skipped one (3.4 → 3.6) — is a 409 that names the allowed
versions. Rejected, failed and superseded submissions hold no number, so a version can be submitted
again after a rejection.

The floor includes the **hand-written releases on aicountly.com** (snapshot 2026-10-17 in
`ProductCatalog`, e.g. Smart Books 2.0, HRMS 2.0, Manage 1.2). That matters: a Reach note with a
hand-written release's slug would replace that page. When a hand-written release is added on the site,
add it to `ProductCatalog::products()` too. A product with no release yet (Billing) may start at any
version; the plan suggests `1.0`.

## Retries and the sweep

`reach:schedule` enqueues `reach.structured_content_sweep` (queue `publishing`) every 15 minutes. It
re-vets `pending_vetting` rows whose backoff has passed (15 min doubling, capped at 1 h — Gemini outages
retry indefinitely), republishes `failed` rows that are due, and picks up `approved` rows left behind for
over 10 minutes. Types switched off in Settings → AI & Automation
(`automation.release_note.enabled`, `automation.workflow_guide.enabled`) are not touched until switched
back on; while off, new submissions are refused with 403.

## Production honesty

- Unvetted content is never published; a Gemini outage leaves the row pending (`vetting_unavailable`).
- A publish counts only when the receiver's verification returns `live: true`; otherwise the row is
  `failed` with `not_live`, never `published`.
- If `REACH_PUB_MOCK=true` on a server with `CI_ENVIRONMENT=production`, nothing is sent and the row
  fails with `configuration_error` — the mock publisher can never turn into a reported publish.

## Operator actions after deploy

1. Run migrations (`php spark reach:migrate`, which the deploy runs) — adds the table and widens
   `rci_content_type_chk`.
2. The aicountly.com receiver must have its migration `013_release_notes_workflow_guides` applied, or it
   answers 503 and submissions sit in `failed` (retried) until it is.
3. Make sure the worker drains the `publishing` queue (it already does in
   `docs/operations/REACH_WORKER_AND_CRON.md`) and `reach:schedule` runs every minute.
4. Create the two routines from the files in `routines/` (they need `REACH_API_BASE` and
   `REACH_AUTOMATION_TOKEN` in the cloud environment, and the product repositories).
