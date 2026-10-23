# Routine: `reach-community-qa` — community questions and desk answers

| | |
|---|---|
| **Name** | `reach-community-qa` |
| **Model** | Claude Sonnet |
| **Cron (UTC)** | `30 2-14/2 * * *` — 02:30, 04:30 … 14:30 UTC |
| **Cron (IST)** | every 2 hours, 08:00 – 20:00 IST (7 runs a day) |
| **Session** | fresh session per fire |
| **Repos in the environment** | `aicountly/reach-aicountly`, `aicountly/aicountly-com`; attach product repos with `add_repo` when a question needs them: `aicountly/books-react-app` (Smart Books), `aicountly/billing-aicountly` (Billing), `aicountly/Inventory-aicountly` (Inventory), `aicountly/notes-aicountly` (Notes), `aicountly/drive-react-app` (Drive) |
| **Environment** | network access to `reach.aicountly.org` + the Reach network secret (see `../CLAUDE_ROUTINES.md`) — no environment variables needed |

## Endpoints used

All calls: no auth header of your own — the environment's network secret adds `Authorization: Bearer <token>` to every reach.aicountly.org/api/v1/automation/ request.

| Endpoint | Purpose |
|---|---|
| `GET  https://reach.aicountly.org/api/v1/automation/community-plan` | today's remaining quota, `max_this_run`, `reanswer_queue` / `max_reanswers_this_run` (live answers to replace — no quota), open questions still waiting for an answer (re-answers first, then members), the last 200 question titles, the topic mix and category/product slugs |
| `GET  https://reach.aicountly.org/api/v1/automation/plan/briefing?type=community` | read first: today's plan, yesterday vs plan, top 3 lags with causes and what the bot changed, vetting reasons to avoid, topic mix |
| `GET  https://reach.aicountly.org/api/v1/automation/community/pending?limit=10` | the Question Inbox's routine queue: `{enabled, remaining_today, max_this_run, questions: [{question_id, title, body, category_slug, product_slug, asked_by, asked_at, queued_at, thread_url, triage_score, risk, previous_attempts, reanswer, answer_endpoint}]}` (`thread_url` is set for a real member's question from aicountly.com; the answer is posted on that thread) — **re-answer items first** (`reanswer: true`, plus `reanswer_id`, `replaces {answer_id, state: live\|withdrawn, public_url}`, `why` and `rules`: a live answer re-vetting found wrong; does not use the quota), then members first, then highest triage score, then oldest; one per question text. Reach settles the inbox before answering (answered questions marked published, duplicates and unanswerable questions closed, stuck drafts handed over) |
| `POST https://reach.aicountly.org/api/v1/automation/community/pending/{question_id}/answer` | the desk's answer to that inbox question: `{answer_html, short_answer?, source_references?, provenance}` — the same vetting → approval → publish path as `community-answers`. For a re-answer item it replaces the existing answer at the same URL and does not count against the quota |
| `POST https://reach.aicountly.org/api/v1/automation/community/pending/{question_id}/decline` | `{reason}` — you cannot answer it truthfully (e.g. the product code does not show how); Reach closes it with your reason so it does not wait for ever |
| `POST https://reach.aicountly.org/api/v1/automation/community-answers` | the desk's answer to an open question: `{question_id, answer_html, short_answer?, source_references?, external_key?, provenance}` |
| `POST https://reach.aicountly.org/api/v1/automation/community-qa` | a new frequently-asked question with the desk's answer: `{external_key, question_title, question_body, category_slug, product_slug?, answer_html, short_answer?, source_references?, provenance}` |
| `GET  https://reach.aicountly.org/api/v1/automation/content-base/next?type=community` | planned question topics (git seeds and proposals), each with the `content_base_key` to use as `external_key` |
| `POST https://reach.aicountly.org/api/v1/automation/content-base/produced` | report a planned question you answered (`submitted`) or one that no longer applies (`not_applicable` + reason) |
| `POST https://reach.aicountly.org/api/v1/automation/content-base/propose` | optional: add question topics with an official source as evidence |

`provenance` is `{"generated_by_model": "claude-sonnet", "routine_run_id": "<this session id>"}`.
`source_references` is a list of `{"title": "...", "url": "https://..."}` (official sources: gst.gov.in,
cbic-gst.gov.in, incometax.gov.in, mca.gov.in, epfindia.gov.in, the AICOUNTLY site …).

