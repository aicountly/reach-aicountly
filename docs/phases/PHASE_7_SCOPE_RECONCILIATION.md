# Phase 7 — Scope Reconciliation

**Phase title (roadmap):** Campaign and Distribution Automation  
**Phase title (working):** Omnichannel Campaign Distribution  
**Repository:** reach-aicountly  
**Date:** 2026-07-15  
**Baseline tag:** `reach-phase-6-complete` → `84704586488b2c343ab5630b8aaf4496aa25e6a8`

> The roadmap title "Campaign and Distribution Automation" is the authoritative title. The working title "Omnichannel Campaign Distribution" is an accurate description of scope and is used in documentation and commit messages.

---

## Capabilities in scope (from gap matrix)

| ID | Capability | Gap Matrix Status | Phase 7 Disposition |
|----|-----------|-------------------|---------------------|
| 25 | Social publishing (LinkedIn, X, Facebook, Instagram) | Stub/Placeholder | **Confirmed requirement** |
| 26 | Email publishing via provider | Stub/Placeholder | **Confirmed requirement** |
| 27 | WhatsApp publishing via Business API | Stub/Placeholder | **Confirmed requirement** |
| 28 | SMS/DLT publishing | Missing | **Confirmed requirement** |

---

## Requirement classifications

### Confirmed requirements

| Requirement | Source | Evidence | Disposition |
|-------------|--------|----------|-------------|
| Governed campaign versions and approval | Phase 7 prompt §8.1 | `reach_campaigns` lacks versioning, `approval_status` is a single field | New: `reach_campaign_versions` |
| Channel variants per campaign | Phase 7 prompt §8.1 | No per-channel content table | New: `reach_campaign_channel_variants` |
| Audience segment definitions | Phase 7 prompt §8.6 | No `audience_segments` table; only JSONB `audience_filter` on channel tables | New: `reach_audience_segments` + rules |
| Immutable audience snapshots | Phase 7 prompt §8.6 | No snapshot/recipient tables | New: `reach_campaign_audience_snapshots` + recipients |
| Consent records | Phase 7 prompt §8.7 | No consent table | New: `reach_channel_consents` |
| Suppression records | Phase 7 prompt §8.7 | No suppression table | New: `reach_channel_suppressions` |
| Dispatch batches | Phase 7 prompt §8.1 | No dispatch batch tracking | New: `reach_campaign_dispatches` |
| Delivery attempt log | Phase 7 prompt §8.1 | No per-recipient attempt log | New: `reach_campaign_delivery_attempts` |
| SMS channel end-to-end | Gap matrix #28 | No SMS tables, controller, or routes | New: `reach_sms_campaigns` |
| Channel sender profiles | Phase 7 prompt §8.3 | Email `from_name`/`from_email` are free fields on campaign | New: `reach_campaign_sender_profiles` |
| Channel templates (versioned) | Phase 7 prompt §8.8 | WhatsApp `template_name` is a VARCHAR, no template catalogue | New: `reach_campaign_templates` + versions |
| Provider events (inbound callbacks) | Phase 7 prompt §8.1 | No provider event table for campaigns | New: `reach_campaign_provider_events` |
| Operational metrics | Phase 7 prompt §8.9 | Only JSONB `stats` blob per campaign | New: `reach_campaign_operational_metrics` |
| Provider interfaces (4 channels) | Phase 7 prompt §14 | No interface classes for social/email/WhatsApp/SMS sending | New: 4 interfaces + 4 mocks |
| Distribution permissions | Phase 7 prompt §8.1 | `distribution` permission group absent | New: 24 permissions |
| Campaign status extensions | Phase 7 prompt §14 | `reach_campaigns.status` CHECK lacks dispatch-lifecycle states | Extend via ALTER migration |
| Social dispatch (remove markPosted stub) | Gap matrix #25 | `socialService.markPosted()` — manual shortcut | Remove stub, wire real dispatch |
| Email dispatch (remove markSent stub) | Gap matrix #26 | `emailService.markSent()` — manual shortcut | Remove stub, wire real dispatch |
| WhatsApp dispatch (remove markSent stub) | Gap matrix #27 | `whatsappService.markSent()` — manual shortcut | Remove stub, wire real dispatch |
| Scheduling and dispatch orchestration | Phase 7 prompt §9 | No dispatch jobs exist | New: 6 job types |
| Distribution Control Centre (React) | Phase 7 prompt §10 | No `/distribution` routes | New: 13+ pages |

