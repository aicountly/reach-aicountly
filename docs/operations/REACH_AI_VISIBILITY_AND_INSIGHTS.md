# AI Visibility (GEO) and AI Insights — operations

## What runs, and when

| When | Job | What it does |
|---|---|---|
| 05:00 IST daily | `reach.ai_visibility_daily` (queued by `reach:schedule`) | Plans today's pass: each active prompt (Intelligence → Prompt Library) × each configured assistant becomes one `intelligence.visibility.execute` job. The worker cron runs them. |
| Sunday 16:00 IST | `reach.ai_insights_run` (queued by `reach:schedule`) | Builds the data digest and asks the AI Insights model for the weekly report. |
| Sunday 18:00 IST | Claude routine `reach-growth-strategy` | Reads `GET v1/automation/insights-digest`, writes the strategy memo, `POST v1/automation/strategy-memos`. See `docs/automation/routines/reach-growth-strategy.md`. |

No new cron lines: the existing `reach:schedule` (every minute) and `reach:work` (default queue) crons
cover both jobs. Each scheduled job is queued once per IST day / Sunday (idempotency key, any state).

## Settings (Settings → AI & Automation → "AI visibility & insights")

| Key | Default | Meaning |
|---|---|---|
| `visibility.enabled` | on | daily pass on/off (manual Run now works either way) |
| `visibility.daily_prompt_limit` | 25 | cost cap: prompts asked per IST day, across all passes; prompts not reached run first next day |
| `visibility.providers` | `gemini,openai,perplexity` | assistants to ask; one without a key is skipped and shown as skipped with the reason |
| `visibility.brand_aliases` | `AICOUNTLY,aicountly.com,AI Countly` | names that count as an AICOUNTLY mention |
| `visibility.gemini.search_grounding` | on | Gemini answers grounded on Google Search (closer to the Gemini app, returns sources; billed per grounded call) |
| `ai.openai.chat_model` | env `AI_OPENAI_DEFAULT_MODEL`, else `gpt-5-mini` | OpenAI model; plain chat models answer from training data, not a live search |
| `ai.perplexity.model` | env `AI_PERPLEXITY_DEFAULT_MODEL`, else `sonar` | Perplexity model |
| `insights.weekly.enabled` | on | weekly AI Insights report |
| `insights.site_url` | `https://aicountly.com` | site probed for llms.txt, JSON-LD, robots.txt, sitemap |

Gemini visibility checks and AI Insights use `ReachConfig::vettingModel('insights')` (the "AI Insights
model", blank = default vetting model) and the Gemini key.

## How the numbers are made

- Every answer is stored verbatim (`reach_ai_visibility_responses`, kept 400 days). `MentionParser`
  records an AICOUNTLY observation on every answer (mentioned or not), one per competitor named
  (`reach_competitors` + `reach_competitor_aliases`), the rank when the name leads an item in a
  numbered/bulleted list, table or heading, and every cited URL.
- Mention rate = answers naming the name ÷ answers. Share of voice = that name's mentions ÷ all tracked
  names' mentions. Names that appear in the question itself are excluded from that answer.
- Sample caveat (shown in the UI and the digest): one API answer per prompt per assistant per day; API
  answers differ from the consumer apps and vary run to run.
- AI Insights may only use digest numbers. Figures in its report that Reach cannot trace to the digest
  are listed as "unverified" beside the report. Checklist items Reach observed on the live site keep
  the observed status whatever the model says; an unreachable check is "unknown", never "missing".
- Not configured → a pass is recorded as `skipped` with the reason, a report as `failed` with the
  reason; Run now answers 409 / 429 with the reason. Nothing reports success it did not have.

## Commands

```
php spark reach:ai-visibility status            # which assistants run / why others are skipped
php spark reach:ai-visibility run [--sync]      # manual pass now (respects the cap)
php spark reach:ai-visibility summary --days=7
php spark reach:ai-insights digest              # the digest the model would see (no AI call)
php spark reach:ai-insights run                 # report now
php spark reach:ai-insights latest
```

## For the daily report

`(new \App\Libraries\Intelligence\Insights\GrowthIntelligenceSummary())->forDailyReport()` returns
`available`, AI visibility for the last 24 h and 7 days per assistant, the latest pass, the latest
insights summary with its unverified-figure count, open actions and the latest memo. It returns
`available: false` with a reason (never throws) when these tables are not migrated.
