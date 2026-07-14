# Phase 7 — Data Model Reference

**Date:** 2026-07-15

---

## Tables extended in Phase 7

### `reach_campaigns` (ALTER — migration 100123)

New columns: `uuid UUID UNIQUE`, `tenant_id BIGINT`, `lock_version INT DEFAULT 0`  
Extended status CHECK to include: `preparing`, `ready_for_review`, `in_review`, `dispatching`, `partially_completed`, `completed`, `paused`, `dead_lettered`, `expired`

**Tenant key:** `tenant_id`  
**Concurrency:** `lock_version` (optimistic)  
**Phase 8:** canonical campaign ID via `uuid`

---

### `reach_social_posts` (ALTER — migration 100124)

New columns: `uuid UUID UNIQUE`, `tenant_id BIGINT`, `connection_id BIGINT`, `destination_id VARCHAR(255)`, `remote_post_id VARCHAR(255)`, `remote_url VARCHAR(500)`, `provider VARCHAR(64)`, `dispatch_id BIGINT`

---

### `reach_email_campaigns` (ALTER — migration 100125)

New columns: `uuid UUID UNIQUE`, `tenant_id BIGINT`, `connection_id BIGINT`, `sender_profile_id BIGINT`, `template_version_id BIGINT`, `preview_text VARCHAR(255)`, `dispatch_id BIGINT`

---

### `reach_whatsapp_campaigns` (ALTER — migration 100126)

New columns: `uuid UUID UNIQUE`, `tenant_id BIGINT`, `connection_id BIGINT`, `template_version_id BIGINT`, `dispatch_id BIGINT`

---

## New tables

### `reach_campaign_versions` (migration 100127)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | Stable external identity |
| campaign_id | BIGINT FK reach_campaigns | |
| version_number | INT | UNIQUE per campaign_id |
| content_hash | VARCHAR(64) | SHA-256 of serialised variants |
| audience_snapshot_id | BIGINT | Set at approval |
| submitted_by | BIGINT FK reach_actors | |
| submitted_at | TIMESTAMPTZ | |
| approved_by | BIGINT FK reach_actors | |
| approved_at | TIMESTAMPTZ | |
| rejected_by | BIGINT FK reach_actors | |
| rejected_at | TIMESTAMPTZ | |
| rejection_reason | TEXT | |
| created_by | BIGINT FK reach_actors | |
| created_at | TIMESTAMPTZ NOT NULL DEFAULT NOW() | **No updated_at — immutable** |

**Immutable:** once created, rows are never updated  
**Unique constraint:** `(campaign_id, version_number)`

---

### `reach_campaign_channel_variants` (migration 100128)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| campaign_version_id | BIGINT FK reach_campaign_versions | |
| channel | VARCHAR(32) CHECK(social\|email\|whatsapp\|sms) | |
| source_content_id | BIGINT | Link to approved content |
| template_version_id | BIGINT FK reach_campaign_template_versions | |
| content_json | JSONB NOT NULL | Channel-specific content |
| merge_field_values | JSONB | |
| validation_status | VARCHAR(32) CHECK(pending\|valid\|invalid) | |
| validation_findings | JSONB | |
| generation_artifact_id | BIGINT | If AI-generated |
| created_by | BIGINT FK reach_actors | |
| created_at | TIMESTAMPTZ NOT NULL DEFAULT NOW() | **No updated_at — immutable** |

---

### `reach_audience_segments` (migration 100129)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| tenant_id | BIGINT NOT NULL | |
| name | VARCHAR(200) NOT NULL | |
| description | TEXT | |
| segment_type | VARCHAR(32) CHECK(static\|dynamic) | |
| criteria_summary | TEXT | Human-readable summary |
| estimated_count | INT | Updated on preview |
| created_by | BIGINT FK reach_actors | |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### `reach_audience_segment_rules` (migration 100130)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| segment_id | BIGINT FK reach_audience_segments | |
| rule_group | INT NOT NULL DEFAULT 0 | Groups within group are ANDed; groups ORed |
| field | VARCHAR(128) NOT NULL | Allowlisted field name |
| operator | VARCHAR(32) NOT NULL CHECK(eq\|neq\|contains\|not_contains\|gt\|lt\|in\|not_in\|is_null\|is_not_null) | |
| value | TEXT | |
| negated | BOOLEAN DEFAULT false | |
| created_at | TIMESTAMPTZ | |

---

### `reach_campaign_audience_snapshots` (migration 100131)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| campaign_id | BIGINT FK reach_campaigns | |
| campaign_version_id | BIGINT FK reach_campaign_versions | |
| tenant_id | BIGINT NOT NULL | |
| channel | VARCHAR(32) | |
| recipient_count | INT DEFAULT 0 | |
| eligible_count | INT DEFAULT 0 | |
| suppressed_count | INT DEFAULT 0 | |
| snapshot_criteria | JSONB | Segment rules at freeze time |
| frozen_at | TIMESTAMPTZ | Set when snapshot locked — **immutable after this** |
| frozen_by | BIGINT FK reach_actors | |
| created_at | TIMESTAMPTZ | |

