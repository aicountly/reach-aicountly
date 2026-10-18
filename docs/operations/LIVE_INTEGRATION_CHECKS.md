# Live integration checks

`php spark reach:integrations-check` calls each third-party service the automation pipelines
depend on, using the credentials saved in **Settings → AI & Automation** (or their `.env`
fallbacks), and prints `OK` / `FAIL` with a one-line reason for each. It is the way to prove
Shotstack, YouTube, DataForSEO, Gemini, OpenAI and OpenAI TTS work **from the Reach server**:
the build and CI have no credentials and cannot reach those hosts.

Code: `server-php/app/Libraries/Settings/LiveIntegrationCheck.php` (shared by the command and
the UI button), `server-php/app/Commands/ReachIntegrationsCheck.php`. Tests:
`tests/Unit/Settings/LiveIntegrationCheckTest.php` (scripted HTTP — every check for OK, auth
failure, network failure; render done / failed / timeout) and
`tests/Feature/Settings/AutomationSettingsApiTest.php` (the endpoint).

## Running it on the server

Run as the cPanel account user, from the API directory, so the command reads the same `.env` and
database as the site:

```bash
# every check (no render, nothing uploaded)
su -s /bin/bash reachaicountly -c "cd /home/reachaicountly/public_html/api && /usr/local/bin/php spark reach:integrations-check"

# some checks only
su -s /bin/bash reachaicountly -c "cd /home/reachaicountly/public_html/api && /usr/local/bin/php spark reach:integrations-check --only=shotstack,youtube,dataforseo"

# plus a 3-second Shotstack test render on the configured environment (waits up to 3 min)
su -s /bin/bash reachaicountly -c "cd /home/reachaicountly/public_html/api && /usr/local/bin/php spark reach:integrations-check --only=shotstack --render-test"

# machine-readable (--no-header drops CodeIgniter's banner line)
su -s /bin/bash reachaicountly -c "cd /home/reachaicountly/public_html/api && /usr/local/bin/php spark reach:integrations-check --json --no-header"
```

| Option | Meaning |
|---|---|
| `--only=a,b` | Run only these checks: `shotstack`, `youtube`, `dataforseo`, `gemini`, `openai`, `tts`. Default: all. An unknown name exits 2. |
| `--render-test` | Also submit a 3-second title-card render (Shotstack). Needs the `shotstack` check. |
| `--json` | Print the report as JSON (`ok`, `passed`, `failed`, `results[]` with `target`, `ok`, `message`, `detail`, `duration_ms`). |

Exit code: **0** every requested check passed · **1** at least one failed · **2** usage error.

Example output:

```
Reach live integration checks — shotstack, youtube, dataforseo, gemini, openai, tts
  OK    shotstack   Key accepted on the stage environment (stage, free/watermarked).
        environment: stage
        billing: stage — renders are free and watermarked
        templates: 0
  OK    youtube     Token refresh OK; channel “AICOUNTLY” (UCxxxxxxxx). Long uploads: allowed. Uploads will be requested as public.
        channel_privacy: public
        long_uploads: allowed
        custom_thumbnails: likely allowed — the channel is verified (long uploads allowed); …
        scopes: youtube.upload youtube.force-ssl
        upload_privacy: public
        upload_privacy_note: Reach asks YouTube for privacyStatus=public … YouTube locks every video uploaded through an unverified Google Cloud API project to private …
  OK    dataforseo  Connected. Balance: $48.12
  OK    gemini      Connected. Vetting model gemini-2.5-flash is available.
  OK    openai      Connected. Image model dall-e-3 is available.
  OK    tts         Synthesised 17,280 bytes of MP3 (1.1 s) with gpt-4o-mini-tts / coral.
6 passed, 0 failed.
```

## What each check does

