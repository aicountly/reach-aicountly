# Bot actions — Run now and Ask the bot

Every bot activity in Reach keeps running on its own schedule. Two pages let the owner act
**now** as well:

| Page | What it does | Permission |
|---|---|---|
| **Bot → Activities** (`/bot/activities`) | Lists every scheduled / automated activity — schedule in IST and UTC, last run and its status, next run, whether it is safe to run now, the cost — with a **Run now** button. | view: `bot.view`, `bot.run` or `settings.view`; run: `bot.run` or `settings.manage` |
| **Bot → Ask the bot** (`/bot/ask`) | The owner types an instruction ("Post a 30-second soft-launch reel announcing Aicountly on all connected social platforms today"); Reach fires the **reach-command** Claude Routine once with it; the routine does the work through Reach's automation API and reports back. History with session links, live status of what was made. | view: `bot.view`; send: `bot.command` or `settings.manage` |

`super_admin` holds everything (`*`); migration `2026-10-18-290001` adds `bot.run` and
`bot.command` to `reach_admin`. Both are in the `bot` permission group
(`App\Config\Permissions`).

## One-time setup (owner) — the reach-command routine

Run now for jobs and cron commands works without any setup. **Ask the bot** and Run now for the
**Claude routines** need the reach-command routine:

1. **Create the routine.** claude.ai → Code → **Routines** → New routine:
   - Name `reach-command`, model **Sonnet**, fresh session per fire.
   - Environment: the same one the other Reach routines use (repositories
     `aicountly/reach-aicountly`, `aicountly/aicountly-com` and the product repos; network access to
     `reach.aicountly.org` and the Reach network secret — see
     [`docs/automation/CLAUDE_ROUTINES.md`](../automation/CLAUDE_ROUTINES.md) § Environment prerequisites).
   - Prompt: copy the block under **Prompt** in
     [`docs/automation/routines/reach-command.md`](../automation/routines/reach-command.md).
   - No schedule is needed (remove the default one if the form adds it).
2. **Add an API trigger.** Open the routine → **Add another trigger** → **API** → Save. Copy the URL
   it shows: `https://api.anthropic.com/v1/claude_code/routines/trig_…/fire`.
3. **Generate a token** for that trigger and copy it (`sk-ant-oat01-…`). claude.ai shows it once.
4. **Paste both into Reach.** Settings → **AI & Automation** → **Bot commands**:
   - *reach-command routine: API trigger URL* (`bot.command.routine_fire_url`, env fallback
     `BOT_COMMAND_ROUTINE_FIRE_URL`) — only an `api.anthropic.com/v1/claude_code/routines/trig_…/fire`
     URL is accepted.
   - *reach-command routine: API trigger token* (`bot.command.routine_token`, secret, env fallback
     `BOT_COMMAND_ROUTINE_TOKEN`) — stored encrypted, never shown again.
   Save. Bot → Ask the bot now shows the form instead of the setup steps.
5. **Try it**: Bot → Ask the bot → "Run the reach-blog routine now and tell me what it published",
   or the soft-launch example. The history row shows **Sent to the bot** with a **Claude session**
   link; the routine's report (summary + links) appears on the same row when it finishes.

If the token is regenerated in claude.ai, paste the new one (the old one stops working: the
command shows *The routine token was refused (401)*).

Optional settings in the same section: *Accept routine social posts* (`automation.social.enabled`,
on), *Routine social posts per day* (`automation.social.daily_limit`, 6 — posts tied to an owner
command count against that command instead), *Social post vetting model*
(`ai.vetting.model.social_post`, blank = default vetting model).

## Run now (Bot → Activities)

`GET v1/bot/activities` returns the registry (`App\Libraries\Bot\BotActivityRegistry`) with live
status; `POST v1/bot/activities/{key}/run` runs one. **A manual run is never a different code
path:**

| Kind | Run now does | Last run shown |
|---|---|---|
| `job` — enqueued by `reach:schedule` / GrowthIntelSchedule (routine sweep, social poll, covers, Search Console ingest, SEO snapshot, SEO Tracker, daily report, AI visibility, AI Insights, video sweep, content-base sync, reminders…) | enqueues the **same job type on the same queue** with the payload the scheduler would build now (key `bot-run-<activity>-<uuid>`, actor = the owner) | the latest job of that type, scheduled or manual, with a link to Job Monitor |
| `command` — a cron line (`reach:schedule`, `reach:blog-dispatch --force`, `reach:blog-discover-topics --limit=10`, `reach:blog-optimize-roadmap --force`, `community:schedule`, `community:agents-run`, `reach:console-audit-deliver`) | queues `reach.bot_activity_command`; the worker runs exactly that `php spark …` line as a child process (no shell, allow-listed by the registry, 240 s limit, a non-zero exit fails the job with the output) | the manual run's job; cron runs are not recorded in Reach — the page shows when the cron log file was last written |
| `routine` — reach-blog, reach-kb, reach-community-qa, reach-video, reach-release-notes, reach-workflow-guides, reach-growth-strategy | fires reach-command with "run `<routine>` now: carry out the prompt in its prompt file once" | the manual run's command and session link; scheduled runs happen in claude.ai and are not visible to Reach |
| `worker` — the queue workers | not runnable (the cron worker already runs every minute) | — |

Guards: an **idempotency key per click** (a retried request returns the first run), one manual run
of an activity in flight at a time (409 *busy*), a cooldown between manual runs (60 s; 10 minutes
for routines; 429 with `Retry-After`), the `throttle:bot_run` route limit (20/min per user), and an
audit event `bot.activity.run` with the owner as actor (fanned out to Console). The window-gated
blog commands run with `--force` (their documented manual flag). Activities with a cost carry a
note (DataForSEO SERP calls, AI calls, emails) and the page asks for confirmation.

