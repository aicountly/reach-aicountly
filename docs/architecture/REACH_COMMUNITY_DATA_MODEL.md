# Reach Community Data Model — Phase 5

## Overview

Phase 5 adds 16 database tables to `reach-aicountly` and extends 3 existing tables in `aicountly-com`. All new Reach tables use `BIGSERIAL` primary keys, `UUID` external identifiers, `TIMESTAMPTZ` timestamps, and `CHECK` constraints for status enums.

---

## Reach Migrations (100090–100105)

### 100090 — reach_community_spaces

Controlled community spaces (product-questions, compliance-questions, etc.)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | External identifier |
| slug | VARCHAR(120) UNIQUE | URL slug |
| title | VARCHAR(255) | |
| description | TEXT | |
| visibility | VARCHAR(20) | CHECK (public/private/restricted) |
| moderation_mode | VARCHAR(20) | CHECK (pre/post/none) |
| official_answer_policy | VARCHAR(20) | CHECK (required/optional/disabled) |
| allowed_content_types | TEXT[] | Array of allowed types |
| indexing_policy | VARCHAR(20) | CHECK (index/noindex) |
| status | VARCHAR(20) | CHECK (active/archived/disabled) |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### 100091 — reach_community_questions

Genuine community question intake. Extends the content model with community-specific intake fields.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | External identifier |
| content_item_id | BIGINT FK | → reach_content_items (nullable for direct intake) |
| space_id | BIGINT FK | → reach_community_spaces |
| source_type | VARCHAR(40) | CHECK: manual/import/content_request/official_question/public_submission |
| source_url | TEXT | Original source reference |
| external_question_id | VARCHAR(255) | External platform ID where applicable |
| author_reference | VARCHAR(512) | Anonymised or consented author reference |
| author_display_consent | BOOLEAN | Whether author consented to display |
| title | VARCHAR(512) | |
| body | TEXT | |
| language | VARCHAR(10) | ISO 639-1 |
| product | VARCHAR(120) | |
| category | VARCHAR(120) | |
| tags | TEXT[] | |
| jurisdiction | VARCHAR(80) | |
| question_timestamp | TIMESTAMPTZ | When the question was asked (source time) |
| intake_timestamp | TIMESTAMPTZ | When Reach ingested it |
| sensitivity_flags | TEXT[] | PII, confidential, etc. |
| personal_data_detected | BOOLEAN DEFAULT false | |
| spam_score | DECIMAL(4,3) | 0.000–1.000 |
| moderation_state | VARCHAR(30) | CHECK: clean/pending_review/flagged/removed |
| duplicate_cluster_id | BIGINT FK | → reach_community_duplicate_clusters |
| triage_score | DECIMAL(6,3) | |
| assigned_to | BIGINT FK | → reach_users (nullable) |
| status | VARCHAR(30) | CHECK lifecycle states |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### 100092 — reach_community_question_classifications

Classification results per question (may be updated as models improve).

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| question_id | BIGINT FK | → reach_community_questions |
| product_classification | VARCHAR(120) | |
| category_classification | VARCHAR(120) | |
| risk_classification | VARCHAR(20) | CHECK: low/medium/high/critical |
| jurisdiction_classification | VARCHAR(80) | |
| language_detected | VARCHAR(10) | |
| complexity_score | DECIMAL(4,3) | |
| classified_at | TIMESTAMPTZ | |
| classified_by | VARCHAR(40) | 'ai' or 'human' |
| model_slug | VARCHAR(120) | Model used for classification |

---

### 100093 — reach_community_official_identities

Controlled official responder identities. Never linked to customer accounts.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| slug | VARCHAR(120) UNIQUE | |
| display_name | VARCHAR(255) | |
| department | VARCHAR(120) | |
| badge_type | VARCHAR(40) | CHECK: official/compliance/support/product/engineering |
| avatar_reference | VARCHAR(512) | |
| authorised_scopes | TEXT[] | Which spaces/categories this identity covers |
| disclosure_template | TEXT | Standard disclosure text |
| approval_requirements | JSONB | Override approval matrix for this identity |
| is_active | BOOLEAN DEFAULT true | |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### 100094 — reach_community_official_answers

