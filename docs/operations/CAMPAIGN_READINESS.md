# Campaign readiness — Email, SMS, WhatsApp, social, ads

Status as of 2026-10-17 (branch `wip/reach-G`). This is the operator's view of
what each campaign channel can do **today**, what it needs, and what is
blocked. The live version of the per-channel status is
**Distribution → Readiness** in Reach (`GET /api/v1/distribution/readiness`),
which computes it from the running configuration and never returns a secret.

## What changed (and the rule behind it)

**No channel reports sent / posted / delivered unless a real provider accepted
the message.**

| Before | Now |
| --- | --- |
| `MockSocialPublisher` was the only social publisher and its production guard was a no-op, so production posts were marked `posted` with fake `mock-post-…` ids. | Every mock (email, SMS, WhatsApp, social) throws `ProviderNotConfiguredException` in production (`ProviderGuard`; unset `CI_ENVIRONMENT` counts as production). The post/campaign is recorded `failed` with the reason. |
| Approving a social post set `approved` (auto-dispatchable) when a `*_API_TOKEN` env var was set — tokens nothing used. | Approval goes to `manual_queue` unless a real publisher for that network exists (`ChannelProviderFactory::socialPublisherReadyFor`): social-api.ai with a key and a resolvable account for that platform. |
| Email with no `EMAIL_API_KEY` used the mock and still marked the campaign `sent`. | Production refuses to queue the send (HTTP 503 "Email provider not configured…"); nothing changes status. |
| `SMS_SKIP_SEND` / `WHATSAPP_SKIP_SEND` (both **default true**) returned success without calling the gateway, recorded as `accepted`. | Recorded as `skipped` (new attempt status), the dispatch stops, dispatch + campaign are `failed` with `last_error` naming the switch. |
| Recipients were hard-coded `list@broadcast` / `broadcast`, falling back to `CAMPAIGN_DISPATCH_TEST_EMAIL/_PHONE`. One "send" per campaign. | Real per-recipient audience (below). Gateways send only to the exact address on the message; no fallback. The test address is only used by the explicit test-send endpoint. |
| Dispatch rows inserted `pending` (violates the table CHECK and `DispatchStatus`); fan-out looked for variants `passed` (they are `valid`); `distribution.*` jobs had no handlers; delivery attempts were inserted with `dispatch_id = NULL` into a NOT NULL column; several audit constants the services used did not exist. Net effect: no campaign could actually dispatch. | Fixed; covered by DB-backed tests. |

Existing bad rows: run `php spark reach:distribution-audit` (dry run, exit 1
when something needs fixing) and `php spark reach:distribution-audit --fix` to
mark mock-"delivered" social posts, attempts and campaigns `failed` with the
reason *"recorded as delivered by a mock provider; nothing was actually sent"*.
It also lists (never changes) `skipped` attempts and legacy SMS/WhatsApp
attempts with locally invented ids (`sms-…`, `wa-…`) — those were recorded
while `*_SKIP_SEND` defaulted to on and may never have been sent; check the
gateway's own logs.

## How a campaign send works now

1. **Audience** comes from the campaign's `audience_filter` (email and WhatsApp
   campaign rows, `reach_sms_campaigns.audience_filter`, or the variant's
   `audience` / campaign `target_audience`). It must be explicit:
   `{"segment_id": N}`, `{"lead_ids": [..]}`, `{"filters": [rules]}` or
   `{"all_leads": true}` — an empty filter is refused, never read as "everyone".
   People come from `reach_leads` (the only contact store). Segment rules may
   test `email, phone, created_at, source_kind, product_interest, priority,
   organization, campaign_id`; unknown fields are refused. Segment **preview**
   now returns the real count (it used to be `rand(10, 500)`).
2. **Snapshot** (`AudienceResolverService`), per lead: valid address for the
   channel → not suppressed (bounce/complaint/unsubscribe/manual; phone numbers
   normalised so `+91 98765 43210` and `9876543210` match) → consent
   (`reach_channel_consents`, `subject_type=lead`, `purpose=marketing`):
   **SMS and WhatsApp need a current `granted` row**; email is excluded on a
   revoked/expired row, and needs `granted` when
   `distribution.email.require_opt_in` is on → dedupe on address → per-send cap
   (`distribution.send_cap.<channel>`). The snapshot is frozen with counts per
   exclusion reason and stores only hashes + masked addresses.