Run-now jobs are picked up by the cron worker within a minute (`reach:work … --once`); the video
sweep by the video worker.

## Ask the bot (owner commands)

```
Owner (Bot → Ask the bot)
  └─ POST v1/bot/commands {instruction, channels?, attachments? (https URLs)}
       1. validate; store the command (status `firing`, unique idempotency key) — BEFORE firing
       2. build the payload: instruction verbatim, target channels, attachments, rules, and
          context JSON (today IST, channel readiness from social-api.ai, video pipeline readiness,
          brand facts from the content base, the Reach automation endpoints, plan briefing
          endpoint if deployed)
       3. POST https://api.anthropic.com/v1/claude_code/routines/{trig}/fire  (once)
       4. store the outcome: submitted + session URL | failed + reason | outcome_unknown
  └─ reach-command routine (claude.ai session)
       GET  v1/automation/commands/{id}          verify: act on Reach's stored instruction
       POST v1/automation/video-scripts          reel/Short/vlog (command_id, social_channels)
       POST v1/automation/social-posts           text/image/video posts
       POST v1/automation/videos/{uuid}/distribute   a published video to more networks now
       … blog-drafts, kb-drafts, community-qa, release-notes, workflow-guides …
       POST v1/automation/commands/{id}/report   {status, summary, links}
  └─ Reach: Gemini vets each submission (final), System Bot approves, publishes;
     Bot → Ask the bot shows the report and the live status of every video / post made.
```

**Never double-fires.** The Routines API has no idempotency key — each accepted call is a new
session. Reach writes the command row before firing; a repeated request with the same key (double
click, browser retry) returns that row; only the request that wrote it fires, once. The only
automatic re-send is when the connection never opened (DNS / connect / TLS failure, so nothing
reached Anthropic). Everything else is recorded, not retried:

| Answer | Command status | What to do |
|---|---|---|
| 200 | `submitted` + session URL | nothing — the report arrives on the row |
| 400 | `failed` | the routine is paused, the text too long or the API version changed (message shown) |
| 401 | `failed` | regenerate the trigger token in claude.ai, paste it in Settings |
| 403 / 404 | `failed` | check the URL and token belong to the reach-command API trigger |
| 429 | `failed`, retry time stored | 30 fires/hour per routine, 100/hour per account; send again later |
| 502 / 503 / 529 | `failed` (retryable) | Anthropic did not start a session; send again in a few minutes |
| 500 / 504 / timeout after sending | `outcome_unknown` | a session may have started — look in claude.ai → Routines → reach-command before sending again |

Reach keeps under the routine's limit: at most 25 commands per rolling hour (429 otherwise) and
the `throttle:bot_command` route limit (6/min per user). A command stuck in `firing` for 3 minutes
(Reach stopped mid-request) becomes `outcome_unknown`. There is no status API for a routine
session: Reach keeps the session URL and the routine's report.

**Report statuses** (`POST v1/automation/commands/{id}/report`, automation token): `in_progress`,
`completed`, `partial`, `failed`, with a summary (≤ 8000 chars) and https links (≤ 30); up to 20
reports per command; a command whose fire failed cannot be reported on (409).

### What the soft-launch reel goes through

`POST v1/automation/video-scripts` with `format: "short"`, `command_id` and
`social_channels: ["instagram","facebook","linkedin","x"]` → script validation → Gemini vets
(`video_script` rubric) → System Bot approval → OpenAI TTS → visuals → Shotstack render →
YouTube (Shorts, via social-api.ai by default) → the `social` step offers the Short to every listed
network through social-api.ai (one `reach_social_posts` row per network, `publishing` → `posted`
only when social-api.ai reports it published; a network without an account waits in the manual
queue). Owner-command videos do **not** use the reach-video routine's daily quota
(`video.daily.shorts`), at most 3 scripts per command, while the command is open and under 48 h
old. The routine reports the `item_uuid`; `GET v1/automation/videos/{uuid}` and the Ask-the-bot
history show the live pipeline and post statuses. A video already published can be offered to more
networks with `POST v1/automation/videos/{uuid}/distribute` (a network that already has a live post
for it never gets a second one; a failed one is dispatched again). Shotstack keeps the rendered MP4
about 24 hours, so distribute a video to more networks within a day.

`POST v1/automation/social-posts` — `{external_key, command_id?, channels, text, title?, link?,
hashtags?, media_urls? (https image / video files)}` → Gemini vets (`social_post` rubric: no
invented claims, no hype, AI disclosure kept, X ≤ 280 chars) → System Bot approval (one
`reach_approvals` row per post) → one post per network, dispatched through social-api.ai.
Instagram needs an image or video; YouTube needs a video (use video-scripts). Re-sending an
`external_key` returns the first result; one still `pending_vetting` (Gemini unavailable) is
vetted again.

## Troubleshooting

- **Run now stays `pending`**: the cron worker is not running that queue
  (`REACH_WORKER_AND_CRON.md` — every `--queue=` list must include the queue shown on the row).
- **Command activity failed with "proc_open is disabled"**: the host blocks child processes for
  PHP; run the command from the cPanel terminal instead.
- **Ask the bot says "not set up yet"**: follow the setup above; the problems line names what is
  missing.
- **The routine never reports back**: open the Claude session link; the routine may have stopped on
  a Reach answer (429 quota, 403 switched off). Reach shows the videos / posts it made either way.
