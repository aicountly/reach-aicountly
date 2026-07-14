# Phase 7 — Discovery Report

**Date:** 2026-07-15  
**Repository:** reach-aicountly  
**Baseline:** `reach-phase-6-complete` → `84704586488b2c343ab5630b8aaf4496aa25e6a8`

---

## 1. Existing campaign infrastructure

### Tables

| Table | Migration | Current capability | Phase 7 action |
|-------|-----------|-------------------|----------------|
| `reach_campaigns` | 100009 | Parent record: name, type, UTMs, approval_status, analytics_summary JSONB | Extend: add uuid, tenant_id, lock_version, expanded status CHECK |
| `reach_social_posts` | 100011 | Draft/schedule/manual_queue; external_post_id; approval_status | Extend: add uuid, tenant_id, connection_id, destination_id, provider fields |
| `reach_email_campaigns` | 100012 | subject, from_name/email, body_html/text, audience_filter JSONB, stats JSONB, markSent shortcut | Extend: add uuid, tenant_id, connection_id, provider_message_id, bounce/complaint tracking |
| `reach_whatsapp_campaigns` | 100013 | template_name, template_params, audience_filter JSONB, stats JSONB, markSent shortcut | Extend: add uuid, tenant_id, connection_id, template_id FK, provider_message_id |

### Missing tables (to be created in CP1)

- `reach_campaign_versions` — immutable campaign snapshots
- `reach_campaign_channel_variants` — per-channel content
- `reach_audience_segments` + `reach_audience_segment_rules`
- `reach_campaign_audience_snapshots` + `reach_campaign_audience_recipients`
- `reach_channel_consents`
- `reach_channel_suppressions`
- `reach_campaign_dispatches`
- `reach_campaign_delivery_attempts`
- `reach_sms_campaigns`
- `reach_campaign_sender_profiles`
- `reach_campaign_templates` + `reach_campaign_template_versions`
- `reach_campaign_provider_events`
- `reach_campaign_operational_metrics`

---

## 2. Existing controllers

| Controller | Current state | Gap |
|-----------|---------------|-----|
| `CampaignController.php` | CRUD + approve + setStatus | No versioning, no channel variants, no dispatch |
| `SocialPostController.php` | List/create/approve/`markPosted` | `markPosted` is a manual shortcut; no provider dispatch |
| `SocialQueueController.php` | Queue list only | No dispatch logic |
| `EmailCampaignController.php` | CRUD + `markSent` | `markSent` is a manual shortcut; no provider dispatch |
| `WhatsAppCampaignController.php` | CRUD + `markSent` | `markSent` is a manual shortcut; no provider dispatch |

---

## 3. Existing services

| Service | Phase | Relevance |
|---------|-------|-----------|
| `MarketingBotService` | 0 | Marketing bot generation; not dispatch |
| `DailyMarketingPackService` | 0 | Pack generation; not dispatch |
| `JobService` | 0 | Reused for all dispatch jobs |
| `ApprovalPolicy` | 2 | Reused for campaign self-approval prevention |
| `AiGenerationOrchestrator` | 3 | Reused for AI channel variant adaptation |
| `PublicationDeploymentService` | 4 | Phase 4 blog/KB publishing; not campaign dispatch |
| `VideoCallbackAuthenticator` | 6 | HMAC pattern — extended for campaign provider callbacks |

**No campaign dispatch service exists.**

---

## 4. Existing frontend

| Page | Current state | Gap |
|------|---------------|-----|
| `CampaignListPage` | List campaigns | No versioning, no channel variants |
| `CampaignEditorPage` | Create/edit form | No channel variant tabs |
| `CampaignDetailPage` | View campaign | No dispatch, no analytics |
| `SocialPlannerPage` | Social post list | No provider connection |
| `SocialQueuePage` | Manual queue | `markPosted` shortcut |
| `EmailListPage` | Email campaign list | |
| `EmailDetailPage` | Email detail + `markSent` | `markSent` shortcut |
| `WhatsappListPage` | WhatsApp list | |
| `WhatsappDetailPage` | WhatsApp detail + `markSent` | `markSent` shortcut |

**No `/distribution`, `/sms`, `/distribution/segments`, `/distribution/audiences` routes.**

---

## 5. Existing jobs

No campaign dispatch jobs exist. All 15 existing jobs are for other domains (AI generation, community, content, bot).

Next job types to add follow naming convention `reach.campaign_*`.

---

## 6. Existing permissions

Permission groups `campaign`, `social`, `email`, `whatsapp` exist with basic CRUD permissions.

**Missing:** `distribution` group, `sms` group, consent/suppression management permissions, dispatch/retry/pause permissions.

---

## 7. Phase 4 infrastructure reuse plan

| Phase 4 table | Reuse in Phase 7 |
|---------------|-----------------|
| `reach_publication_connections` | Provider connections for social/email/WhatsApp/SMS (new `connection_type` values) |
| `reach_publication_idempotency_records` | Dispatch idempotency keys |
| `reach_publication_webhook_events` | **Not reused** — campaign provider events get own table `reach_campaign_provider_events` for clean separation |

---

## 8. Phase 6 infrastructure reuse plan

| Phase 6 pattern | Reuse in Phase 7 |
|----------------|-----------------|
| `VideoCallbackAuthenticator` | Extended as `DistributionCallbackAuthenticator` |
| `VideoProviderFactory` | Pattern adopted as `ChannelProviderFactory` |
| Provider event deduplication | Same `provider_event_id UNIQUE per provider+connection` constraint |
| `MockRenderProvider` test pattern | `MockSocialPublisher`, `MockEmailSender`, `MockWhatsAppSender`, `MockSmsSender` |

---

## 9. Baseline test counts

| Suite | Count | Status |
|-------|-------|--------|
| PHPUnit Unit | 763 | All pass |
| PHPUnit Feature | 346 (103 skipped — PG unavailable locally) | All pass |
| Vitest | 267 | All pass |

---

## 10. Migration sequence

Last Phase 6 migration: `2026-07-14-100122_ExtendApprovalsForVideoScript`

Phase 7 migrations: `2026-07-15-100123` through `2026-07-15-100145+`
