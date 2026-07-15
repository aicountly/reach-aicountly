# Reach Content Refresh Intelligence

**Phase:** 9
**Status:** Implementation in progress

---

## Overview

Phase 9 implements a governed, explainable content-refresh intelligence system that:

1. Reads Phase 8 evidence packets via `IntelligenceEvidenceService`
2. Validates evidence against refresh policies
3. Generates auditable recommendations with per-factor scoring
4. Runs a human-approved workflow through triage, generation, review, and publication
5. Measures post-refresh observed changes
6. Records the complete cycle for traceability

---

## Core Principle

**Evidence → Policy → Recommendation → Human decision → AI draft → Human approval → Publication → Outcome**

No step in this chain is automated end-to-end. Human approval is required before any content is published.

---

## Evidence Sources

All evidence is consumed via the Phase 8 contract:

```php
IntelligenceEvidenceService::getEvidencePacket(
    contentIdentityId: int,
    asOf: string,
    windowDays: int = 28
): array
```

Packet keys: `identity`, `search`, `engagement`, `indexing`, `visibility`, `attribution`, `freshness`, `completeness`

---

## Refresh Policy Engine

Policies define per-content-type thresholds that trigger recommendation eligibility:

- Minimum publication age before first refresh check
- Comparison window length
- Deterioration thresholds (position, impressions, engagement, CTR)
- Source freshness requirements
- Required evidence sources (must be present, not imputed)
- Cooldown period after last recommendation
- Risk escalation conditions

Policies are versioned. A policy version change creates a new version; it does not rewrite historical recommendations.

---

## Recommendation Scoring

Scoring is deterministic and explainable. Each factor is stored individually in `reach_refresh_score_components`:

```
declining_search_impressions
declining_clicks
declining_ctr
worsening_position
declining_engagement
conversion_deterioration
outdated_product_information
superseded_release_information
stale_source_material
broken_or_withdrawn_citation
unsupported_claim
canonical_sitemap_issue
duplicate_overlapping_content
ai_visibility_gap
competitor_visibility_gap
missing_cta
incomplete_attribution
long_since_review
```

No opaque composite score. The total is a derived sum of individual factors.

---

## Refresh Workflow States

```
detected → recommended → triaged → accepted → brief_prepared
→ draft_generating → draft_ready → in_review → approved
→ publish_queued → published → monitoring → outcome_recorded
```

Additional states: `rejected`, `deferred`, `changes_requested`, `blocked`, `cancelled`, `superseded`, `failed`, `withdrawn`

---

## Content Types Supported

| Type | Phase Foundation | Phase 9 Extension |
|------|-----------------|------------------|
| Blog | BlogRefreshService (P4) | Wire to workflow, outcome measurement |
| Knowledge base | KnowledgeBaseRefreshService (P4) | Wire to workflow, outcome measurement |
| Community answer | OfficialAnswerCorrectionService (P5) | Wire to workflow |
| Video | VideoScriptVersionService (P6) | Script version + metadata refresh |
| Campaign | CampaignChannelVariantService (P7) | Refresh flag on content variant |

---

## AI Governance in Refresh

AI may only:
- Generate a draft refresh based on approved brief + evidence + product sources
- Operate within Phase 3 budget, usage ledger, and validation pipeline
- Produce an immutable artifact stored in `reach_ai_generation_artifacts`

AI must not:
- Approve its own draft
- Remove disclosures silently
- Fabricate metrics or product functionality
- Publish automatically

---

## Outcome Measurement

Post-refresh outcomes use "Observed post-refresh change" language. Example:

```
Observed: +12% impressions in 28-day post-refresh window vs 28-day baseline
Source: Google Search Console via reach_search_metric_facts
Confidence: medium (28/28 days data available)
Causal claim: none (observational data only)
```