Master record for each official answer to a community question.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| question_id | BIGINT FK | → reach_community_questions |
| identity_id | BIGINT FK | → reach_community_official_identities |
| current_version | INT DEFAULT 1 | |
| approved_version | INT | Version approved for publication |
| approved_version_checksum | VARCHAR(64) | SHA-256 of approved version content |
| public_external_id | VARCHAR(255) | Public site answer ID |
| public_url | TEXT | |
| publication_status | VARCHAR(30) | CHECK: unpublished/scheduled/published/withdrawn |
| ai_assisted | BOOLEAN DEFAULT false | |
| human_reviewed | BOOLEAN DEFAULT false | |
| risk_classification | VARCHAR(20) | CHECK: low/medium/high/critical |
| jurisdiction | VARCHAR(80) | |
| product | VARCHAR(120) | |
| language | VARCHAR(10) | |
| correction_state | VARCHAR(20) | CHECK: none/pending/corrected |
| correction_note | TEXT | Public correction notice |
| withdrawal_state | VARCHAR(20) | CHECK: none/withdrawn |
| status | VARCHAR(30) | CHECK lifecycle states |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### 100095 — reach_community_answer_versions

Immutable version records. Content is never updated after creation.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| answer_id | BIGINT FK | → reach_community_official_answers |
| version_number | INT | |
| content | TEXT | Full HTML/structured content |
| excerpt | TEXT | Plain text excerpt |
| sources | JSONB | Source references array |
| grounding_snapshot_id | BIGINT FK | → reach_knowledge_grounding_snapshots |
| generation_request_id | BIGINT FK | → reach_ai_generation_requests (nullable) |
| generation_run_id | BIGINT FK | → reach_ai_generation_runs (nullable) |
| generation_artifact_id | BIGINT FK | → reach_ai_generation_artifacts (nullable) |
| prompt_version | VARCHAR(80) | |
| model_route | VARCHAR(120) | |
| validation_results | JSONB | |
| risk_findings | JSONB | |
| moderation_decision | VARCHAR(20) | |
| reviewer_id | BIGINT FK | → reach_users (nullable) |
| approver_id | BIGINT FK | → reach_users (nullable) |
| approval_timestamp | TIMESTAMPTZ | |
| checksum | VARCHAR(64) NOT NULL | SHA-256 of content |
| creation_reason | VARCHAR(40) | CHECK: initial/edit/correction/translation |
| superseded_by | INT | Version number that supersedes this one |
| created_at | TIMESTAMPTZ | |
| UNIQUE | (answer_id, version_number) | |

---

### 100096 — reach_community_moderation_findings

Moderation findings per answer version.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| answer_version_id | BIGINT FK | → reach_community_answer_versions |
| question_id | BIGINT FK | → reach_community_questions (nullable, for question moderation) |
| finding_type | VARCHAR(40) | See governance doc for types |
| severity | VARCHAR(20) | CHECK: info/warning/error/critical |
| details | JSONB | Finding details |
| auto_action | VARCHAR(30) | Action taken automatically |
| override_by | BIGINT FK | → reach_users (nullable) |
| override_reason | TEXT | |
| override_at | TIMESTAMPTZ | |
| status | VARCHAR(20) | CHECK: open/resolved/overridden/dismissed |
| created_at | TIMESTAMPTZ | |

---

### 100097 — reach_community_answer_approvals

Approval records extending the Phase 0 approval pattern. Subject type: `official_answer`.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| answer_id | BIGINT FK | → reach_community_official_answers |
| answer_version_number | INT | The version being approved |
| version_checksum | VARCHAR(64) | SHA-256 must match version checksum |
| approval_reach_approval_id | BIGINT FK | → reach_approvals |
| approved_by | BIGINT FK | → reach_users |
| approval_type | VARCHAR(30) | CHECK: standard/professional_review/compliance_review |
| outcome | VARCHAR(20) | CHECK: approved/rejected/changes_requested |
| reason | TEXT | |
| created_at | TIMESTAMPTZ | |

---

### 100098 — reach_community_deployments

Publication deployment records, following Phase 4 pattern.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| answer_id | BIGINT FK | → reach_community_official_answers |
| answer_version_number | INT | |
| version_checksum | VARCHAR(64) | |
| operation | VARCHAR(30) | CHECK: publish/unpublish/withdraw/restore/update |
| idempotency_key | UUID | |
| status | VARCHAR(20) | CHECK: pending/executing/succeeded/failed/retrying |
| attempt_count | INT DEFAULT 0 | |
| max_attempts | INT DEFAULT 3 | |
| last_error | TEXT | |
| last_error_category | VARCHAR(40) | |
| next_retry_at | TIMESTAMPTZ | |
| public_answer_id | VARCHAR(255) | Public site ID returned on success |
| public_url | TEXT | |
| response_checksum | VARCHAR(64) | Checksum returned by public site |
| deployed_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### 100099 — reach_community_answer_verifications

