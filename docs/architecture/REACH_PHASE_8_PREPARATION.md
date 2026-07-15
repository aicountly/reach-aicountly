# Phase 8 — Preparation Notes (Phase 7 Handoff)

**Prepared by:** Phase 7 implementation
**Date:** 2026-07-15

---

## Evidence contracts provided by Phase 7

The following stable data identities are produced by Phase 7 dispatch and available for Phase 8 ingestion:

| Contract | Table | Column | Notes |
|----------|-------|--------|-------|
| Canonical campaign UUID | `reach_campaigns` | `uuid` | Stable across versions |
| Campaign version ID | `reach_campaign_versions` | `uuid` | Points to exact approved content |
| Channel variant ID | `reach_campaign_channel_variants` | `uuid` | Per-channel content identity |
| Channel | `reach_campaign_dispatches` | `channel` | social/email/whatsapp/sms |
| Provider connection ID | `reach_campaign_dispatches` | `connection_id` | FK to reach_publication_connections |
| Remote message/post ID | `reach_campaign_delivery_attempts` | `provider_message_id` | Provider-assigned |
| Remote post URL | `reach_campaign_delivery_attempts` | `remote_url` | Social only |
| UTM source | `reach_campaigns` | `utm_source` | |
| UTM medium | `reach_campaigns` | `utm_medium` | |
| UTM campaign | `reach_campaigns` | `utm_campaign` | |
| UTM content | `reach_campaigns` | `utm_content` | |
| UTM term | `reach_campaigns` | `utm_term` | |
| Dispatch accepted_at | `reach_campaign_delivery_attempts` | `accepted_at` | Provider acceptance timestamp |
| Sent timestamp | `reach_campaign_delivery_attempts` | `sent_at` | |
| Delivery timestamp | `reach_campaign_delivery_attempts` | `delivered_at` | |
| Engagement timestamp | `reach_campaign_delivery_attempts` | `read_at` | Where available |
| Normalised status | `reach_campaign_delivery_attempts` | `status` | Standardised across channels |
| Provider event source | `reach_campaign_provider_events` | `provider` | Provenance label |
| Raw provider event | `reach_campaign_provider_events` | `raw_event` | JSONB — privacy-safe storage |
| Operational metrics | `reach_campaign_operational_metrics` | All counter columns | Aggregate delivery evidence |
| Suppression events | `reach_channel_suppressions` | All columns | Unsubscribes, bounces, complaints |

---

## Phase 8 prerequisites prepared by Phase 7

- Stable campaign UUID for cross-channel attribution joins
- Channel-level delivery timestamps for funnel analysis
- Provider event type and provenance labelling
- UTM parameters attached to every outbound dispatch
- Normalised delivery status vocabulary (`sent`, `delivered`, `read`, `failed`, `bounced`, `complained`, `unsubscribed`)
- Aggregate metrics counters per dispatch for dashboard roll-ups
- Suppression records for audience quality scoring
- Connection ID for provider-level attribution

---

## Phase 8 prerequisites NOT prepared by Phase 7

| Item | Reason |
|------|--------|
| GSC Search Console connector | Phase 8 scope |
| IndexNow indexing signals | Phase 8 scope |
| AI visibility test harness | Phase 8 scope |
| Competitor mention monitoring | Phase 8 scope |
| Cross-channel revenue attribution model | Phase 8–9 scope |
| Content performance intelligence | Phase 8 scope |
| Automatic content refresh triggers | Phase 9 scope |

---

## Explicit non-implementation statement

> No Phase 8 GSC ingestion, IndexNow intelligence, AI visibility monitoring, competitor monitoring, attribution algorithms, content-performance intelligence, or automatic optimisation was implemented in Phase 7.