3. **Dispatch** row is created `queued`, the channel campaign goes `sending`,
   and a `distribution.dispatch.<channel>` job is queued on the **`distribution`
   queue**. Each job sends `distribution.batch_size` recipients (default 100),
   one `reach_campaign_delivery_attempts` row each, re-checks suppression at send
   time (late unsubscribes are honoured), then queues the next batch. HTTP 429
   pauses 60 s and retries the same recipient; other failures are recorded per
   recipient (permanent / transient / rejected); "not configured" and "skipped"
   stop the whole dispatch.
4. **Finalise**: dispatch `completed` (all accepted), `partially_completed`
   or `failed`; the email/WhatsApp/SMS campaign is `sent` only if at least one
   message was accepted, otherwise `failed` with `stats.error`. Parent
   `reach_campaigns` follows its dispatches.

Endpoints (all JWT + permission gated unless noted):

| Endpoint | Purpose |
| --- | --- |
| `POST v1/distribution/email/dispatch/:id` | queue an email campaign send (202) |
| `POST v1/distribution/email/test/:id` `{to}` | one `[TEST]` copy to `to` (or `CAMPAIGN_DISPATCH_TEST_EMAIL`); campaign status untouched |
| `POST v1/distribution/sms/dispatch/:campaign_id` | queue an SMS campaign send |
| `POST v1/distribution/whatsapp/dispatch/:id` | queue a WhatsApp template broadcast |
| `POST v1/distribution/campaigns/:id/dispatch` | start every approved channel send linked to a campaign + fan out its latest approved version |
| `POST v1/distribution/campaigns/:id/schedule` `{scheduled_at}` | queue `distribution.campaign.schedule` for that time |
| `POST v1/distribution/campaigns/:id/cancel` | cancel; unsent batches stop |
| `POST v1/distribution/dispatches/:id/reconcile` | repair from local records (stuck `sending` → failed/unknown, requeue, finalise) |
| `GET v1/distribution/readiness` | the live readiness report |
| `GET/POST v1/public/unsubscribe?t&e&s` | **public**, HMAC-signed one-click unsubscribe → email suppression |

### Queue for the worker cron

All handlers run on queue **`distribution`** (job types
`distribution.dispatch.email|sms|whatsapp|social`,
`distribution.campaign.schedule`, `distribution.campaign.reconcile`). The
worker line must include it, e.g.
`php spark reach:work --queue=default,blog,publishing,community,distribution --once --limit=20 --worker-id=cron`.
Until it does, sends sit `queued` and the readiness page flags the queue as
stalled after 15 minutes. (`REACH_WORKER_AND_CRON.md` is owned by another
change; this note is the hand-off.)

## Per channel

States used below and on the readiness page: **ready**, **test_mode**
(configured but `*_SKIP_SEND` on), **not_configured** (fails closed),
**blocked** (waiting on something outside the code), **not_supported**.

### Email — state depends on config; code ready

