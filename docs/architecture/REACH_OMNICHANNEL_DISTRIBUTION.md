# AICOUNTLY Reach — Omnichannel Distribution Architecture

**Phase:** 7  
**Date:** 2026-07-15

---

## Overview

Phase 7 delivers governed, provider-backed campaign distribution across four channel families: Social, Email, WhatsApp, and SMS. It extends the existing Phase 0–6 infrastructure rather than replacing it.

---

## Architecture principles

1. Extend `reach_campaigns` as the single omnichannel campaign identity.
2. Immutable campaign versions — approved content cannot be modified.
3. Audience snapshots are frozen at approval/dispatch and never silently recalculated.
4. Consent and suppression are rechecked immediately before each provider submission.
5. All provider calls go through the job queue; no long DB transactions during provider I/O.
6. Provider adapters are disabled by default; enabled only via environment variables.
7. All callbacks are HMAC-verified and deduplicated before processing.
8. Self-approval is prevented via Phase 2 `ApprovalPolicy`.

---

## Domain model overview

```
reach_campaigns (parent)
  └── reach_campaign_versions (immutable, per campaign)
        └── reach_campaign_channel_variants (per version per channel)

reach_audience_segments
  └── reach_audience_segment_rules

reach_campaign_audience_snapshots (frozen at dispatch)
  └── reach_campaign_audience_recipients (per recipient row)

reach_channel_consents
reach_channel_suppressions

reach_campaign_dispatches (per campaign+channel batch)
  └── reach_campaign_delivery_attempts (per recipient per batch)

reach_campaign_sender_profiles
reach_campaign_templates
  └── reach_campaign_template_versions (immutable)

reach_campaign_provider_events (inbound callbacks)
reach_campaign_operational_metrics (aggregate counters)
reach_sms_campaigns (SMS-specific dispatch data)
```

---

## Campaign lifecycle

```
draft
→ preparing (channel variants being built)
→ ready_for_review (submitted for approval)
→ in_review
→ approved
→ scheduled
→ dispatching
→ partially_completed (some channels done)
→ completed

Side branches:
in_review → changes_requested → draft
in_review → rejected
dispatching → paused
dispatching → cancelled
dispatching → failed
dispatching → dead_lettered
```

---

## Provider architecture

```
ChannelProviderFactory
  ├── SocialPublisherInterface (mock by default, production adapters disabled)
  │     ├── MockSocialPublisher (CI default)
  │     ├── LinkedInPublisherAdapter (disabled unless LINKEDIN_PROVIDER=enabled)
  │     ├── XPublisherAdapter (disabled)
  │     ├── FacebookPublisherAdapter (disabled)
  │     └── InstagramPublisherAdapter (disabled)
  ├── EmailSenderInterface
  │     ├── MockEmailSender (CI default)
  │     └── MailgunEmailAdapter (disabled unless EMAIL_PROVIDER=mailgun)
  ├── WhatsAppSenderInterface
  │     ├── MockWhatsAppSender (CI default)
  │     └── CloudApiWhatsAppAdapter (disabled unless WHATSAPP_PROVIDER=cloudapi)
  └── SmsSenderInterface
        ├── MockSmsSender (CI default)
        └── TwoFactorSmsAdapter (disabled unless SMS_PROVIDER=2factor)
```

---

## Callback verification

All inbound provider callbacks are processed by `DistributionCallbackAuthenticator`:
1. Verify HMAC-SHA256 signature using connection-specific secret.
2. Check timestamp within ±5 minute tolerance window.
3. Deduplicate using `reach_campaign_provider_events.provider_event_id UNIQUE`.
4. Reject replays silently (return 200 to prevent retries).

---

## Dispatch orchestration (CP9)

```
CampaignScheduleService
  → Polls for due campaigns
  → Creates CampaignScheduleDispatchJob

CampaignScheduleDispatchJob
  → Final preflight (version approved, snapshot frozen)
  → Final consent+suppression recheck
  → Creates reach_campaign_dispatches per channel
  → Enqueues CampaignChannelBatchJob per dispatch

CampaignChannelBatchJob
  → Reserves dispatch (optimistic lock)
  → Calls ChannelProviderFactory::make(channel)
  → Submits provider request (idempotency key)
  → Records provider receipt in delivery attempt
  → On success: update status, enqueue reconciliation

CampaignDeliveryRetryJob
  → Exponential backoff for transient failures
  → Marks permanent failures as dead_letter

CampaignProviderEventJob
  → Normalises inbound callback
  → Updates attempt status
  → Triggers suppression on unsubscribe/complaint/bounce

CampaignDeliveryReconciliationJob
  → Reconciles attempts vs provider events
  → Updates operational metrics
```

---

## Security controls

- All provider connections stored in `reach_publication_connections`; credentials in encrypted vault
- Provider secrets never returned in API responses
- Recipient addresses masked in logs (last 4 chars visible)
- Consent records require proof reference
- Suppression stored as SHA-256 keyed hash — raw PII not stored
- Segment rules use allowlisted field grammar — no arbitrary SQL
- HMAC callbacks: SHA-256 + timestamp + replay protection
- Self-approval prevention via `ApprovalPolicy`
- Object-level tenant isolation on all endpoints

---

## Phase 4 infrastructure reused

| Resource | Reuse |
|----------|-------|
| `reach_publication_connections` | Channel provider connections |
| `reach_publication_idempotency_records` | Campaign dispatch idempotency |
| Phase 4 HMAC pattern | Extended in `DistributionCallbackAuthenticator` |

---

## Phase 6 infrastructure reused

| Resource | Reuse |
|----------|-------|
| `VideoCallbackAuthenticator` pattern | Extended as `DistributionCallbackAuthenticator` |
| `VideoProviderFactory` pattern | Adopted as `ChannelProviderFactory` |
| Mock provider pattern | 4 mock providers for CI |
