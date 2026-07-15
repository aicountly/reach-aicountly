# Phase 9 Evidence Foundations

**Prepared by:** Phase 8  
**Phase 9 capability:** Content Refresh Intelligence and Automation  
**Status:** Evidence contracts defined. Phase 9 implementation not started.

---

## Purpose

Phase 9 will implement content-refresh recommendations and a governed refresh queue. Phase 8 prepares the time-series evidence that Phase 9 will consume. Phase 9 must NOT begin until Phase 8 is tagged and validated.

---

## Evidence Contracts Phase 9 May Read

All contracts are read-only from Phase 9's perspective.

### Search Performance Evidence

**Table:** `reach_search_metric_facts`

| Field | Phase 9 Use |
|-------|-------------|
| `content_identity_id` | Links to content requiring refresh |
| `metric_date` | Build time series |
| `avg_position` | Position deterioration signal |
| `impressions` | Impression decline signal |
| `clicks` | Click decline signal |
| `ctr` | CTR deterioration signal |

**Minimum evidence window for Phase 9:** 28 days rolling comparison

### Content Engagement Evidence

**Table:** `reach_content_metric_facts`

| Field | Phase 9 Use |
|-------|-------------|
| `content_identity_id` | Links to content requiring refresh |
| `metric_date` | Time series |
| `engaged_sessions` | Engagement decline |
| `engagement_rate` | Rate deterioration |
| `avg_engagement_time_secs` | Time-on-page decline |

### Indexing Evidence

**Table:** `reach_sitemap_entries`

| Field | Phase 9 Use |
|-------|-------------|
| `content_identity_id` | Identify content |
| `included` | Not included = potential indexing gap |
| `exclusion_reason` | Why excluded |
| `snapshot_id` → `generated_at` | When the gap was first detected |

### AI Visibility Evidence

**Table:** `reach_ai_visibility_observations`

| Field | Phase 9 Use |
|-------|-------------|
| `entity_mentioned` | Which entities appear |
| `coverage_state` | `not_mentioned` = visibility gap |
| `run_id` → `prompt_version_id` → `prompt_id` | Which query/product |
| `confidence` | Evidence quality |

### Attribution Evidence

**Table:** `reach_attribution_calculation_versions`

| Field | Phase 9 Use |
|-------|-------------|
| `unattributed_count` | Attribution completeness |
| `calculated_at` | When calculated |

---

## Evidence Contract API

Phase 8 provides `IntelligenceEvidenceService::getContentEvidence(int $contentIdentityId)` returning:

```php
array {
    content_identity: array,
    search_performance: array {
        period_days: int,
        avg_position_current: float,
        avg_position_baseline: float,
        impressions_current: int,
        impressions_baseline: int,
        position_trend: string,  // improving|stable|declining|insufficient_data
        completeness: float,     // 0-1
        freshness_at: string,
    },
    content_engagement: array {
        period_days: int,
        engagement_trend: string,
        completeness: float,
        freshness_at: string,
    },
    indexing_status: array {
        is_indexed: bool,
        last_snapshot_at: string,
        exclusion_reason: string|null,
    },
    ai_visibility: array {
        coverage_state: string,
        last_run_at: string,
        confidence: float,
    },
    attribution_completeness: float,
    phase9_refresh_eligible: bool,    // true if evidence thresholds met
    phase9_refresh_reason: string[],  // list of signals (NOT a recommendation)
}
```

**Critical:** `phase9_refresh_eligible` is an evidence flag, NOT a refresh recommendation. Phase 9 must implement its own recommendation logic.

---

## What Phase 9 Must NOT Assume

1. Phase 8 does not create refresh tasks
2. Phase 8 does not populate Phase 9 refresh queues
3. `phase9_refresh_eligible = true` does not mean content must be refreshed
4. Phase 8 evidence windows may have gaps; Phase 9 must handle `insufficient_data` gracefully
5. AI visibility observations are samples, not exhaustive search rankings

---

## Phase 9 Prerequisites

Before Phase 9 can begin:

- [ ] `reach_search_metric_facts` has at least 28 days of GSC data
- [ ] `reach_content_metric_facts` has at least 28 days of GA4 data
- [ ] `reach_content_identities` covers all active content types
- [ ] `reach_sitemap_entries` has at least 2 consecutive snapshot comparisons
- [ ] `reach_ai_visibility_observations` has at least 5 runs per monitored prompt
- [ ] Phase 8 is tagged `reach-phase-8-complete`
- [ ] Phase 8 CI passed in GitHub Actions
- [ ] Phase 8 staging smoke tests approved
- [ ] Human sign-off on Phase 8

---

## Explicit Phase 9 Exclusions from Phase 8

Phase 8 does NOT implement:

- Refresh recommendation records
- Refresh queue or work items
- Automatic content regeneration triggers
- Automatic republication after refresh
- Advanced multi-touch attribution weighting
- Revenue attribution calculations
- Algorithmic content scoring
- Programme-readiness audit
- Automatic anomaly-to-task conversion
