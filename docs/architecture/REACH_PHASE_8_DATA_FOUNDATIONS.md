# Phase 8 Data Foundations — Evidence Contracts

**Scope:** Phase 7 data that Phase 8 (Search Intelligence & Attribution) can consume.  
**Status:** Phase 7 complete, Phase 8 not started.

---

## Delivery Evidence Tables

| Table | Description | Phase 8 Use |
|-------|-------------|-------------|
| `reach_campaign_delivery_attempts` | Every send attempt with status | Attribution signal: did this recipient receive the message? |
| `reach_campaign_dispatches` | Batch-level dispatch tracking | Cohort analysis: which campaigns ran in which time windows |
| `reach_campaign_operational_metrics` | Aggregated per-dispatch stats | Funnel metrics baseline |
| `reach_campaign_provider_events` | Raw inbound events from providers | Deduplication and canonical delivery status |

---

## Audience and Consent Evidence

| Table | Description | Phase 8 Use |
|-------|-------------|-------------|
| `reach_campaign_audience_snapshots` | Immutable audience at approval time | Population for attribution model |
| `reach_campaign_audience_recipients` | Per-recipient rows with consent_status | Consent-gated attribution |
| `reach_channel_consents` | Full consent history | Preference-based segmentation signals |
| `reach_channel_suppressions` | Suppressed addresses by reason | Negative signal for attribution |

---

## Campaign Version Evidence

| Table | Description | Phase 8 Use |
|-------|-------------|-------------|
| `reach_campaign_versions` | Immutable content snapshot per approval | Content variant analysis |
| `reach_campaign_channel_variants` | Per-channel content with validation_status | Channel effectiveness comparison |

---

## Contracts Phase 8 Must Honour

1. **Do not mutate** `reach_campaign_audience_recipients.channel_address_hash` or `channel_address_masked` — these are immutable after snapshot freeze.
2. **Do not mutate** `reach_campaign_versions` rows once `approved_at` is set.
3. **delivery_attempts** `idempotency_key` uniqueness must be preserved; Phase 8 may read it for dedup but must not write.
4. **provider_events** `event_hash` is the canonical dedup key — Phase 8 attribution must use this for join, not provider_message_id alone.
5. Consent status lookups for Phase 8 segmentation must query `reach_channel_consents` with `WHERE revoked_at IS NULL AND expired_at IS NULL OR expired_at > now()`.

---

## Recommended Phase 8 Schema Extensions

These extensions are safe to add in Phase 8 without altering Phase 7 tables:

```sql
-- Attribution linkage: connect delivery to revenue/conversion
CREATE TABLE reach_attribution_events (
    id              BIGSERIAL PRIMARY KEY,
    delivery_attempt_id BIGINT REFERENCES reach_campaign_delivery_attempts(id),
    conversion_type VARCHAR(80),
    converted_at    TIMESTAMPTZ,
    revenue         NUMERIC(12,4),
    attribution_model VARCHAR(40),
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

-- Search visibility signal (GSC ingest — Phase 8 only)
CREATE TABLE reach_search_impressions (
    id          BIGSERIAL PRIMARY KEY,
    campaign_id BIGINT REFERENCES reach_campaigns(id),
    page_url    TEXT,
    query       TEXT,
    impressions INT,
    clicks      INT,
    position    NUMERIC(6,2),
    collected_on DATE,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
```

---

_Last updated: Phase 7 completion. Do not modify Phase 7 tables listed above without a formal Phase 8 migration._