**Immutable after `frozen_at` is set**

---

### `reach_campaign_audience_recipients` (migration 100132)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| snapshot_id | BIGINT FK reach_campaign_audience_snapshots | |
| tenant_id | BIGINT NOT NULL | |
| channel | VARCHAR(32) | |
| channel_address_hash | VARCHAR(128) NOT NULL | SHA-256 of address (privacy) |
| channel_address_masked | VARCHAR(100) | Last 4 chars visible |
| consent_status | VARCHAR(32) CHECK(granted\|revoked\|unknown) | At snapshot time |
| suppressed | BOOLEAN DEFAULT false | |
| suppression_reason | VARCHAR(64) | |
| eligibility_status | VARCHAR(32) CHECK(eligible\|ineligible\|suppressed\|no_consent\|invalid_address) | |
| eligibility_reason | TEXT | |
| dedup_key | VARCHAR(256) | `sha256(snapshot_id + channel + address)` |
| created_at | TIMESTAMPTZ | |

**Unique constraint:** `(dedup_key)` — prevents duplicate recipient in same snapshot

---

### `reach_channel_consents` (migration 100133)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| tenant_id | BIGINT NOT NULL | |
| subject_type | VARCHAR(64) NOT NULL | e.g. `lead`, `contact` |
| subject_id | BIGINT NOT NULL | |
| channel | VARCHAR(32) NOT NULL | |
| purpose | VARCHAR(64) NOT NULL | e.g. `marketing`, `transactional` |
| status | VARCHAR(32) CHECK(granted\|revoked\|expired) | |
| source | VARCHAR(64) | |
| proof_reference | TEXT | Form ID, webhook ID, etc. |
| captured_at | TIMESTAMPTZ NOT NULL | |
| captured_by | BIGINT FK reach_actors | |
| revoked_at | TIMESTAMPTZ | |
| expires_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ | |

---

### `reach_channel_suppressions` (migration 100134)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| tenant_id | BIGINT NOT NULL | |
| channel | VARCHAR(32) NOT NULL | |
| address_hash | VARCHAR(128) NOT NULL | SHA-256(tenant_id + channel + normalised_address) |
| address_masked | VARCHAR(100) | |
| reason | VARCHAR(64) CHECK(unsubscribe\|bounce\|complaint\|manual\|legal\|opt_out) | |
| source | VARCHAR(64) | |
| suppressed_at | TIMESTAMPTZ NOT NULL DEFAULT NOW() | |
| suppressed_by | BIGINT FK reach_actors | |
| expires_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ | |

**Unique constraint:** `(tenant_id, channel, address_hash)` — one suppression record per address per channel

---

### `reach_campaign_dispatches` (migration 100135)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| campaign_id | BIGINT FK reach_campaigns | |
| campaign_version_id | BIGINT FK reach_campaign_versions | |
| snapshot_id | BIGINT FK reach_campaign_audience_snapshots | |
| tenant_id | BIGINT NOT NULL | |
| channel | VARCHAR(32) NOT NULL | |
| status | VARCHAR(32) CHECK(queued\|dispatching\|paused\|cancelled\|partially_completed\|completed\|failed\|dead_lettered) | |
| connection_id | BIGINT | FK to reach_publication_connections |
| idempotency_key | VARCHAR(128) UNIQUE | |
| scheduled_at | TIMESTAMPTZ | |
| started_at | TIMESTAMPTZ | |
| completed_at | TIMESTAMPTZ | |
| total_recipients | INT DEFAULT 0 | |
| sent_count | INT DEFAULT 0 | |
| failed_count | INT DEFAULT 0 | |
| suppressed_count | INT DEFAULT 0 | |
| lock_version | INT DEFAULT 0 | Optimistic concurrency |
| created_by | BIGINT FK reach_actors | |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### `reach_campaign_delivery_attempts` (migration 100136)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| dispatch_id | BIGINT FK reach_campaign_dispatches | |
| recipient_id | BIGINT FK reach_campaign_audience_recipients | |
| attempt_number | INT NOT NULL DEFAULT 1 | |
| status | VARCHAR(32) CHECK(queued\|sending\|accepted\|sent\|delivered\|read\|failed\|bounced\|complained\|unsubscribed\|suppressed) | |
| provider | VARCHAR(64) | |
| provider_message_id | VARCHAR(255) | |
| remote_url | VARCHAR(500) | For social posts |
| failure_class | VARCHAR(64) CHECK(permanent\|transient\|rate_limit\|rejected\|unknown) | |
| failure_detail | TEXT | |
| provider_latency_ms | INT | |
| idempotency_key | VARCHAR(128) UNIQUE | |
| accepted_at | TIMESTAMPTZ | |
| sent_at | TIMESTAMPTZ | |
| delivered_at | TIMESTAMPTZ | |
| read_at | TIMESTAMPTZ | |
| failed_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ | |

