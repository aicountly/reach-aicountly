# Phase 7 — Implementation Plan

**Phase:** 7 — Campaign and Distribution Automation (Omnichannel Campaign Distribution)  
**Repository:** reach-aicountly  
**Date:** 2026-07-15  
**Baseline:** `reach-phase-6-complete` → `84704586488b2c343ab5630b8aaf4496aa25e6a8`

---

## Checkpoints

| CP | Title | Commit message |
|----|-------|----------------|
| CP0 | Baseline verification, discovery, architecture | `docs(reach): define Phase 7 omnichannel distribution architecture` |
| CP1 | Campaign, audience and delivery schema | `feat(distribution): add omnichannel campaign and delivery schema` |
| CP2 | Permissions, audit events, provider contracts | `feat(distribution): add permissions audit and provider contracts` |
| CP3 | Audience segments, consent, suppression | `feat(distribution): implement audience consent and suppression controls` |
| CP4 | Campaign versions, variants, approval | `feat(distribution): add governed campaign variants and approvals` |
| CP5 | Social publishing | `feat(distribution): implement governed social publishing` |
| CP6 | Email distribution | `feat(distribution): implement provider-backed email campaigns` |
| CP7 | WhatsApp distribution | `feat(distribution): implement WhatsApp Business campaigns` |
| CP8 | SMS/DLT distribution | `feat(distribution): add governed SMS campaign delivery` |
| CP9 | Scheduling and dispatch orchestration | `feat(distribution): implement omnichannel dispatch orchestration` |
| CP10 | Distribution Control Centre | `feat(distribution): add omnichannel distribution control centre` |
| CP11 | Full validation and exit audit | `test(distribution): complete Phase 7 validation and exit audit` |

---

## CP1 — Schema migrations (100123–100145)

### New tables

| # | Migration | Table |
|---|-----------|-------|
| 100123 | `AddPhase7FieldsToCampaigns` | ALTER `reach_campaigns` |
| 100124 | `AddPhase7FieldsToSocialPosts` | ALTER `reach_social_posts` |
| 100125 | `AddPhase7FieldsToEmailCampaigns` | ALTER `reach_email_campaigns` |
| 100126 | `AddPhase7FieldsToWhatsappCampaigns` | ALTER `reach_whatsapp_campaigns` |
| 100127 | `CreateReachCampaignVersions` | `reach_campaign_versions` |
| 100128 | `CreateReachCampaignChannelVariants` | `reach_campaign_channel_variants` |
| 100129 | `CreateReachAudienceSegments` | `reach_audience_segments` |
| 100130 | `CreateReachAudienceSegmentRules` | `reach_audience_segment_rules` |
| 100131 | `CreateReachCampaignAudienceSnapshots` | `reach_campaign_audience_snapshots` |
| 100132 | `CreateReachCampaignAudienceRecipients` | `reach_campaign_audience_recipients` |
| 100133 | `CreateReachChannelConsents` | `reach_channel_consents` |
| 100134 | `CreateReachChannelSuppressions` | `reach_channel_suppressions` |
| 100135 | `CreateReachCampaignDispatches` | `reach_campaign_dispatches` |
| 100136 | `CreateReachCampaignDeliveryAttempts` | `reach_campaign_delivery_attempts` |
| 100137 | `CreateReachSmsCampaigns` | `reach_sms_campaigns` |
| 100138 | `CreateReachCampaignSenderProfiles` | `reach_campaign_sender_profiles` |
| 100139 | `CreateReachCampaignTemplates` | `reach_campaign_templates` |
| 100140 | `CreateReachCampaignTemplateVersions` | `reach_campaign_template_versions` |
| 100141 | `CreateReachCampaignProviderEvents` | `reach_campaign_provider_events` |
| 100142 | `CreateReachCampaignOperationalMetrics` | `reach_campaign_operational_metrics` |
| 100143 | `AddDistributionPermissions` | Inserts permission rows |

### Rollback order (child-before-parent)

```
100143 → 100142 → 100141 → 100140 → 100139 → 100138
→ 100137 → 100136 → 100135 → 100134 → 100133 → 100132
→ 100131 → 100130 → 100129 → 100128 → 100127
→ 100126 → 100125 → 100124 → 100123
```

