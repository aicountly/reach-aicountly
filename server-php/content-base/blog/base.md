# AICOUNTLY Blog Base

This file is the strategy base for all autonomous blog generation (API route
and Claude routine). It is **edited only in git** — the cPanel copy is
overwritten by every deploy (`rsync --delete`), so never edit it on the
server. The Reach console renders it read-only under
Blog Command Centre → Roadmap → Content Base.

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

## Index workflow

`index.json` is the queue of upcoming blogs. Each entry's `brief_prompt` is a
short creative brief — the model has its own wisdom; the prompt sets scope,
angle and must-cover points only. The daily Claude base-revision routine
updates this file from product-repo changes and marks published entries.
Statuses: `planned` → `drafting` → `published` | `retired`.