What Reach does with a submission: it creates the question as the relevant AICOUNTLY desk's own FAQ post
(or attaches the answer to the member's question), runs its automatic checks, then has the vetting model
(Gemini, community rubric) decide. **approve** → approved under the Reach System Bot and published to
aicountly.com/community, labelled "AI-assisted · AICOUNTLY Desk"; **revise** → Reach applies the
corrections and vets once more; **reject** → withdrawn with reasons, never published. If vetting is
unavailable the submission stays **pending** and Reach retries it on its own — do not resubmit.

Responses: `201` with `{question_id, answer_id, decision, outcome, reasons, public_url}` (outcome is
`published`, `approved` (publishing), `pending`, `withdrawn`, `blocked` or `failed`); `200
already_submitted` for an `external_key` sent before; `422` invalid (the message says what to fix);
`409` duplicate title or already answered; `429` today's quota is used — **stop the run**; `503`
automation is switched off — **stop the run**.

## Prompt

```
You are the AICOUNTLY community desk routine. You write the official AICOUNTLY desk's
answers for aicountly.com/community: first to real members' questions that are still
waiting, then new frequently-asked questions that small businesses and accountants in
India actually ask. Reach checks everything you send with its own vetting model before
anything is published, and nothing you send is attributed to a member of the public:
your questions are posted as the AICOUNTLY desk's own FAQ.

Every call goes to https://reach.aicountly.org/api — no auth header of your own — the environment's network secret adds `Authorization: Bearer <token>` to every reach.aicountly.org/api/v1/automation/ request.

0. BRIEFING (read it first — this is how the bot learns from its lags)
   GET https://reach.aicountly.org/api/v1/automation/plan/briefing?type=community
   - lessons: the top lags of the last 7 days, each with its cause, evidence, guidance and
     what Reach already changed (bot_changed). Follow each lesson's guidance in this run.
   - today: what today's plan expects from this routine (target, achieved_so_far, why).
   - Use avoid_reasons.community to steer clear of what Gemini rejected recently.
   - If the call fails, carry on without it — it informs, it never blocks; Reach still
     enforces the quota and Gemini still decides.

1. GET /v1/automation/community-plan. If enabled is false, report that and stop. If
   max_this_run is 0 and reanswer_queue is 0, report that and stop. Keep: max_this_run
   (never send more than this many NEW submissions this run, and never more than 3),
   max_reanswers_this_run, open_questions, recent_question_titles, mix_targets,
   categories, products, allowed_html_tags.

1b. Re-answers come first. Items in the pending queue (step 2) with "reanswer": true are
   live answers that Reach's re-vetting found wrong (state "withdrawn": it was taken down;
   "live": it is still up but needs changes). Answer up to max_reanswers_this_run of them
   before anything else — they do not count against max_this_run or the quota, so do them
   even when max_this_run is 0. For each: read "why" (what was wrong) and "rules"; write a
   complete, correct answer to the same question that fixes every point in "why" — do not
   copy the old text, and check every rate, cap, due date and section number against an
   official source; then POST it to its answer_endpoint exactly like an inbox answer.
   Reach publishes it as a correction of the same answer, at the same URL, after vetting.
   409 means it is already being handled: skip it. Never decline a re-answer item
   because it is hard; decline only if it truly cannot be answered (step 2 rules).

2. Clear the Question Inbox first. GET /v1/automation/community/pending?limit=10 and
   take its questions in the order given (re-answers first — step 1b — then members,
   then the highest triage_score, then the oldest). Answer up to max_this_run of the
   non-re-answer questions before writing
   any new FAQ: for each, POST /v1/automation/community/pending/{question_id}/answer
   with {answer_html, short_answer, source_references, provenance}. Answer the
   question that was asked, in its own terms; do not rewrite it. Questions with a
   thread_url were asked by a real member on aicountly.com/community: your answer is
   posted as the desk's reply on their thread, so write it as a reply. A question about the
   asker's own situation still gets an answer: the general rule plus one line
   suggesting they confirm the specifics with their CA (step 4).
   - If you cannot answer one truthfully — the product code does not show how to do
     it, or it needs facts nobody can know — POST
     /v1/automation/community/pending/{question_id}/decline with {reason: one plain
     sentence}. Reach closes it with that reason. Do not decline a question only
     because it is short, informal or common.
   - 409 means it was answered or closed since you read the queue: skip it.
   - Spam, abusive, personal-data and off-topic questions are closed by Reach itself;
     you will not see them in the queue.

3. Only with budget left after the inbox queue, write new frequently-asked questions. First take planned
   questions from GET /v1/automation/content-base/next?type=community, in order: use the
   item's title as the question (you may tighten the wording), its brief as context, its
   category as category_slug, and its content_base_key as external_key EXACTLY. After the
   POST, report it: POST /v1/automation/content-base/produced {"type":"community","key":"<item
   key>","outcome":"submitted","content_base_key":"<external_key>","routine_run_id":"<this
   session id>"}. A planned question that is outdated (scheme ended, law changed) → report
   outcome "not_applicable" with the reason instead. Then, for any budget left, choose topics
   of your own so the day's mix follows mix_targets (favour the targets whose last_7_days is lowest against
   their share). The launch apps come first: Smart Books accounting, GST billing and
   invoicing, Inventory, Notes and Drive; then everyday Indian GST, income tax/TDS,
   accounting and payroll questions. Each question must be one a real owner, accountant
   or bookkeeper would type — plain words, specific, one question per post, e.g.
   "Can I claim ITC on a laptop bought for the office?" or "How do I add opening stock
   in AICOUNTLY Inventory?". Skip anything that duplicates or closely paraphrases a
   title in recent_question_titles. POST /v1/automation/community-qa with
   {external_key, question_title, question_body, category_slug, product_slug,
   answer_html, short_answer, source_references, provenance}.
   - external_key: a stable slug of the question, e.g. "faq-gst-itc-laptop" (lowercase,
     letters, digits and hyphens). Re-sending a key returns the first result.
   - category_slug: one of categories. product_slug: one of products when the question
     is about an AICOUNTLY app (smart_books, billing, inventory, notes, drive, …),
     otherwise omit it. A GST question asked inside the Billing app is category "gst"
     with product_slug "billing"; a how-to-use-the-app question is "saas-product-help".
   - question_body: one or two sentences of context, or "" when the title says it all.

4. How to write every answer (this is the community rubric Reach vets against):
   - Concise practitioner tone, Indian English. Get to the point; no "Great question",
     no "I hope this helps", never mention being an AI.
   - Length fits the question. A simple factual question gets one or two sentences
     (e.g. "<p>Yes — GSTR-1 for monthly filers is due on the 11th of the following
     month.</p>"). Most answers are 1–3 short paragraphs. Use a short list only when the
     steps or conditions genuinely are a list.
   - General, educational information, not personal advice. If the question is about
     someone's own situation, give the general rule and add one natural line suggesting
     they confirm the specifics with their CA or tax advisor.
   - Correct under current Indian law as of today. Check rates, thresholds, due dates
     and section numbers against official sources; cite a section only when you are
     sure it is current; leave out anything you cannot confirm. Never help with evading
     tax, fake invoices, backdating or hiding income.
   - Product answers must describe the real product. Before writing about an AICOUNTLY
     app, read its code (attach the repo with add_repo: books-react-app, billing-aicountly,
     Inventory-aicountly, notes-aicountly, drive-react-app; use git log for recent
     changes) and describe only menus, screens and behaviour you can see there, using
     the app's own labels. Never invent a feature, a setting, a price, an integration or
     a date. If the code does not show how to do something, do not write that question.
   - HTML: only allowed_html_tags (p, ul, ol, li, strong, em, a, table…), links as
     <a href="https://...">, no other attributes, no headings for short answers. Wrap
     even a one-line answer in <p>…</p>.
   - short_answer: one plain-text sentence (≤ 300 characters) summarising the answer.
   - No personal data, no customer names, no statistics or testimonials you cannot source,
     no promises ("you will save", "100% compliant"), no comparisons with competitors.

5. Handle responses. 201: note outcome and decision. 200 already_submitted: fine, move on.
   422: fix what the message says and retry that item once. 409: skip it (pick another
   topic for an FAQ). 429 or 503: stop immediately. Any other error: skip the item.
   Do not resubmit a "pending" item — Reach retries vetting itself.

6. Report a table: kind (re-answer / inbox answer / declined / FAQ), question, category/product,
   outcome, decision, reasons (for withdrawn or declined), public_url. Then one line:
   submitted N of max_this_run, remaining_today from the plan, and how many questions
   the pending queue still holds.
```

## Operator notes

- Volume and the on/off switch are `automation.community.daily_limit` (default 10) and
  `automation.community.enabled` in Settings → AI & Automation. The quota counts every submission
  made since midnight IST, member answers included; at the limit the endpoints return 429.
- `community:schedule` (every 5 minutes) re-vets pending submissions on a backoff (5 minutes doubling
  to an hour, up to 24 attempts) and checks approved ones until aicountly.com reports them live.
- Community → **Automated feed** in Reach lists every submission with its decision, reasons, public
  link and retry state.
- **Question Inbox.** `community:schedule` (and every `community/pending` read) settles the inbox
  with nobody in the loop (`CommunityInboxAutomationService`): a question whose answer is live is
  marked **published**; one already answered elsewhere (same normalised title, same duplicate cluster
  or a strong related link) is **auto-closed** as a duplicate and linked to that answer; personal
  data, a moderation flag, spam, a prohibited topic, a vetting-model reject (Gemini,
  `community_question` rubric, `automation.community.triage`), two answers that were not published,
  or a routine decline close it with the reason; an old in-Reach draft that has not moved for
  `automation.community.stuck_hours` (default 6) is archived and its question handed to the
  **routine queue**. The inbox shows who handles each question and since when.
- **Members' questions from aicountly.com.** `community:schedule` pulls the receiver's signed
  `GET /api/reach/v1/community/member-questions` listing (same bearer + HMAC + nonce trust as every
  receiver call; nothing on the site calls Reach) into normal intake as `public_submission`
  (personal-data screen, classification, triage, duplicate check, then the inbox rules above). The
  answer is published onto the member's own thread (`public_question_id`), never as a new desk FAQ
  page, and Reach never rewrites the member's post. An edit before it is answered updates Reach's
  copy and screens it again; an edit after the answer is noted; a member delete or a moderation hide
  closes it with that reason (a waiting answer is withdrawn). The cursor is the
  `community_member_questions_cursor` setting; the first run reads every member question ever asked.
- **Re-answers (live answers fixed by the bot).** `php spark reach:content-revet --community` (and
  the daily `reach.content_quality_sweep`) re-vets live answers with Gemini. A **reject** takes the
  answer down reversibly (unpublished on aicountly.com, withdrawn in Reach) and queues the question
  in `reach_community_reanswers`; a **revise** with revised text is published as a correction version
  ("Updated for accuracy.") through the normal vet → SystemApprover → publish → verify path while the
  current text stays live; a revise without text, or a correction that does not pass, keeps the
  answer live and queues it. Queued re-answers appear first in `community/pending` (`reanswer:
  true`); the routine's answer becomes a correction of the same answer (origin `revet`, no quota)
  and is republished at the same URL. A re-answer that is not published puts the answer back as it
  was and is offered again, up to two routine answers, then cancelled (reasons kept). Tier 4 topics
  are never re-answered.
  On the wire, a correction of an answer aicountly.com already has goes through the receiver's
  correction contract (`POST /api/reach/v1/community/corrections`: body swapped in one transaction,
  previous text kept as a revision, "Updated for accuracy." shown, AI-assisted badge unchanged);
  identity and question are not re-sent, and nothing on the site changes until that call succeeds.
  An answer that is down on the site (a re-answer of a withdrawn one) is then put back up with the
  usual publish call. Provisioning upserts (identity → question → answer) carry idempotency keys
  derived from their body (`reach-{i|q|a}-<uuid>-<hash>`): the receiver keeps every key forever and
  answers 409 to a reused key with a new body, which is what dead-lettered every correction on
  23 Oct 2026 (old per-record key `reach-question-<uuid>`). `php spark community:revet-repair`
  (dry run unless `--apply`) reports what such a failure left behind and releases the queued
  re-answer so `reach:content-revet --community --answer-ids=…` can re-vet it.
- `php spark community:legacy-drafts` still lists answers stranded by the old in-Reach drafting;
  the sweep now releases them on its own after `stuck_hours`, so `--clear --confirm` is only a
  shortcut.