---

## CP2 — Permissions and provider contracts

### New permission group: `distribution`

```php
DISTRIBUTION_READ               = 'distribution.read'
DISTRIBUTION_CREATE             = 'distribution.create'
DISTRIBUTION_UPDATE             = 'distribution.update'
DISTRIBUTION_SEGMENT            = 'distribution.segment'
DISTRIBUTION_PREVIEW            = 'distribution.preview'
DISTRIBUTION_TEST_SEND          = 'distribution.test_send'
DISTRIBUTION_SUBMIT             = 'distribution.submit'
DISTRIBUTION_REVIEW             = 'distribution.review'
DISTRIBUTION_APPROVE            = 'distribution.approve'
DISTRIBUTION_SCHEDULE           = 'distribution.schedule'
DISTRIBUTION_DISPATCH           = 'distribution.dispatch'
DISTRIBUTION_PAUSE              = 'distribution.pause'
DISTRIBUTION_CANCEL             = 'distribution.cancel'
DISTRIBUTION_RETRY              = 'distribution.retry'
DISTRIBUTION_CONNECTIONS_READ   = 'distribution.connections.read'
DISTRIBUTION_CONNECTIONS_MANAGE = 'distribution.connections.manage'
DISTRIBUTION_TEMPLATES_READ     = 'distribution.templates.read'
DISTRIBUTION_TEMPLATES_MANAGE   = 'distribution.templates.manage'
DISTRIBUTION_CONSENT_READ       = 'distribution.consent.read'
DISTRIBUTION_CONSENT_MANAGE     = 'distribution.consent.manage'
DISTRIBUTION_SUPPRESSION_READ   = 'distribution.suppression.read'
DISTRIBUTION_SUPPRESSION_MANAGE = 'distribution.suppression.manage'
DISTRIBUTION_OPERATIONS_READ    = 'distribution.operations.read'
DISTRIBUTION_AUDIT_READ         = 'distribution.audit.read'
SMS_READ                        = 'sms.read'
SMS_CREATE                      = 'sms.create'
SMS_UPDATE                      = 'sms.update'
SMS_SEND                        = 'sms.send'
```

### Provider interfaces (location: `app/Libraries/Distribution/Providers/`)

- `SocialPublisherInterface`
- `EmailSenderInterface`
- `WhatsAppSenderInterface`
- `SmsSenderInterface`
- `ChannelProviderFactory`
- Mock implementations: `MockSocialPublisher`, `MockEmailSender`, `MockWhatsAppSender`, `MockSmsSender`
- `DistributionCallbackAuthenticator` (extends Phase 6 HMAC pattern)
- Disabled production adapters: `MockSocialPublisher` (returns `SOCIAL_PROVIDER_NOT_CONFIGURED` unless env set)

---

## CP3 — Audience, consent, suppression (API routes)

```
GET/POST      v1/distribution/segments
GET/PUT/DELETE v1/distribution/segments/:id
POST          v1/distribution/segments/:id/preview
GET/POST      v1/distribution/consents
DELETE        v1/distribution/consents/:id
GET/POST      v1/distribution/suppressions
DELETE        v1/distribution/suppressions/:id
POST          v1/distribution/campaigns/:id/audience-snapshot
GET           v1/distribution/campaigns/:id/audience-snapshot
```

---

## CP4 — Campaign versions and approval (API routes)

```
POST          v1/campaigns/:id/versions
GET           v1/campaigns/:id/versions
GET           v1/campaigns/:id/versions/:v
POST          v1/campaigns/:id/versions/:v/submit
POST          v1/campaigns/:id/versions/:v/approve
POST          v1/campaigns/:id/versions/:v/reject
POST          v1/campaigns/:id/versions/:v/request-changes
GET           v1/campaigns/:id/versions/:v/variants
POST          v1/campaigns/:id/versions/:v/variants
PUT           v1/distribution/variants/:id
POST          v1/distribution/variants/:id/validate
```

---

## CP5–CP8 — Channel dispatch (API routes)

