# AI vetting and Settings → AI & Automation

## What vetting does

Content produced by the Claude Routines (community Q&A, blogs, knowledge base, release notes,
workflow guides) is checked by a Gemini model before anything is published.
`App\Libraries\Ai\Vetting\ContentVettingService::vet($type, $draft, $context)` sends the draft
with that content type's rubric (`App\Libraries\Ai\Vetting\Rubrics`) and gets back one of:

| Decision | Meaning | What callers do |
|---|---|---|
| `approve` | publishable as submitted | publish `$result->final` (the draft unchanged) |
| `revise`  | publishable after the model's corrections | re-vet `$result->final` once; publish only if that comes back `approve` |
| `reject`  | not publishable | retire the draft with `$result->reasons` |

If vetting cannot reach a verdict — no Gemini key, provider error, or unusable output (retried
once) — `VettingUnavailableException` is thrown and the content stays pending. Unvetted content
is never published.

Every call is recorded in `reach_ai_vetting_runs` (model, decision, reasons, scores, the final
text, latency, error) and listed at `GET /api/v1/ai/vetting-runs`. That table is the review
record.

The rubrics are the editorial policy. Change them in `Rubrics.php`; the unit test
`tests/Unit/Ai/Vetting/ContentVettingServiceTest.php` pins their key rules.

## Settings → AI & Automation (`/admin/ai-automation`)

Super admins and Reach admins (`settings.manage`) can change, without a code change or deploy:

- **AI provider keys** — Gemini, OpenAI, Perplexity. Stored encrypted (libsodium secretbox, key
  derived from `REACH_SETTINGS_KEY`, else `JWT_SECRET`). Write-only: the API reports only whether
  a key is set and whether it comes from settings or the server `.env`. Clearing a saved key
  falls back to the `.env` value.
- **Vetting model** — a default plus an optional override per content type (community, blog,
  knowledge base, release notes, workflow guides) and for AI Insights. The field offers the
  models your Gemini key can use (`GET /api/v1/settings/automation/gemini-models`); any id is
  accepted. Use **Test gemini** to confirm the key works and the chosen model is available.
- **Image model** — OpenAI image model (default `dall-e-3`) and size.
- **Automation** — on/off and daily volume per content type.
- **Reports** — daily analytics report recipients (default `pno@aicountly.com`).
- **SEO data** — DataForSEO login/password. **Social** — socialapi.ai key and base URL.

After a provider key is added or removed, `reach:schedule` (every minute) re-seeds the AI
catalog so the provider becomes routable — no shell needed.

`ReachConfig` (`app/Libraries/Settings/ReachConfig.php`) is the only way code reads these
values: `ReachConfig::get()` for plain values, `ReachConfig::secret()` for keys,
`ReachConfig::vettingModel($type)`.

Note: if `REACH_SETTINGS_KEY` (or `JWT_SECRET`, when it is the key source) changes, saved keys
can no longer be decrypted; they read as unset and fall back to `.env`. Re-enter them.
