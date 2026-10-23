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

## Blog categories (aicountly.com taxonomy, 23 Oct 2026)

Every post is filed under exactly ONE category slug (Reach refuses anything
else with HTTP 422). Source of truth in code: `App\Libraries\Blog\BlogTaxonomy`
(the aicountly.com side keeps the same list).

| Group | Slug | Label | Use for |
|---|---|---|---|
| Compliance | `accounting` | Accounting | bookkeeping, ledgers, bank reconciliation, closing, financial statements and reporting |
| Compliance | `gst` | GST | GST returns, ITC, GSTR-2B reconciliation, e-invoicing, e-way bills |
| Compliance | `income-tax` | Income Tax | ITR, advance tax, presumptive tax, new laws and provisions |
| Compliance | `tds-tcs` | TDS/TCS | TDS/TCS sections, deposits, returns, certificates |
| Compliance | `audit-assurance` | Audit & Assurance | statutory/tax/internal audit, audit trails, audit readiness |
| Compliance | `company-law` | Company Law | MCA/ROC filings, LLP and private company compliance |
| Compliance | `payroll-hr` | Payroll & HR | payroll, PF, ESI, PT, LWF, payslips, HR compliance |
| Business | `billing-invoicing` | Billing & Invoicing | invoices, quotations, credit notes, collections |
| Business | `inventory-stock` | Inventory & Stock | stock tracking, warehouses, reorder levels, valuation |
| Business | `sales-crm` | Sales & CRM | leads, customers, pipelines, follow-ups |
| Business | `productivity-collaboration` | Productivity & Collaboration | notes, documents, file storage, sharing, team work (Notes, Drive) |
| Business | `business-operations` | Business Operations | cash flow, funding, schemes, processes, growth |
| AICOUNTLY | `product-guides` | Product Guides | how to do a job in an AICOUNTLY app, step by step |
| AICOUNTLY | `product-updates` | Product Updates | what shipped recently in an app (from its git log) |
| AICOUNTLY | `ai-for-finance` | AI for Finance | AI in accounting, tax and compliance work — practical, no hype |

Old values still map (aliases, never dropped): financial-reporting →
accounting, audit → audit-assurance, ai-finance-compliance / ai /
ai_promotions → ai-for-finance, "TDS" → tds-tcs, "Payroll" → payroll-hr,
funding_promotions / subsidies_promotions → business-operations,
new_laws_provisions → income-tax, erp → product-guides, platform →
product-updates.

## Topic vs app-led mix (owner, 23 Oct 2026)

- **50 % compliance/finance topic articles** — no `product_slug`; the closing
  paragraph may still mention the relevant app once.
- **50 % app-led articles** — the app is the subject; send its
  `product_slug` (aicountly.com catalogue slug).
  - Live apps first: books (Smart Books), billing, inventory, notes, docs
    (Drive), manage.
  - About **1 app-led article in 5 is about a Beta app** (every other
    aicountly.com product). Beta apps are described only from their
    repository and are always presented as Beta ("HRMS (Beta)").
- `GET v1/automation/blog-plan` → `mix` shows the last 7 / 30 days by
  category group and the app-led share, and `mix.next` says which kind to
  write next.

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