### Inferred requirements

| Requirement | Rationale | Disposition |
|-------------|-----------|-------------|
| Unsubscribe/preference endpoint | Email dispatch requires a CAN-SPAM/GDPR-compliant unsubscribe mechanism | Implemented within Reach backend; `aicountly-com` public page assessed during CP0 |
| Bounce and complaint auto-suppression | Standard email deliverability requirement | Handled in CP6 `EmailSenderService` |
| WhatsApp opt-in pre-check | WABA policy requires user consent before template messaging | Enforced in `WhatsAppSenderService` |
| DLT metadata fields for SMS | Required for TRAI-registered Indian SMS (Principle Entity ID, Template ID, Header) | Stored as configurable metadata in `reach_sms_campaigns` |

### Already implemented (Phase 0–6, not to be duplicated)

| Item | Evidence |
|------|----------|
| `reach_campaigns` parent table | `100009_CreateReachCampaigns.php` |
| `reach_social_posts` | `100011_CreateReachSocialPosts.php` |
| `reach_email_campaigns` | `100012_CreateReachEmailCampaigns.php` |
| `reach_whatsapp_campaigns` | `100013_CreateReachWhatsappCampaigns.php` |
| `reach_publication_connections` | `100082` — reused for all channel provider connections |
| `reach_publication_idempotency_records` | `100089` — reused for dispatch idempotency |
| HMAC callback authenticator pattern | `VideoCallbackAuthenticator.php` — extended for distribution |
| Provider event dedup | `reach_video_provider_events` pattern — mirrored for `reach_campaign_provider_events` |
| Job queue (`reach_jobs`) | Phase 0 — reused for all campaign dispatch jobs |
| `ApprovalPolicy` (self-approval prevention) | Phase 2 — reused for campaign approval |
| `AuditLogger` | All phases — extended with distribution event constants |
| `AiGenerationOrchestrator` | Phase 3 — used for AI-assisted channel variant adaptation |
| `HtmlSanitizer` | Phase 3/4 — used for email HTML validation |

### Deferred requirements

| Requirement | Reason | Phase |
|-------------|--------|-------|
| GSC analytics | Phase 8 scope | 8 |
| AI visibility monitoring | Phase 8 scope | 8 |
| Competitor monitoring | Phase 8 scope | 8 |
| Revenue/lead attribution models | Phase 8–9 scope | 8–9 |
| Content refresh automation | Phase 9 scope | 9 |

### Out of scope

- Building an independent CRM
- Purchasing or importing audience data from brokers
- Unofficial social APIs or browser automation
- Plain-text provider credential storage
- Production bulk sending during automated tests
- Self-approval
- Silent audience expansion after approval

---

## Public-site impact assessment

`aicountly-com` does not require modification for Phase 7 core dispatch. An unsubscribe/preference endpoint is required for email compliance. This will be implemented as a Reach backend endpoint (`GET /api/v1/distribution/unsubscribe?token=…`) with HMAC-signed tokens, rendering a confirmation page served by the Reach frontend. No `aicountly-com` files need to change.

**Conclusion:** `No aicountly-com files changed; no public Phase 7 tag required.`

---

## Residual risks

| Risk | Mitigation |
|------|-----------|
| No approved social provider credentials | Production adapters disabled by default; mock covers CI |
| WhatsApp WABA approval required for production | Adapter disabled; documented deployment prerequisite |
| SMS DLT registration required for India | Fields present; compliance documentation required |
| Email provider selection not finalised | Interface abstraction; Mailgun/SES adapter disabled |
| Large audience snapshots may be slow | Batching + pagination in `AudienceSnapshotService`; index on `snapshot_id` |