- **Providers:** managed centrally in Console (**Email providers**, bound to
  `reach`) — `GatewayEmailSender` → `Gateways\Mailer` → `Email\EmailDispatcher`,
  which reads `GET {CONSOLE_API_URL}/portal/email/providers?app_code=reach` with
  `CONSOLE_API_TOKEN` (capability `email.credentials`; `Email\EmailProviderResolver`,
  cached for the answer's TTL in an encrypted 0600 file, used up to 15 minutes stale
  while Console is down). A send tries the primary, then each fallback (HTTP API =
  Itwalk, SMTP = Aicountly's mail server); a recipient counts as sent only when a
  provider accepted it. The readiness page lists the providers by name in send
  order (never a key). `php spark reach:email-providers [--send=you@x] [--resend]`
  shows the same and sends a test.
- **Env / settings:** `SENDER_EMAIL` (used when a provider has no `from_email`),
  `SENDER_NAME`, `REPLY_TO_EMAIL`; `EMAIL_API_KEY` / `EMAIL_API_URL` /
  `EMAIL_API_FORMAT` only as the last resort (Console has no provider for reach,
  or is unreachable with nothing cached); `EMAIL_PROVIDER` blank.
  **Settings → Distribution:** `distribution.email.postal_address` (or env
  `DISTRIBUTION_POSTAL_ADDRESS`) — **required, sends are refused without it**;
  `distribution.email.unsubscribe_base_url` (blank = `REACH_APP_URL`);
  `REACH_SETTINGS_KEY` (or `JWT_SECRET`) signs the unsubscribe links;
  optional `distribution.email.require_opt_in`, `distribution.send_cap.email`
  (default 5000).
- Every campaign email gets a footer with a signed one-click unsubscribe link and
  the postal address; `{{unsubscribe_url}}` in the body is also replaced.
  Clicking it adds the address to the email suppression list.
- `List-Unsubscribe` / `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
  (RFC 8058) headers go out when the SMTP provider sends; the HTTP API (Itwalk)
  carries the body footer only (its header support is not verified). Gmail and
  Yahoo require one-click header unsubscribe for bulk senders (≈5,000+/day to
  their users): above that volume make the SMTP provider the primary for reach.
- **Prerequisites outside Reach:** SPF including every provider (Itwalk and the
  Aicountly mail server), DKIM signing for each provider's from domain, DMARC
  record (at least `p=none` with reporting, aligned From); a working reply-to
  inbox; Itwalk account out of sandbox with enough daily quota.

### SMS — code ready; blocked on DLT registration + opt-ins

- **Provider:** Digimiles / AOC Portal (`SMS_PROVIDER=digimiles`, default) or
  SMS Gateway Hub (`smsgatewayhub`).
- **Env:** `SMS_API_KEY`, `SMS_SENDER_ID` (DLT header), `SMS_SKIP_SEND=false`
  (**defaults to true → test_mode**), `SMS_TYPE` (defaults `TRANS`),
  `SMS_COUNTRY_CODE=91`, optional `SMS_API_URL`. Setting
  `distribution.send_cap.sms` (default 1000).
- **Per campaign** (`reach_sms_campaigns`): `dlt_entity_id`, `dlt_template_id`,
  `dlt_sender_id` (all required), `template_variables.body` = the exact
  DLT-approved text with variables filled (falls back to the campaign's
  `creative_copy`); ≤160 characters.
- **There is no API to create SMS campaign rows yet** — they must be inserted
  by an operator (or a follow-up change).
- **TRAI (TCCCPR 2018) prerequisites:** principal-entity registration on an
  operator DLT portal (entity id); header registration (promotional headers are
  numeric; service/transactional are 6-letter alphabetic); every message
  registered as a content template with `{#var#}` placeholders and the right
  category; URLs, call-back numbers and APKs in templates whitelisted on DLT
  (TRAI direction effective 2024); promotional sends are DND-scrubbed and only
  delivered in the allowed daytime window; service-explicit messages need
  consent captured through a DLT-registered consent template.
- **Compliance gaps to confirm before sending marketing SMS:**
  `SMS_TYPE` defaults to `TRANS` — a marketing message on a transactional route
  is non-compliant; set the promotional / service-explicit route value Digimiles
  expects. The Digimiles payload carries `templateId` but not the entity id —
  confirm with Digimiles that the PE id is bound on the account.
- **Audience:** only leads with a `granted` SMS marketing consent. Public lead
  capture does **not** record consent today, so the eligible audience is empty
  until consents are added (`POST v1/distribution/consents`,
  `subject_type=lead`) or capture is extended.

### WhatsApp — code ready (template send unverified); blocked on Meta approvals + opt-ins

- **Provider:** Infobip. Campaigns send **approved templates** only
  (`POST /whatsapp/1/message/template`); free-form text is refused.
- **Env:** `WHATSAPP_API_KEY`, `WHATSAPP_WABA_ID`, `WHATSAPP_SENDER_ID` (the
  registered sender number; falls back to the WABA id),
  `WHATSAPP_SKIP_SEND=false` (**defaults to true → test_mode**), optional
  `WHATSAPP_TEMPLATE_API_URL` (Infobip accounts usually have a personal base
  URL, e.g. `https://<id>.api.infobip.com/whatsapp/1/message/template`; the
  default is `https://api.infobip.com/...`). Setting
  `distribution.send_cap.whatsapp` (default 1000).
- **Per campaign:** `template_name` (Meta-approved), `template_params` (list of
  body placeholders, or `{"placeholders": [...], "language": "en"}`; `{{name}}`
  is replaced with the lead's name).
- The template request shape is taken from Infobip's public API reference and
  **has not been exercised against the live account** — do one test send.
  Delivery-report webhooks are not wired; status stops at `accepted`.
- **Prerequisites:** Meta Business verification; WhatsApp sender + display
  name approved in Infobip; each template approved in category MARKETING;
  WhatsApp Business Policy opt-in (explicit, naming Aicountly, recorded) and a
  working opt-out ("STOP" → add a WhatsApp suppression); messaging-limit tier
  and per-message marketing pricing (check current Meta rates).
- **Audience:** only leads with a `granted` WhatsApp marketing consent (same
  capture gap as SMS).

### Facebook, Instagram, LinkedIn, X/Twitter — through social-api.ai

Full setup, request flow and assumptions: [SOCIAL_API.md](SOCIAL_API.md).

- **Provider:** `SocialApiAiPublisher` (social-api.ai), selected when `social.socialapi.api_key`
  is set (Settings → AI & Automation → Social, or env `SOCIALAPI_API_KEY`; `SOCIAL_PROVIDER`
  unset or `socialapi`). Base URL `social.socialapi.base_url`, default
  `https://api.social-api.ai/v1`.
- **States on the readiness page:**
  - `ready` — key and base URL set **and** an account for that platform resolves
    (`social.socialapi.account.<platform>`, or the single active account in social-api.ai's
    `GET /v1/accounts`, cached 10 minutes); the account id is shown;
  - `not_configured` — no key (the development mock is in use; in production posts fail closed
    and approved posts go to `manual_queue`);
  - `blocked` — key set but no active account / several active accounts (set the id) / the key
    is refused / social-api.ai unreachable; the reason is shown.
- **Honest post states:** a dispatched post is `publishing` once social-api.ai accepted it
  (HTTP 201) and becomes `posted` only when `GET /v1/posts/{id}` reports the target published
  (`reach.social_post_poll`, every 5 minutes on the `distribution` queue). A failed target is
  `failed` with social-api.ai's `error.code`; no confirmation within 30 minutes → `failed` with
  `publishing.delivery.timeout`. 207 / 422 / 400 / 409 are recorded with their codes; the redacted
  provider response is kept on the post (`provider_response_json`).
- **Campaign fan-out** (`dispatchVariant`): the variant becomes a `reach_social_posts` row linked
  by `dispatch_id`; the dispatch stays `dispatching` until the poller settles it
  (`completed` / `failed`), then the campaign follows.
- **After saving the key** run `php spark reach:integrations-check --only=social_api` (or
  **Check social-api.ai** on Settings → Social): it lists the connected accounts and which one each
  platform posts to. It never posts.
- **Per-network options** (`platform_data`) are operator JSON copied from docs.social-api.ai →
  Platforms (`social.socialapi.platform_data.<platform>`); Reach invents no keys.
- Withdraw/delete is not implemented (no confirmed endpoint) — remove posts in social-api.ai.

### YouTube — through social-api.ai (default) or direct upload

Videos come from the video pipeline, not campaign posts. With `video.youtube.publisher =
social_api` (default) each vlog and Short is published to YouTube through social-api.ai as a
`youtube` `reach_social_posts` row (MP4 uploaded to social-api.ai's media store, title = video
title, text = description); the video item is `published` only when social-api.ai reports the
YouTube target published. `direct` keeps Reach's own Google OAuth upload. The YouTube readiness
row names the active path; Video → Pipeline / Operations show it too. See
[VIDEO_AUTOMATION_SETUP.md](VIDEO_AUTOMATION_SETUP.md).

### Google Ads, Meta Ads, LinkedIn Ads — not supported

No ad-platform code exists in Reach (no campaign, audience-sync, spend or
conversion API). social-api.ai is used for organic posts and videos only. Run
paid campaigns directly in each ads manager for launch; UTM templates and
attribution in Reach still apply to the landing traffic.

## DPDP Act 2023 (applies to every channel)

Marketing to individuals in India needs consent that is free, specific,
informed, unconditional and given by clear affirmative action, after a notice
of what is collected and why; withdrawal must be as easy as giving consent;
data is kept only as long as the purpose needs. Reach's side: consents per lead,
channel and purpose in `reach_channel_consents` (with `source` and
`proof_reference`), revocation honoured at snapshot and send time, suppression
lists, signed unsubscribe. Gaps: lead capture does not record consent or show a
notice; no consent-withdrawal link for SMS/WhatsApp other than replying STOP;
retention/erasure of `reach_leads` is not automated. The DPDP Rules were
notified in November 2025 with phased commencement — confirm with counsel
which obligations already apply.

## Go / no-go checklist for launch day

Go for a channel only when every line for it is ticked **and** the readiness
page shows `ready`.

**Platform**
- [ ] Migration `2026-10-17-700001_DistributionHonestDelivery` applied (`php spark reach:migrate`).
- [ ] Worker cron drains `distribution` (see queue note above); readiness page shows the queue not stalled.
- [ ] `CI_ENVIRONMENT=production` on the server (mocks refuse there).
- [ ] `php spark reach:distribution-audit` run; `--fix` applied if it reports rows.

**Email**
- [ ] `EMAIL_API_KEY`, `SENDER_EMAIL` set; SPF/DKIM/DMARC pass for the sender domain (send to a Gmail test inbox, "Show original" → all PASS).
- [ ] `distribution.email.postal_address` set; `REACH_APP_URL` correct; unsubscribe link in the test email works and adds a suppression.
- [ ] Test send (`POST v1/distribution/email/test/:id`) received.
- [ ] Campaign `audience_filter` set; expected eligible count confirmed in the dispatch response.
- [ ] Daily volume to Gmail/Yahoo below the bulk-sender threshold (no List-Unsubscribe header yet).

**SMS** (default: **no-go** until DLT is done)
- [ ] DLT entity, header and content templates approved; URLs in templates whitelisted.
- [ ] `SMS_SKIP_SEND=false`; `SMS_TYPE` set to the promotional/service-explicit route; PE id binding confirmed with Digimiles.
- [ ] SMS campaign row created with DLT ids and the exact approved text.
- [ ] Leads have recorded SMS marketing consent (eligible count > 0).

**WhatsApp** (default: **no-go** until Meta approvals are done)
- [ ] Sender approved; MARKETING template approved; `WHATSAPP_SKIP_SEND=false`; `WHATSAPP_TEMPLATE_API_URL` set to the account base URL.
- [ ] One template test to an internal opted-in number succeeds (Infobip shows the message).
- [ ] Leads have recorded WhatsApp opt-in; STOP handling owner assigned.

**Social** (social-api.ai)
- [ ] Migration `2026-10-18-100001_SocialApiPublishing` applied.
- [ ] `social.socialapi.api_key` saved; `php spark reach:integrations-check --only=social_api` is OK and lists the expected account for every network you will post to (set `social.socialapi.account.<platform>` where several are connected).
- [ ] Readiness shows `ready` for each such network.
- [ ] Any per-network options copied from docs.social-api.ai into `social.socialapi.platform_data.<platform>`.
- [ ] One test post per network dispatched by hand ends `posted` with a working link (not just `publishing`); the assumptions in SOCIAL_API.md § "Verified vs. assumed" checked against its stored response.

**Ads** — **no-go in Reach**; use each ads manager directly.