```
# Social
POST    v1/distribution/social/dispatch/:post_id
GET     v1/distribution/social/status/:post_id

# Email
POST    v1/distribution/email/dispatch/:campaign_id
GET     v1/distribution/email/status/:campaign_id
POST    v1/distribution/email/test/:campaign_id

# WhatsApp
POST    v1/distribution/whatsapp/dispatch/:campaign_id
GET     v1/distribution/whatsapp/status/:campaign_id
POST    v1/distribution/whatsapp/test/:campaign_id

# SMS
POST    v1/sms/campaigns
GET     v1/sms/campaigns
GET     v1/sms/campaigns/:id
POST    v1/distribution/sms/dispatch/:campaign_id
GET     v1/distribution/sms/status/:campaign_id
POST    v1/distribution/sms/test/:campaign_id

# Callbacks (unauthenticated, HMAC-verified)
POST    v1/distribution/callbacks/social
POST    v1/distribution/callbacks/email
POST    v1/distribution/callbacks/whatsapp
POST    v1/distribution/callbacks/sms

# Sender profiles and templates
GET/POST      v1/distribution/sender-profiles
GET/PUT/DELETE v1/distribution/sender-profiles/:id
GET/POST      v1/distribution/templates
GET/PUT/DELETE v1/distribution/templates/:id
POST          v1/distribution/templates/:id/versions
GET           v1/distribution/templates/:id/versions
GET           v1/distribution/templates/:id/versions/:v

# Operations
GET     v1/distribution/operations
GET     v1/distribution/dispatches
GET     v1/distribution/dispatches/:id
GET     v1/distribution/delivery-attempts
GET     v1/distribution/analytics
```

---

## CP9 — Dispatch jobs

| Job key | Handler class |
|---------|---------------|
| `reach.campaign_schedule_dispatch` | `CampaignScheduleDispatchJob` |
| `reach.campaign_channel_batch` | `CampaignChannelBatchJob` |
| `reach.campaign_delivery_retry` | `CampaignDeliveryRetryJob` |
| `reach.campaign_provider_event` | `CampaignProviderEventJob` |
| `reach.campaign_delivery_reconciliation` | `CampaignDeliveryReconciliationJob` |
| `reach.campaign_dead_letter_recovery` | `CampaignDeadLetterRecoveryJob` |

---

## CP10 — Frontend routes

```
/distribution                    DistributionOverviewPage
/distribution/campaigns          (upgrade existing CampaignListPage)
/distribution/campaigns/:id      CampaignWorkspacePage
/distribution/audiences          AudienceOverviewPage
/distribution/segments           AudienceSegmentsPage
/distribution/segments/:id       SegmentBuilderPage
/distribution/templates          TemplateCatalogPage
/distribution/templates/:id      TemplateEditorPage
/distribution/social             SocialOperationsPage
/distribution/email              EmailOperationsPage
/distribution/whatsapp           WhatsAppOperationsPage
/distribution/sms                SmsOperationsPage
/distribution/connections        ChannelConnectionsPage
/distribution/suppressions       SuppressionPage
/distribution/operations         DistributionOperationsPage
/distribution/analytics          CampaignAnalyticsPage
/sms                             SmsListPage (consistent with /email, /whatsapp)
/sms/:id                         SmsDetailPage
```

---

## Phase 8 evidence contracts (prepared in CP10)

The following data is captured by Phase 7 and will be available to Phase 8 intelligence connectors:

- Canonical campaign ID (`reach_campaigns.uuid`)
- Canonical content ID (linked from `reach_campaign_channel_variants.source_content_id`)
- Channel ID (`reach_campaign_dispatches.channel`)
- Provider ID (`reach_campaign_dispatches.connection_id`)
- Remote message/post ID (`reach_campaign_delivery_attempts.provider_message_id`)
- UTM parameters (`reach_campaigns.utm_*`)
- Dispatch timestamps (accepted_at, sent_at, delivered_at, read_at, failed_at)
- Provider event source label (`reach_campaign_provider_events.provider`)
- Normalised status (`reach_campaign_delivery_attempts.status`)
- Operational metrics (`reach_campaign_operational_metrics`)

---

## Explicit Phase 8 non-implementation

> No Phase 8 GSC ingestion, IndexNow intelligence, AI visibility monitoring, competitor monitoring, attribution models, or content-refresh automation is implemented in Phase 7.