Verification run results.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| deployment_id | BIGINT FK | → reach_community_deployments |
| answer_id | BIGINT FK | → reach_community_official_answers |
| verified_at | TIMESTAMPTZ | |
| public_status | VARCHAR(30) | |
| public_version | INT | |
| checksum_match | BOOLEAN | |
| expected_checksum | VARCHAR(64) | |
| actual_checksum | VARCHAR(64) | |
| canonical_url_ok | BOOLEAN | |
| robots_ok | BOOLEAN | |
| sitemap_ok | BOOLEAN | |
| verification_outcome | VARCHAR(20) | CHECK: passed/failed/mismatch/not_found |
| details | JSONB | |
| created_at | TIMESTAMPTZ | |

---

### 100100 — reach_community_engagement_events

Genuine engagement events only. No synthetic data.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| event_type | VARCHAR(40) | CHECK: page_view/helpful/not_helpful/reply/report/click |
| answer_id | BIGINT FK | → reach_community_official_answers (nullable) |
| question_id | BIGINT FK | → reach_community_questions (nullable) |
| source | VARCHAR(40) | Where the event originated |
| event_timestamp | TIMESTAMPTZ | When the event occurred |
| deduplication_key | VARCHAR(255) | Prevent duplicate ingestion |
| session_reference | VARCHAR(255) | Anonymous session ref |
| bot_filtered | BOOLEAN DEFAULT false | |
| validated | BOOLEAN DEFAULT false | |
| ingested_at | TIMESTAMPTZ DEFAULT NOW() | |
| UNIQUE | (deduplication_key) | |

---

### 100101 — reach_community_source_coverage

Source citation coverage per answer version.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| answer_version_id | BIGINT FK | → reach_community_answer_versions |
| source_type | VARCHAR(40) | CHECK: kb_article/blog/product_doc/release_note/policy/feature_record/external |
| source_id | BIGINT | ID in the source table |
| source_uuid | UUID | |
| source_title | VARCHAR(512) | Snapshot at time of grounding |
| source_version | VARCHAR(80) | |
| source_url | TEXT | |
| claim_reference | TEXT | The claim this source supports |
| coverage_status | VARCHAR(20) | CHECK: covered/partial/missing/conflicted |
| created_at | TIMESTAMPTZ | |

---

### 100102 — reach_community_duplicate_clusters

Duplicate question clusters for deduplication.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID UNIQUE | |
| canonical_question_id | BIGINT FK | → reach_community_questions |
| member_count | INT DEFAULT 1 | |
| similarity_algorithm | VARCHAR(40) | |
| similarity_threshold | DECIMAL(4,3) | |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### 100103 — Add community.* permissions

Migration adds 22 permission constants to the permissions enum/config for use with `reach_user_permissions`.

---

### 100104 — Add Phase 5 audit event constants

Migration documents 30+ `community.*` audit event constants in a reference table. No schema change needed; `AuditLogger` uses free-form event strings.

---

### 100105 — reach_community_analytics_cache

Materialised cache for analytics queries. Refreshed by scheduled job.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| metric_key | VARCHAR(120) UNIQUE | |
| dimension | VARCHAR(120) | Optional dimension value |
| period_start | TIMESTAMPTZ | |
| period_end | TIMESTAMPTZ | |
| value | DECIMAL(15,4) | |
| meta | JSONB | |
| computed_at | TIMESTAMPTZ | |

---

## aicountly-com Schema Extensions

Migration file: `database/migrations/006_community_reach_integration.pgsql.sql`

### Extensions to community_answers

```sql
ALTER TABLE community_answers
  ADD COLUMN IF NOT EXISTS reach_answer_uuid UUID,
  ADD COLUMN IF NOT EXISTS official_identity_id INTEGER REFERENCES community_official_identities(id),
  ADD COLUMN IF NOT EXISTS ai_assisted BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS human_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS answer_version INTEGER NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS payload_checksum VARCHAR(64),
  ADD COLUMN IF NOT EXISTS correction_note TEXT,
  ADD COLUMN IF NOT EXISTS withdrawn_at TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS reach_published_at TIMESTAMPTZ;
```

### New Table: community_official_identities

```sql
CREATE TABLE community_official_identities (
  id SERIAL PRIMARY KEY,
  slug VARCHAR(120) NOT NULL UNIQUE,
  display_name VARCHAR(255) NOT NULL,
  department VARCHAR(120),
  badge_type VARCHAR(40) NOT NULL DEFAULT 'official',
  disclosure_template TEXT,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### New Table: reach_api_community_idempotency

```sql
CREATE TABLE reach_api_community_idempotency (
  id SERIAL PRIMARY KEY,
  idempotency_key UUID NOT NULL UNIQUE,
  operation VARCHAR(40) NOT NULL,
  reach_answer_uuid UUID,
  response_body JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX ON reach_api_community_idempotency(created_at);
```