| Check | Call(s) | Cost |
|---|---|---|
| `gemini` | `GET /v1beta/models` with the Gemini key; confirms the vetting model is offered | free |
| `openai` | `GET /v1/models`; confirms the image model (`ai.image.model`) is available to the key | free |
| `dataforseo` | `GET /v3/appendix/user_data` (Basic auth); prints the balance | free |
| `shotstack` | `GET https://api.shotstack.io/edit/{env}/templates` with `x-api-key`, where `{env}` is `video.shotstack.env` (`stage` or `v1`) | free |
| `shotstack --render-test` | `POST /edit/{env}/render` with one 3-second `text` clip in the pipeline's font (Montserrat ExtraBold, navy on white, 1280×720 MP4), then `GET /edit/{env}/render/{id}` every 5 s until `done`/`failed` or 180 s. Prints the output URL (Shotstack hosts it ~24 h). | **stage: free, watermarked. v1: billed** (3 s of render) |
| `youtube` | OAuth token refresh with the saved client id / secret / refresh token (`YouTubeOAuth`), then `channels.list?part=snippet,status&mine=true` (`YouTubeDataClient`). **Never uploads.** | 1 quota unit |
| `tts` | `POST /v1/audio/speech` for “Reach live check.” with `video.tts.model` / `video.tts.voice`; reports the MP3 size and length. The audio is discarded. | ~17 characters of TTS |

The checks always use the real providers, even if `VIDEO_PIPELINE_MOCKS` is set.

### YouTube details

- **long_uploads** is the channel's `status.longUploadsStatus` (`allowed`, `eligible`,
  `disallowed`). Vlogs are under 15 minutes, so this matters only as a sign of verification.
- **custom_thumbnails** is not exposed by the API. YouTube allows custom thumbnails once the
  channel is phone-verified (youtube.com/verify) — the same verification that turns long uploads
  to `allowed`, so the check reports “likely allowed” in that case. Without it `thumbnails.set`
  fails and the pipeline records a warning.
- **upload_privacy** is `video.youtube.privacy`. Google locks every video uploaded through an
  **unverified** API project to *private* until the Google Cloud project passes the YouTube API
  audit. The API does not reveal whether this applies, so confirm the first upload's visibility in
  YouTube Studio and the OAuth consent screen's verification status in Google Cloud.
- **scopes**: the check fails if Google's token response omits `youtube.upload` or
  `youtube.force-ssl` — reconnect the channel in Reach → Video and tick every box.

## From the UI

**Settings → AI & Automation → Live integration checks → Run live checks** (users with
`settings.manage`, i.e. super admins / Reach admins) calls
`POST /api/v1/settings/automation/live-check` — the same code, with every HTTP call capped at
15 s. Body (optional): `{"only": ["youtube", "tts"]}`. The run is audited as
`settings.automation_live_check` (targets and which failed) and throttled by the `integration`
rate limit. The Shotstack test render is **CLI-only** (`{"render_test": true}` is refused with
422): it waits minutes and may be billed.

## Secrets

No result carries a credential. Messages come from the provider clients (which already redact
`Bearer …` / `sk-…`), and every message and detail is then scrubbed of the configured secret
values (all `secret` settings, the DataForSEO Basic-auth string and the YouTube access token
minted during the run). The DataForSEO login and the YouTube client id are not printed either.

## Reading a FAIL

| Message | Meaning |
|---|---|
| `… is not set` / `No credential saved` | Nothing in Settings and nothing in `.env`. |
| `Could not reach X (network error: …)` | DNS / TLS / firewall / timeout from the server — not a credential problem. |
| `Shotstack rejected the key for the stage environment (HTTP 401/403)` | Wrong key, or a production key used with `stage` (or the reverse): Shotstack's stage and v1 keys differ. |
| `YouTube access was revoked or expired (invalid_grant)` | Reconnect the channel in Reach → Video. Unverified OAuth apps in *Testing* mode issue refresh tokens that expire after 7 days. |
| `Test render timed out after 180 s … render id …` | Shotstack is still working; look the id up in the Shotstack dashboard. |
| `Test render failed: …` | Shotstack's reason (e.g. a font or asset it could not use). |
