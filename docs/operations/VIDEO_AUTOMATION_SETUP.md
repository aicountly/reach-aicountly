# Video automation — setup and operations

Every morning the `reach-video` Claude routine ([routine prompt](../automation/routines/reach-video.md))
writes one **vlog** (16:9, 2–6 min) and one **Short** (9:16, ≤ 60 s) and posts each script to
Reach. Reach then runs the whole pipeline with no human review:

```
routine ──POST v1/automation/video-scripts──▶ validate ─▶ Gemini vets (video_script rubric)
   revise → corrections applied, re-vetted once │ reject → stored with reasons, never rendered
   approve → approved by "Reach System Bot" (reach_approvals row + vetting run id)
        ▼  jobs on the `video` queue, one short step per cron run
  tts            OpenAI text-to-speech per scene (MP3 stored by Reach)
  visuals        product screenshots (Worker) / DALL·E stills / title cards + DALL·E thumbnail
  render_submit  Shotstack timeline (branded template, captions burned in) → render
  render_poll    Shotstack status (the render callback brings the poll forward)
  youtube_upload resumable upload, streamed from Shotstack's MP4 in 8 MB chunks
  youtube_extras thumbnails.set + captions.insert (SRT from the script)
  social         Shorts only: Instagram Reels / Facebook / LinkedIn posts in Reach's social queue
  done           status `published` with the real YouTube video id and URL
```

Reach → **Video → Pipeline** shows every item's step and status, the vetting decision and
reasons, warnings, a preview link to the render and the YouTube link, and has the
**Connect YouTube channel** button. **Video → Operations** shows what is configured.

States are honest: `blocked` = an operator must act (missing key, channel not connected or
access revoked) — blocked items are retried hourly and released immediately when a channel is
connected; `failed` = a step cannot succeed (the reason is shown; **Retry** is on the item);
`published` only after YouTube returned the video id.

## 1. Shotstack (rendering)

1. Create an account at <https://shotstack.io> → Dashboard → **API Keys**. There is a key for
   **Stage** (free, renders carry a Shotstack watermark) and one for **Production** (`v1`,
   billed per rendered minute).
2. Reach → Settings → AI & Automation → **Video**: paste the key into *Shotstack API key* and set
   *Shotstack environment* to `stage` for a trial, `v1` for real videos. (Env fallback:
   `SHOTSTACK_API_KEY`, `SHOTSTACK_ENV`.)
3. Shotstack must be able to fetch Reach's voice-overs and stills, so:
   - `MEDIA_SIGNING_KEY` must be set in `server-php/.env` (it signs the expiring
     `…/api/v1/public/video-media/{uuid}.mp3?exp=…&sig=…` URLs). Without it renders are blocked.
   - `REACH_APP_URL` must be the public Reach URL (`https://reach.aicountly.org`).
   - Optional: `VIDEO_MEDIA_STORAGE_PATH=/home/<user>/reach_video_media` (outside the deploy
     tree, like `MEDIA_STORAGE_PATH`). Voice-overs and stills are deleted once the video is on
     YouTube; only the thumbnail is kept.
4. Shotstack keeps rendered files for about 24 hours. Reach uploads straight from Shotstack and
   re-renders automatically (once) if the output expired before the upload finished. The MP4 is
   never stored on the cPanel host.
5. Render-finished callback (sent automatically with every render, no setup in Shotstack):
   `POST {REACH_APP_URL}/api/v1/public/video/shotstack/callback/{item}?sig=…` — the signature is an
   HMAC of the item (key: `REACH_SETTINGS_KEY`, else `JWT_SECRET`). Polling alone also works.

## 2. OpenAI (voice-over and stills) and Gemini (vetting)

- *OpenAI API key* (Settings → AI providers) is used for text-to-speech and DALL·E.
  *Voice-over model* defaults to `gpt-4o-mini-tts`, *Voice* to `coral` (any OpenAI voice name).
- *Gemini API key* + *Video script vetting model* (blank = default vetting model). Without a
  working vetting model scripts wait as `pending_vetting` and are re-vetted hourly — they are
  never rendered unvetted.

## 3. Google Cloud — YouTube Data API v3 and OAuth

1. <https://console.cloud.google.com> → create (or pick) a project, e.g. `aicountly-reach`.
2. **APIs & Services → Library → YouTube Data API v3 → Enable.**
3. **OAuth consent screen** (Google Auth Platform → Branding / Audience / Data access):
   - User type **External**; app name "AICOUNTLY Reach"; support and developer email; app domain
     `aicountly.org` / authorised domain `aicountly.org`; privacy policy and terms URLs.
   - Scopes: `https://www.googleapis.com/auth/youtube.upload` and
     `https://www.googleapis.com/auth/youtube.force-ssl` (captions and channel lookup need it).
     Both are sensitive scopes.
   - **Publishing status.** While the app is in *Testing*, only listed test users can connect
     and **Google expires the refresh token after 7 days** — the channel would disconnect every
     week. Move the app to **In production**. Because the scopes are sensitive, Google requires
     **app verification** (submit for verification: demo video of the consent flow, privacy
     policy, justification) before other users see an unwarned consent screen.
   - **YouTube audit.** Videos uploaded through an API project that has not passed YouTube's
     API Services audit are **locked to private** regardless of `privacyStatus`. Request the
     audit with the *YouTube API Services – Audit and Quota Extension* form. Until then Reach
     uploads succeed but YouTube keeps them private — Reach records the privacy YouTube actually
     applied and shows a warning on each item.