---

### `reach_sms_campaigns` (migration 100137)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| campaign_id | BIGINT FK reach_campaigns | |
| tenant_id | BIGINT NOT NULL | |
| sender_profile_id | BIGINT FK reach_campaign_sender_profiles | |
| template_version_id | BIGINT FK reach_campaign_template_versions | |
| template_variables | JSONB | |
| dlt_entity_id | VARCHAR(100) | TRAI PE ID |
| dlt_template_id | VARCHAR(100) | TRAI template ID |
| dlt_sender_id | VARCHAR(100) | Registered DLT sender/header |
| provider | VARCHAR(64) | |
| connection_id | BIGINT | FK to reach_publication_connections |
| audience_filter | JSONB | Legacy filter (snapshot takes precedence) |
| scheduled_at | TIMESTAMPTZ | |
| sent_at | TIMESTAMPTZ | |
| status | VARCHAR(32) CHECK(draft\|pending_approval\|approved\|scheduled\|sending\|sent\|failed\|archived) | |
| stats | JSONB | |
| created_by | BIGINT FK reach_actors | |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### `reach_campaign_sender_profiles` (migration 100138)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| tenant_id | BIGINT NOT NULL | |
| channel | VARCHAR(32) CHECK(email\|sms\|whatsapp) | |
| name | VARCHAR(200) NOT NULL | |
| from_address | VARCHAR(255) | |
| display_name | VARCHAR(200) | |
| reply_to | VARCHAR(255) | |
| verified | BOOLEAN DEFAULT false | |
| provider | VARCHAR(64) | |
| connection_id | BIGINT | |
| dlt_header | VARCHAR(100) | For SMS DLT |
| created_by | BIGINT FK reach_actors | |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### `reach_campaign_templates` (migration 100139)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| tenant_id | BIGINT NOT NULL | |
| channel | VARCHAR(32) CHECK(email\|whatsapp\|sms\|social) | |
| name | VARCHAR(200) NOT NULL | |
| provider_template_id | VARCHAR(255) | Provider-assigned ID (WhatsApp BSP) |
| language | VARCHAR(20) DEFAULT 'en' | |
| approval_status | VARCHAR(32) CHECK(draft\|pending\|approved\|rejected\|paused) | |
| created_by | BIGINT FK reach_actors | |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### `reach_campaign_template_versions` (migration 100140)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| template_id | BIGINT FK reach_campaign_templates | |
| version_number | INT NOT NULL | UNIQUE per template_id |
| content_json | JSONB NOT NULL | Full template content |
| merge_field_schema | JSONB | Allowed merge fields + types |
| character_count | INT | |
| segment_count | INT | For SMS |
| approved_by | BIGINT FK reach_actors | |
| approved_at | TIMESTAMPTZ | |
| created_by | BIGINT FK reach_actors | |
| created_at | TIMESTAMPTZ NOT NULL DEFAULT NOW() | **No updated_at — immutable** |

---

### `reach_campaign_provider_events` (migration 100141)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| tenant_id | BIGINT NOT NULL | |
| dispatch_id | BIGINT FK reach_campaign_dispatches | |
| attempt_id | BIGINT FK reach_campaign_delivery_attempts | |
| provider | VARCHAR(64) NOT NULL | |
| connection_id | BIGINT | |
| event_type | VARCHAR(64) NOT NULL | e.g. `delivery`, `bounce`, `open`, `click` |
| raw_event | JSONB | |
| normalised_status | VARCHAR(32) | |
| provider_event_id | VARCHAR(255) | |
| received_at | TIMESTAMPTZ NOT NULL | |
| processed_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ | |

**Unique constraint:** `(provider, connection_id, provider_event_id)` — deduplication

---

### `reach_campaign_operational_metrics` (migration 100142)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| dispatch_id | BIGINT FK reach_campaign_dispatches UNIQUE | One row per dispatch |
| tenant_id | BIGINT NOT NULL | |
| channel | VARCHAR(32) | |
| queued | INT DEFAULT 0 | |
| attempted | INT DEFAULT 0 | |
| accepted | INT DEFAULT 0 | |
| sent | INT DEFAULT 0 | |
| delivered | INT DEFAULT 0 | |
| read_count | INT DEFAULT 0 | |
| failed | INT DEFAULT 0 | |
| bounced | INT DEFAULT 0 | |
| complained | INT DEFAULT 0 | |
| unsubscribed | INT DEFAULT 0 | |
| suppressed | INT DEFAULT 0 | |
| last_updated | TIMESTAMPTZ | |
