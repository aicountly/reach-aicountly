# Bot fixes: blocked articles and "What to do next"

The bot does not only report problems on the Command centre — it fixes the ones it safely can,
on its own or when you press the button. Nothing is published without Gemini vetting it; the
bot hands writing to the Claude routines, exactly as for any new article.

Settings: **Settings → AI & Automation → Bot fixes**.

## Routine articles blocked from publishing

A blog or help article written by a routine can stop between vetting and aicountly.com. Reach
records why (`block_kind` on `reach_routine_submissions`) and `RoutineRemediator` takes one step
at a time — every 5 minutes after the routine content sweep, when
`automation.remediation.auto_fix_blocked` is on (default), or at once from the buttons.

| Why it stopped (`block_kind`) | Bot's next step |
|---|---|
| `site_refused`, `publish_refused`, `vetting_unavailable` (outside the text) | *Approved, publish blocked*: retried automatically with back-off. *Publish failed*: **retry** after 6 hours, at most twice, then a person. |
| `version_mismatch`, `not_approved` (the text changed after it was approved) | **re-vet** the current text; Gemini approves it (with its corrections) and the approval is bound to that version. |
| `vet_refused` (Gemini's publication check refused the text) | **re-vet** first; if that does not get it through, **rewrite**: the blog / KB routine writes a fresh version on the same topic and URL with Gemini's reasons (`reach_content_regenerations`, status *Waiting for the bot's rewrite*). |
| A re-vet Gemini rejects | **rewrite**. |
| `approval_revoked`, *Held: high risk*, `rewrite_failed`, 4 steps tried | **a person** — the bot does not override a person or a high-risk hold. The buttons still work. |

Before this, a refusal at the publication check was retried every 30 minutes on the same words
(12 Gemini calls) and a "publish failed" article had no way back except a manual Retry that
failed again.

Where: Blog Command Centre → **Routine Articles** (blogs) and Publishing → **Knowledge bases**
(help articles). The Command centre card opens the right one, filtered to the blocked ones.
Per row: **Retry**, **Re-vet & publish**, **Ask bot to rewrite** (with an optional note for the
routine); in the header **Let the bot fix N blocked**. API:
`POST v1/routine-submissions/{id}/remedy {step: retry|revet|rewrite, note?}`,
`POST v1/routine-submissions/fix-blocked` (`publishing.publish`).

## "What to do next" cards

| Card | "Let the bot fix it" |
|---|---|
| Push "<query>" onto page one | A content-base topic (`origin = signal`, priority 100) for one focused article, first in the blog routine's queue, linking to the pages that already rank. |
| Retitle a page people see but do not click | A same-URL rewrite by the blog / help routine aimed at the title, meta description and opening, with the queries the page shows for. Only for pages Reach published; others say to change the title in the aicountly.com code. |
| AI Insights recommendation | One reach-command run that does what the automation API allows and reports in Bot → Ask the bot. |
| <Routine> has been quiet | **Run it now** (Bot → Activities). |
| N routine blogs / help articles blocked | **Let the bot fix them** (above). |

`POST v1/dashboard/command-centre/fix {card_id}` (`bot.run`). The card is looked up again from
live data; every fix is recorded in `reach_recommendation_fixes` and shown on the card. A card is
not fixed again for 45 days (Search Console reports 28-day windows, so a new title needs weeks
to show), an insight only once.

**AUTO mode**: hourly (`reach.recommendation_autofix`, also under Bot → Activities → Run now) the
bot fixes these cards itself when the bot is in AUTO mode and
`automation.remediation.auto_recommendations` is on, within
`search_topics_per_day` (1), `retitles_per_week` (2) and `insight_commands_per_day` (1). In
CONFIRM mode nothing happens until someone presses the button.
