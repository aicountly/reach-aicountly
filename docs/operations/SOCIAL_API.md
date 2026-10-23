# social-api.ai — social posts and YouTube videos

Reach publishes its social posts **and** its YouTube vlogs/Shorts through
[social-api.ai](https://docs.social-api.ai). Aicountly's YouTube connection inside social-api.ai
is already verified by Google, so Reach needs no Google OAuth app, no YouTube API audit and no
"private-only" lock. Reach's own direct YouTube uploader stays as an optional fallback
(`video.youtube.publisher = direct`, see [VIDEO_AUTOMATION_SETUP.md](VIDEO_AUTOMATION_SETUP.md)).

Code: `server-php/app/Libraries/Distribution/Providers/SocialApiAiPublisher.php` (the publisher),
`…/Providers/SocialApi/` (HTTP client, account resolution, response parsing, redaction),
`…/Distribution/SocialPostStatusPoller.php` (confirms delivery), `VideoPipelineRunner`
(`youtube_upload` step, social_api path).

## Set it up (operator)

1. In social-api.ai: connect the Aicountly accounts (YouTube channel, Instagram, Facebook page,
   LinkedIn page, X). Create an API key (it starts with `sapi_key_`).
2. Reach → **Settings → AI & Automation → Social**:
   - *social-api.ai API base URL* — leave the default `https://api.social-api.ai/v1`.
   - *social-api.ai API key* — paste the key (write-only; stored encrypted; env fallback
     `SOCIALAPI_API_KEY`).
   - *How videos reach YouTube* (`video.youtube.publisher`) — `social_api` (default) or `direct`.
   - *Account id* per platform — leave blank when exactly one active account of that platform is
     connected; set it when there are several (the live check prints the ids).
   - *platform_data* per platform — optional JSON objects (see below).
3. Save, then click **Check social-api.ai** on the same card, or on the server:

   ```bash
   php spark reach:integrations-check --only=social_api
   ```

   It calls `GET /v1/accounts` with the saved key and prints OK + every connected account
   (platform, name, id, status) and which account each platform would post to — or FAIL with the
   reason (key refused 401/403, network error, unexpected answer). **It never posts.**
4. Check **Distribution → Readiness**: Facebook / Instagram / LinkedIn / X / YouTube show `ready`
   only when the key is set and an account for that platform resolves.
5. Do one real post first (a test LinkedIn post via Social Distribution → Dispatch) and confirm it
   ends `posted` with a link; then let the automation run.

## How a post goes out

1. **Account**: `social.socialapi.account.<platform>` if set, otherwise the single *active*
   account of that platform in `GET /v1/accounts` (cached 10 minutes). None or more than one →
   the post fails closed with a message naming what was found and the setting to fill in.
2. **Media** (videos, images in `media_refs`): Reach streams the file (e.g. Shotstack's rendered
   MP4) to a temp file, then `GET /v1/media/upload-url?media_type=&filename=` → `PUT` the bytes to
   the presigned `upload_url` (no Reach credentials sent there) → `POST /v1/media/{id}/verify`
   (re-checked up to 4 times while `pending`). YouTube links in `media_refs` are not files and are
   skipped.
3. **Create**: `POST /v1/posts` with `text`, `title` (YouTube: the video title; the description is
   the `text`), `targets:[{account_id}]`, `media:[{source_type:"media_id", media_id}]`, and
   **always** `publish_now: true` — or `scheduled_at` (RFC 3339, UTC) when the post is scheduled at
   least 2 minutes ahead. Never a draft.
4. **Answer**:

   | HTTP | Reach records |
   |---|---|
   | 201 (or 2xx) | post `publishing` (not posted), social-api.ai post id stored, attempt `accepted` |
   | 207 | our target failed → `failed` with its `error.code`; still pending → `publishing` |
   | 422 | `failed`, every target's error code in the message, `provider_error_code` set |
   | 400 | `failed` (`validation.field_invalid` etc., field named) |
   | 409 | `failed` with `resource.conflict` (same title + text within 24 h); if the answer carries the existing post id Reach follows that post instead |
   | 401/403 | `failed` as not configured: the key was refused |
   | 429 | `failed` (rate limit) |
   | 5xx / network | `failed`, *outcome unknown* — check social-api.ai before retrying |

5. **Confirm**: `reach.social_post_poll` (queued by `reach:schedule` every 5 minutes on the
   `distribution` queue) calls `GET /v1/posts/{id}` for each `publishing` post:
   published → `posted` with the network URL / id when returned; failed → `failed` with the
   target's `error.code`; still pending 30 minutes after submission (or after the scheduled time)
   → `failed` with `publishing.delivery.timeout` and a note that it may still appear. A status
   check that errors keeps the post `publishing` (shown in the error column) until the timeout.
   The video pipeline also checks its own YouTube post every ~45 s.

Every stored provider response (`reach_social_posts.provider_response_json`) and every message is
redacted: the API key, any `sapi_key_…`, `Bearer …`, credential-named fields and the query string
of signed URLs (presigned upload URLs) are replaced with `[REDACTED]`.

**No webhooks.** social-api.ai can send `post.published / post.partial / post.failed`, but its
signature scheme is not documented to Reach, so Reach does not accept them — it polls.

**Retries.** Only idempotent GETs (accounts, upload-url, post status) are retried, on 5xx/network.
`POST /posts` is never retried by the client. A failed post can be dispatched again (Social
Distribution → Retry); social-api.ai refuses an identical post within 24 h (409), which is what
protects against a double post when the first outcome was unknown. Each create carries an
`Idempotency-Key` header derived from Reach's attempt key.

## platform_data

Per-network options (YouTube privacy, category, made-for-kids, Instagram media type, LinkedIn
visibility…) go in `platform_data`. **Reach does not know the exact keys and does not invent
them.** Copy them from docs.social-api.ai → *Platforms* → the network, and paste the
`platform_data` object exactly as the docs show it into
`social.socialapi.platform_data.<platform>` (env fallback `SOCIALAPI_PLATFORM_DATA_<PLATFORM>`).
Reach merges it verbatim (deep merge) into `platform_data` of every post to that platform; blank =
none is sent. The setting is validated as a JSON object.

This means `video.youtube.privacy` and `video.youtube.category_id` (direct-upload settings) are
**not** sent through social-api.ai — put the equivalent keys in the YouTube platform_data.

## YouTube visibility and per-target options (confirmed 18 Oct 2026)

social-api.ai's own dashboard sends YouTube visibility **on the target**, not in `platform_data`:
`{"publish_now":true,"targets":[{"account_id":"acc_…","visibility":"public"}],"text":"…"}`, and its
default visibility is **private**. Reach therefore always sends `targets[].visibility` for YouTube
from `social.socialapi.youtube.visibility` (`public` | `unlisted` | `private`, default `public`).

Other per-network target fields go in `social.socialapi.target_options.<platform>` (a JSON object
merged into that platform's `targets[]` entry; it can't change `account_id`, and for YouTube the
visibility setting wins). To find a field name: in app.social-api.ai → Create Post, set the option,
open DevTools → Network → Fetch/XHR, save as **Draft**, and read the `posts` request's Payload
(never copy the Headers tab — it carries your login token).

## Settings

| Key | Default | Env fallback |
|---|---|---|
| `social.socialapi.base_url` | `https://api.social-api.ai/v1` (must be https) | `SOCIALAPI_BASE_URL` |
| `social.socialapi.api_key` (secret) | — | `SOCIALAPI_API_KEY` |
| `video.youtube.publisher` | `social_api` (`social_api` \| `direct`) | `VIDEO_YOUTUBE_PUBLISHER` |
| `social.socialapi.account.{youtube,instagram,facebook,linkedin,x,tiktok}` | blank = auto | `SOCIALAPI_ACCOUNT_<PLATFORM>` |
| `social.socialapi.platform_data.{youtube,instagram,facebook,linkedin,x,tiktok}` | blank | `SOCIALAPI_PLATFORM_DATA_<PLATFORM>` |

Reach channels map to platforms as `twitter → x`, the rest by name. TikTok is configurable but
Reach has no TikTok channel yet. `SOCIAL_PROVIDER` may stay unset (the key selects social-api.ai);
`SOCIAL_PROVIDER=mock` forces the development mock (refused in production).

## Verified vs. assumed

Verified (from social-api.ai's docs as supplied by the owner; this build could not reach
docs.social-api.ai or the API): base URL, Bearer auth with `sapi_key_` keys, JSON bodies and
RFC 3339 times; `GET /v1/accounts`; the 3-step presigned media flow; `POST /v1/posts` with
`text/title/targets/media/publish_now|scheduled_at/platform_data` and its 201/207/422/400/409
answers; `GET /v1/posts/{id}` with per-target status and `error.code`.

**Assumed — confirm with the live check and the first real post** (Reach parses defensively and
stores the raw, redacted response on the post so a mismatch is visible):

1. The account list is a JSON array (or under `data` / `accounts` / `items` / `results`), each
   account has `id` and its platform in `platform` (else `provider` / `network` / `platform_type`
   / `type` / `channel`), as a string or an object with `slug` / `key` / `id` / `name`. Unknown
   shapes are logged (`[SocialApiAi]` in `writable/logs`), never guessed.
2. "Active" = `status` missing or one of active / connected / ok / healthy / valid / enabled /
   ready / live / authorized (or `active: true`). Other statuses are not auto-picked.
3. `upload-url` answers `media_id` (or `id`) and `upload_url` (or `url`), optionally `headers`
   to send with the PUT; the PUT is a plain `PUT` with `Content-Type` = the media type.
4. `verify` answers a `status` (ready / verified / uploaded…, or pending / processing while not
   done); a 2xx without a status counts as verified.
5. The create / status answers carry the post id as `id` | `post_id` | `pid` (top level or under
   `data` / `post`), the status as `status`, and per-target outcomes as `targets` | `results`
   with `account_id`, `status`, `error {code, message}`, the published link in `url` |
   `post_url` | `permalink` | `platform_url` | `published_url` and the network's id in
   `platform_post_id` | `external_id` | … . Target status words: published / posted / success /
   completed / live → published; failed / error / rejected → failed; pending / queued /
   processing / publishing / scheduled → pending.
6. A YouTube target's URL (or id) identifies the video; Reach stores both on the video item. If
   social-api.ai returns neither, the item is still `published` (social-api.ai said so) with a
   warning to find the link in YouTube Studio.
7. A 409 answer may include the existing post's id; if not, the duplicate is recorded as failed.
8. The `Idempotency-Key` header is harmless if ignored (the docs Reach had do not mention it).
9. No custom thumbnail or separate caption track is set on YouTube via social-api.ai (no
   documented field); captions are burned into the video. `#Shorts` in the title (and the 9:16
   format) is what marks a Short.
10. Withdraw/delete is not implemented (no confirmed endpoint): Reach refuses rather than
    pretending; remove posts in social-api.ai.

## Social inbox bot (comments and DMs)

Reach reads the comments and direct messages on the connected accounts and handles each one without a
person in the loop. Code: `server-php/app/Libraries/Distribution/SocialInbox/` (`SocialInboxService`,
`SocialInboxParser`, `SocialInboxClassifier`, `SocialReplyPolicy`, `FlowTicketClient`), the inbox
methods of `SocialApiAiClient`, job `reach.social_inbox_poll`, table `reach_social_inbox`, UI
**Social → Inbox** (`/social/inbox`), API `GET v1/social/inbox`, `GET v1/social/inbox/summary`,
`POST v1/social/inbox/{id}/retry` (`social.view` / `social.dispatch`).

### What happens to a message

1. **Read** — every 10 minutes (`reach:schedule`, queue `distribution`), for each network in
   `social.inbox.platforms` that has an active account (same resolution as posting): `GET
   {social.inbox.list_path}?account_id=…&since=…&limit=50[&cursor=…]`, up to 3 pages. The first read
   goes back `social.inbox.lookback_hours` (24); later reads start an hour before the newest stored
   message. Each message is stored once (`UNIQUE (network, external_id)`); our own replies
   (`direction: outbound`, `is_from_me`, …) and deleted items are not stored.
2. **Classify** — Gemini (`ai.vetting.model.social_inbox`, blank = default vetting model) returns a
   category — question, praise, complaint, sales_lead, spam_abuse, sensitive (shares personal data /
   a private matter), other — with a confidence, a severity (high = legal threat, regulator or
   consumer court, data / privacy / security issue, money lost or fraud allegation, media), a
   personal-data flag, a neutral summary and, for question / praise / sales_lead / other, a short
   draft reply. The message is passed as untrusted data; manipulation attempts count as spam_abuse.
3. **Act**

   | Category | Reach does | Final status |
   |---|---|---|
   | question, praise (and other with a draft) | the draft is vetted (`social_reply` rubric — the vetting decision is final), checked by `SocialReplyPolicy`, then posted | `replied` (only once social-api.ai accepted the reply) |
   | complaint | lodged in **Flow** (`POST {flow.api_base_url}/internal/reach/social-tickets`, idempotent on the message id), then the fixed acknowledgement quoting the ticket (`TCK-…`) and asking them to DM / e-mail support@ — no account details in public. A DM gets the DM wording. | `ticket_created` (+ reply status) |
   | complaint, high severity | as above **plus** an immediate e-mail to `social.inbox.alert_recipients` (blank = daily report recipients), once per message, at most 10 a day. Informational — nothing to approve. | `ticket_created` |
   | sales_lead | a Reach lead (`source_kind = social_inbox`, `utm_source = <network>`, `utm_medium = social_inbox`, priority high, the message and post link in the notes) pushed to Engage like any captured lead; then the vetted reply | `lead_created` |
   | sensitive | the fixed privacy nudge (never repeats what they posted) | `replied` |
   | spam_abuse | never answered. Hidden when `social.inbox.hide_spam` is on **and** social-api.ai accepted the hide | `hidden` / `ignored` |
   | other without a draft | nothing | `ignored` |

   **Why draft + vet, not a routine:** replies must go out within minutes, and the vetting service
   already supports exactly this pattern (one model drafts, `ContentVettingService` approves /
   revises / rejects with a pinned rubric). Complaint acknowledgements and privacy nudges are fixed
   texts — no model writes them. Reach still does not generate long-form content here.

4. **Guards** (in this order; a skipped reply always says why in `reply_reason` / `status_reason`):
   `social.inbox.auto_reply` off → nothing is posted (classification, Flow tickets and leads still
   happen) · confidence below `social.inbox.min_confidence` (70) → not answered (a complaint is still
   lodged; the ticket shows the confidence) · older than `social.inbox.reply_max_age_hours` (48) →
   not answered · one reply per conversation (same network + thread + author; without a thread id,
   the same author and kind within 24 h) · `social.inbox.daily_reply_cap` (40 per IST day) → the
   message waits (`deferred`) for a later run while it is young enough · `SocialReplyPolicy`: at most
   280 characters on X (600 elsewhere), signed "— Aicountly team", no PAN / Aadhaar / card / phone /
   foreign e-mail, links to aicountly.com only.
5. **Failures are honest.** Gemini or Flow unavailable → `retry`, up to 5 attempts, then `failed`
   with the reason. Flow not set up (no key, Flow 401 / 503 "not configured" / 404) → `deferred`: the
   complaint is not acknowledged (there is no ticket to quote) and is lodged automatically once Flow
   accepts it; the daily report counts "complaints waiting for Flow". A reply POST is never retried:
   a refused reply is `failed` with social-api.ai's error; a network error / 5xx is `failed` with
   "outcome unknown". **Retry** on the Inbox page (social.dispatch) re-runs a failed item (it asks
   first when a reply's outcome was unknown).
6. **Report.** The 08:00 IST daily report has a "Social inbox (last 24h)" section (read, replies
   posted, Flow tickets, leads, by type, reply failures, complaints waiting for Flow, high-severity
   items) and to-do lines when complaints wait for Flow or replies failed. Lodged complaints, leads,
   sent replies and hides are audited (`social.inbox.*`, fanned out to Console's central audit).

Run it by hand: `php spark reach:social-inbox` (poll + handle), `--poll-only`, `--process-only`,
`--item=<id>`, `--flow-check` (proves the Flow key: Flow must answer 422 to an empty body; nothing is
lodged), `--json`.

### Set it up (operator)

1. Deploy Flow with migration 045 and `FLOW_REACH_INBOUND_KEY` in Flow's `server-php/.env`
   (flow-react-app `docs/REACH_SOCIAL_TICKETS.md`). Put the **same** key in Reach → Settings → AI &
   Automation → **Social inbox** → *Flow key* (or `FLOW_REACH_INBOUND_KEY` in Reach's `.env`). Check:
   `php spark reach:social-inbox --flow-check`.
2. Run `php spark migrate` in Reach (table `reach_social_inbox`).
3. Run `php spark reach:integrations-check --only=social_api`. It also GETs each inbox list once
   (`social.inbox.list_path`, default `/inbox/comments,/inbox/conversations`) and should say
   `Inbox OK`. A 404 names the alternative paths that answer; an answer Reach cannot read is shown as
   its skeleton (`shape <path>`: key names and types, never values). Check `item fields <path>`
   against the assumptions below once real comments arrive.
4. Optionally set `social.inbox.alert_recipients`. Leave `social.inbox.hide_spam` off until the hide
   endpoint is confirmed.
5. Watch the first run on **Social → Inbox** (or `php spark reach:social-inbox --poll-only --json`
   first, which reads without answering). To read and classify without posting anything for a day,
   turn off *Post replies automatically*.

### Confirmed live (18 Oct 2026) and still assumed

Confirmed by the first live check: `GET /v1/inbox` is 404; comments are listed at
`GET /v1/inbox/comments` → `{data, pagination: {has_more}}` and direct messages at
`GET /v1/inbox/conversations` → `{data, pagination: {has_more, next_cursor}, sync_state,
last_synced_at}`; an empty list is `data: null`. Item fields are not confirmed yet (both lists were
empty). Everything below that is not in this paragraph is still an assumption. Reach parses
defensively, logs what it does not understand (`[SocialInbox]` in `writable/logs`) and never guesses:

1. **List**: every path in `social.inbox.list_path` (comma-separated; `{account_id}` in a path is
   substituted instead of the query) with `account_id`, `since` (RFC 3339), `limit`, `cursor` query
   parameters. Items from a conversations list count as direct messages unless they carry their own
   type; Reach resumes each list from its own last item. Unknown parameters are assumed to be ignored; Reach also de-duplicates, so a list that
   ignores `since` / `account_id` only costs reads.
2. **Shape**: a list, or a list under `data` / `items` / `results` / `messages` / `comments` /
   `conversations` / `threads` / `inbox` (also one level under `data`); conversation objects with a
   `messages` / `comments` / `replies` list are flattened. Item fields: id (`id` | `message_id` |
   `comment_id` | `item_id`), text (`text` | `message` | `content` | `body` | `comment` | `caption`,
   or `content.text`), type (`type` | `kind` → comment / message / mention / review), author
   (`author` | `from` | `sender` | `user` | `commenter` with `name` / `username` | `handle` | `id`),
   thread (`thread_id` | `conversation_id` | `post_id` | `post.id`), link (`post_url` | `permalink` |
   `url` | `post.url`, https only), time (`created_at` | `timestamp` | … ISO or epoch s / ms), ours
   (`direction: outbound` | `is_from_me` | `from_me` | …). Next page: `next_cursor` | `cursor` |
   `next` | `pagination.*` | `meta.*` | `paging.cursors.after`; `has_more: false` stops.
3. **Reply**: comments `POST /v1/inbox/comments/{id}/reply` (setting `social.inbox.reply_path`),
   direct messages `POST /v1/inbox/conversations/{thread_id}/messages` (setting
   `social.inbox.message_reply_path`; `{thread_id}` = the conversation id, else the message id), both
   with `{"text": "…"}`; a wrong path is a failed reply on Social → Inbox, never a second post; any 2xx without `success: false` = accepted; the reply id is read from
   `id` | `reply_id` | `message_id` when present. An `Idempotency-Key` header is sent (harmless if
   ignored). Moving a public complaint to DM is done in words (the acknowledgement asks them to DM or
   e-mail support@) — no DM-initiation endpoint is assumed.
4. **Hide**: `POST /v1/inbox/comments/{id}/hide` (setting `social.inbox.hide_path`), only with
   `social.inbox.hide_spam` on.
5. **Webhooks** for new comments are not used (signature scheme unknown) — Reach polls.