4. **Credentials → Create credentials → OAuth client ID → Web application.**
   - Authorised redirect URI (exactly):

     ```
     https://reach.aicountly.org/api/v1/public/video/youtube/oauth-callback
     ```

     (= `{REACH_APP_URL}/api/v1/public/video/youtube/oauth-callback`; Reach → Video shows the
     value it uses.)
   - Copy the client ID and secret into Settings → AI & Automation → Video (*YouTube OAuth
     client ID / secret*; env fallback `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET`).
5. The YouTube channel must be **phone-verified** (youtube.com/verify) for custom thumbnails;
   without it `thumbnails.set` is refused and Reach records a warning (YouTube picks a frame).

## 4. Connect the channel in Reach

Reach → **Video** → **Connect YouTube channel** (needs `video_connections.manage`) → sign in with
the Google account that owns the AICOUNTLY channel → allow → you return to Reach → Video with
"YouTube channel connected". Reach stores the refresh token encrypted
(`video.youtube.refresh_token`) and records the channel. **Disconnect** revokes it at Google.
If Google later revokes access, items block at the upload step and the channel shows the error —
connect again.

## 5. Settings (Settings → AI & Automation → Video)

| Key | Default | Meaning |
|---|---|---|
| `automation.video.enabled` | on | off → the routine gets HTTP 429 and stops |
| `video.daily.vlogs` / `video.daily.shorts` | 1 / 1 | daily quota (IST day); beyond it → 429 |
| `ai.vetting.model.video_script` | (default model) | Gemini model for the `video_script` rubric |
| `video.tts.model` / `video.tts.voice` | gpt-4o-mini-tts / coral | OpenAI TTS |
| `video.shotstack.api_key` (secret) / `video.shotstack.env` | — / stage | renderer |
| `video.music.url` | blank | optional royalty-free background MP3 (https) |
| `video.brand.logo_url` | aicountly.com logo | logo used on cards and as a corner mark |
| `video.youtube.client_id` / `client_secret` (secret) | — | OAuth web client |
| `video.youtube.refresh_token` (secret) | — | set by Connect |
| `video.youtube.privacy` | public | public / unlisted / private |
| `video.youtube.category_id` | 28 | 28 Science & Technology, 27 Education |

Screenshot scenes use the Worker (`WORKER_BASE_URL`, `WORKER_API_TOKEN`). If it is not
configured or a capture fails, the scene falls back to a branded title card and the item
carries a warning.

## 6. Cron (cPanel)

Add the video worker next to the existing lines (it has its own line so a ~80 s upload step never
delays the other queues; `reach:schedule` already runs the hourly video sweep):

```cron
* * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:work --queue=video --once --limit=3 --lease=300 --worker-id=video >> writable/logs/worker-video.log 2>&1
```

Then create the routine (`reach-video`, Sonnet, `30 1 * * *` UTC = 07:00 IST) from the prompt in
[`docs/automation/routines/reach-video.md`](../automation/routines/reach-video.md).

A vlog takes roughly: TTS 1–3 cron runs, visuals 2–6 runs (DALL·E ~20 s per still), render
3–10 min at Shotstack, upload 1–10 runs depending on size — typically published 20–40 minutes
after the script arrives.

## 7. Quotas

YouTube Data API default quota: **10,000 units per day** per project.

| Call | Units | Per video |
|---|---|---|
| `videos.insert` (resumable upload) | ~1,600 | 1 |
| `captions.insert` | 400 | 1 |
| `thumbnails.set` | 50 | 1 |
| `channels.list` | 1 | only on connect |

≈ 2,050 units per video → two videos a day use ~4,100 units. A quota error leaves the item
in progress and retries the step hourly. Raise `video.daily.*` above ~4 videos/day only after
requesting a quota extension.

## 8. Social offer for Shorts

Each published Short creates three `reach_social_posts` rows (instagram, facebook, linkedin),
approved by the System Bot, with the YouTube Shorts URL and Shotstack's 9:16 MP4 URL (valid ~24 h)
in `media_refs`. They go through the existing social publisher seam: until socialapi.ai is
integrated it is fail-closed, so the posts wait in Reach’s social manual queue (status `manual_queue`) —
nothing is marked posted.

## 9. Development

`VIDEO_PIPELINE_MOCKS=1` swaps in mock TTS / renderer / YouTube / visuals (mock render URLs are
`mock://…`, mock video ids start with `MOCK`). Every mock refuses to run in production: a
production item with mocks enabled is `blocked`, never published.

## 10. Not verified against the live services

To verify the credentials and the services from the Reach server, run
`php spark reach:integrations-check` (add `--only=shotstack --render-test` for a 3-second test
render that exercises the `text` asset and the Montserrat font) — see
[LIVE_INTEGRATION_CHECKS.md](LIVE_INTEGRATION_CHECKS.md). The same checks (without the render)
are behind **Run live checks** on Settings → AI & Automation.

The pipeline is covered by unit tests with scripted HTTP and DB tests with mock providers; it has
**not** been run against live Shotstack, OpenAI TTS, Google OAuth or YouTube from this build.
Check on the first real run: Shotstack's acceptance of the `text` asset fields and the fonts
(`Montserrat ExtraBold` / `Montserrat SemiBold`), the `poster` output option, the
`containsSyntheticMedia` upload flag, and that the Worker exposes `POST v1/screenshot`
(WorkerPlaywrightClient's contract — the worker repository only shows a pull-based session
runner; if it is missing, screenshot scenes become title cards with a warning).
