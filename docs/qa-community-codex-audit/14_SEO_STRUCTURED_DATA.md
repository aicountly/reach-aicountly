# SEO / QAPage Structured Data Correctness

Full finding and rationale is in `15_RBAC_SSO_SECURITY.md` ("QAPage /
structured data" section) — this file exists as its own numbered stub per the
index and records the specific correctness checks performed.

## Checks performed against `community_qa_json_ld_with_official()`

| Check | Result |
|-------|--------|
| Emitted only on real question-detail pages, from real fetched rows | Pass — `community/question.php` only, built from `$post`/`$answers`/`$officialAnswers` for that request |
| Official answers use `Organization` author type | Pass |
| Community (human) answers use `Person` author type | Pass |
| AI disclosure surfaces in structured data for official answers | Pass — `additionalProperty` → `aiAssisted` `PropertyValue` when `ai_assisted` is true |
| Accepted vs. suggested answer selection | Pass — official answer always wins `acceptedAnswer` slot when present; otherwise the human-marked `is_accepted` row; the rest become `suggestedAnswer` |
| Breadcrumb JSON-LD present alongside QAPage | Pass — `community_breadcrumb_json_ld()`, separate `@type: BreadcrumbList` block |
| No raw HTML/script injection via body text reaching the `<script type="application/ld+json">` block | Pass — every text field passes through `strip_tags()` before JSON encoding |

**Verdict: no gap.** No code change was required in this area; the
architecture claim (`docs/architecture/REACH_COMMUNITY_ARCHITECTURE.md`'s
"QAPage on real Q&A pages only, disclosure in JSON-LD") matches the as-built
code.
