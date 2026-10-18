# AICOUNTLY Blog Base

This file is the strategy base for the Claude routines that write blogs. It
is edited in git (the server copy is replaced on every deploy). The Reach
console shows it under Quality Centre → Content Base, and routines read it
from `GET v1/automation/content-base` (`base_markdown`).

## Who we write for

- Indian SMB owners, founders and their accountants
- Chartered Accountant firms and Company Secretaries
- HR/payroll operators in growing companies
- Finance teams evaluating accounting / compliance software

## What every blog must do

1. Answer one real question completely (search-intent first).
2. Ground every factual/compliance claim in current Indian law or an
   authoritative source (incometax.gov.in, gst.gov.in, mca.gov.in, rbi.org.in,
   sebi.gov.in). Unverifiable claims get removed, not hedged.
3. Mention the relevant AICOUNTLY product where it genuinely helps, with a
   link — never force a product into an unrelated topic.
4. Use plain language; define jargon on first use; prefer short sections,
   numbered steps and tables.
5. 900–1800 words for standard posts. Include a 2–3 sentence summary.

## Portfolio mix (enforced by the roadmap optimizer)

- 45% marketing (compliance/tax/accounting questions our buyers search for)
- 35% product (how AICOUNTLY products solve specific workflows)
- 20% problem-to-product (problem deep-dive that lands on a product answer)

## Categories in use on aicountly.com

accounting, gst, income-tax, tds-tcs, audit, financial-reporting,
company-law, payroll-hr, product-guides, product-updates,
ai-finance-compliance, business-operations

## Topics (the living content base)

`index.json` (and the KB / community seed files) are only the **seed
baseline**. Reach imports them hourly into its database, where the topics
actually live: the routines take the next topics from
`GET v1/automation/content-base/next`, add new ones from real product changes
with `POST …/propose` and report with `POST …/produced`. Each entry's
`brief_prompt` is a short creative brief — it sets scope, angle and
must-cover points only. Statuses (set by Reach from its own pipeline):
`planned` → `queued` → `produced` → `published` | `retired`. Editing a seed
here changes a topic only while it is still planned; progress is never reset
by a deploy.
