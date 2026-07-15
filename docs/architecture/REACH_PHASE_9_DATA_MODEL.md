# Phase 9 Data Model

**Migration range:** 100172–100194
**PostgreSQL compatible**

---

## Table Reference

### `reach_refresh_policies` (100172)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID NOT NULL UNIQUE | |
| tenant_id | BIGINT NOT NULL FK reach_actors | |
| name | VARCHAR(200) NOT NULL | |
| content_type | VARCHAR(50) NOT NULL | blog, knowledge_base, community_answer, video, campaign |
| is_active | BOOLEAN NOT NULL DEFAULT FALSE | |
| created_by | BIGINT FK reach_actors | |
| created_at | TIMESTAMPTZ NOT NULL | |
| updated_at | TIMESTAMPTZ NOT NULL | |

Unique: `(tenant_id, name)`
Index: `(tenant_id, content_type, is_active)`

---

### `reach_refresh_policy_versions` (100173)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| policy_id | BIGINT NOT NULL FK reach_refresh_policies | |
| version_number | INT NOT NULL | |
| min_publication_age_days | INT NOT NULL DEFAULT 30 | |
| comparison_window_days | INT NOT NULL DEFAULT 28 | |
| position_decline_threshold | NUMERIC(5,2) | avg position worsened by N |
| impressions_decline_pct | NUMERIC(5,2) | |
| clicks_decline_pct | NUMERIC(5,2) | |
| engagement_decline_pct | NUMERIC(5,2) | |
| cooldown_days | INT NOT NULL DEFAULT 14 | |
| required_evidence_sources | JSONB NOT NULL DEFAULT '[]' | |
| risk_escalation_rules | JSONB NOT NULL DEFAULT '{}' | |
| approved_by | BIGINT FK reach_actors | |
| approved_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ NOT NULL | |

Unique: `(policy_id, version_number)`
Immutable fields: all except `approved_by`, `approved_at` after creation

---

### `reach_refresh_evidence_snapshots` (100174)

Immutable. One per content identity per evidence period.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID NOT NULL UNIQUE | |
| tenant_id | BIGINT NOT NULL FK reach_actors | |
| content_identity_id | BIGINT NOT NULL FK reach_content_identities | |
| policy_version_id | BIGINT NOT NULL FK reach_refresh_policy_versions | |
| evidence_date | DATE NOT NULL | asOf date of evidence packet |
| window_days | INT NOT NULL | |
| evidence_packet | JSONB NOT NULL | immutable copy of getEvidencePacket() result |
| completeness_score | NUMERIC(4,3) NOT NULL | |
| missing_domains | JSONB NOT NULL DEFAULT '[]' | |
| freshness_state | JSONB NOT NULL | |
| created_at | TIMESTAMPTZ NOT NULL | |

Unique: `(content_identity_id, policy_version_id, evidence_date)`
No UPDATE permitted after creation.

---

### `reach_refresh_recommendations` (100175)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID NOT NULL UNIQUE | |
| tenant_id | BIGINT NOT NULL FK reach_actors | |
| content_identity_id | BIGINT NOT NULL FK reach_content_identities | |
| policy_version_id | BIGINT NOT NULL FK reach_refresh_policy_versions | |
| evidence_snapshot_id | BIGINT NOT NULL FK reach_refresh_evidence_snapshots | |
| status | VARCHAR(30) NOT NULL DEFAULT 'pending' | detected, recommended, triaged, accepted, rejected, deferred, superseded, expired |
| risk_classification | VARCHAR(20) NOT NULL DEFAULT 'low' | low, medium, high, critical |
| confidence | NUMERIC(4,3) | |
| effort_estimate | VARCHAR(20) | low, medium, high |
| cooldown_until | TIMESTAMPTZ | |
| superseded_by | BIGINT FK reach_refresh_recommendations | |
| assigned_to | BIGINT FK reach_actors | |
| due_date | DATE | |
| triage_notes | TEXT | |
| created_at | TIMESTAMPTZ NOT NULL | |
| updated_at | TIMESTAMPTZ NOT NULL | |

Unique: `(content_identity_id, policy_version_id, evidence_snapshot_id)` where status NOT IN ('rejected','superseded','expired')
Index: `(tenant_id, status)`, `(content_identity_id, status)`

---

### `reach_refresh_score_components` (100176)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| recommendation_id | BIGINT NOT NULL FK reach_refresh_recommendations | |
| factor | VARCHAR(80) NOT NULL | see factor list in REACH_CONTENT_REFRESH_INTELLIGENCE.md |
| raw_value | NUMERIC(12,4) | |
| weight | NUMERIC(4,3) NOT NULL | |
| contribution | NUMERIC(12,4) NOT NULL | |
| evidence_source | VARCHAR(50) | |
| evidence_period | VARCHAR(30) | |
| scoring_version | VARCHAR(20) NOT NULL | |
| created_at | TIMESTAMPTZ NOT NULL | |

No UPDATE — immutable per recommendation.

---

### `reach_refresh_workflows` (100177)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| uuid | UUID NOT NULL UNIQUE | |
| tenant_id | BIGINT NOT NULL FK reach_actors | |
| recommendation_id | BIGINT NOT NULL FK reach_refresh_recommendations | |
| content_identity_id | BIGINT NOT NULL FK reach_content_identities | |
| status | VARCHAR(40) NOT NULL DEFAULT 'accepted' | |
| lock_version | INT NOT NULL DEFAULT 0 | optimistic concurrency |
| refresh_objective | TEXT | |
| risk_classification | VARCHAR(20) | |
| assigned_to | BIGINT FK reach_actors | |
| due_date | DATE | |
| approved_by | BIGINT FK reach_actors | |
| approved_at | TIMESTAMPTZ | |
| cancelled_by | BIGINT FK reach_actors | |
| cancelled_at | TIMESTAMPTZ | |
| cancel_reason | TEXT | |
| created_at | TIMESTAMPTZ NOT NULL | |
| updated_at | TIMESTAMPTZ NOT NULL | |

Index: `(tenant_id, status)`, `(content_identity_id, status)`

---

### `reach_refresh_briefs` (100178)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| workflow_id | BIGINT NOT NULL FK reach_refresh_workflows | |
| evidence_snapshot_id | BIGINT NOT NULL FK reach_refresh_evidence_snapshots | |
| refresh_objective | TEXT NOT NULL | |
| key_changes | JSONB NOT NULL DEFAULT '[]' | |
| target_sections | JSONB NOT NULL DEFAULT '[]' | |
| source_requirements | JSONB NOT NULL DEFAULT '[]' | |
| created_by | BIGINT NOT NULL FK reach_actors | |
| created_at | TIMESTAMPTZ NOT NULL | |

Unique: `(workflow_id)` — one brief per workflow

---

### `reach_refresh_content_version_links` (100179)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| workflow_id | BIGINT NOT NULL FK reach_refresh_workflows | |
| content_version_id | BIGINT | FK reach_content_versions |
| blog_version_id | BIGINT | FK reach_blog_versions |
| community_answer_version_id | BIGINT | FK reach_community_answer_versions |
| video_script_version_id | BIGINT | FK reach_video_script_versions |
| generation_artifact_id | BIGINT | FK reach_ai_generation_artifacts |
| version_status | VARCHAR(30) NOT NULL DEFAULT 'draft' | |
| created_at | TIMESTAMPTZ NOT NULL | |
| updated_at | TIMESTAMPTZ NOT NULL | |

At least one version FK must be non-null (enforced in service layer).

---

### `reach_refresh_publication_links` (100180)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| workflow_id | BIGINT NOT NULL FK reach_refresh_workflows | |
| publication_attempt_id | BIGINT FK reach_publication_attempts | |
| idempotency_key | VARCHAR(100) NOT NULL UNIQUE | |
| published_at | TIMESTAMPTZ | |
| delivery_status | VARCHAR(30) NOT NULL DEFAULT 'pending' | |
| retry_count | INT NOT NULL DEFAULT 0 | |
| created_at | TIMESTAMPTZ NOT NULL | |
| updated_at | TIMESTAMPTZ NOT NULL | |

---

### `reach_refresh_outcome_windows` (100181)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| publication_link_id | BIGINT NOT NULL FK reach_refresh_publication_links | |
| content_identity_id | BIGINT NOT NULL FK reach_content_identities | |
| baseline_from | DATE NOT NULL | |
| baseline_to | DATE NOT NULL | |
| post_from | DATE NOT NULL | |
| post_to | DATE NOT NULL | |
| measurement_status | VARCHAR(20) NOT NULL DEFAULT 'pending' | pending, partial, complete, insufficient_data |
| created_at | TIMESTAMPTZ NOT NULL | |

Unique: `(publication_link_id, baseline_from, post_from)`

---

### `reach_refresh_outcome_metrics` (100182)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| outcome_window_id | BIGINT NOT NULL FK reach_refresh_outcome_windows | |
| metric_domain | VARCHAR(30) NOT NULL | search, engagement, conversion, visibility, indexing |
| metric_name | VARCHAR(80) NOT NULL | |
| baseline_value | NUMERIC(14,4) | |
| post_value | NUMERIC(14,4) | |
| observed_change_pct | NUMERIC(8,4) | never labelled "caused by" |
| evidence_source | VARCHAR(50) | |
| confidence | VARCHAR(20) | low, medium, high, insufficient_data |
| data_points_baseline | INT | |
| data_points_post | INT | |
| measured_at | TIMESTAMPTZ NOT NULL | |

---

### `reach_attribution_models` (100183)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| tenant_id | BIGINT NOT NULL FK reach_actors | |
| model_name | VARCHAR(50) NOT NULL | equal_weight, position_based, time_decay |
| description | TEXT | |
| formula | TEXT NOT NULL | human-readable formula |
| lookback_window_days | INT NOT NULL | |
| limitations | TEXT NOT NULL | mandatory disclosure |
| is_active | BOOLEAN NOT NULL DEFAULT FALSE | |
| created_at | TIMESTAMPTZ NOT NULL | |

Unique: `(tenant_id, model_name)`

---

### `reach_attribution_model_versions` (100184)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| model_id | BIGINT NOT NULL FK reach_attribution_models | |
| version_number | INT NOT NULL | |
| formula | TEXT NOT NULL | |
| weight_rules | JSONB NOT NULL | |
| approved_by | BIGINT FK reach_actors | |
| approved_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ NOT NULL | |

Unique: `(model_id, version_number)`

---

### `reach_attribution_journey_calculations` (100185)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| tenant_id | BIGINT NOT NULL FK reach_actors | |
| conversion_link_id | BIGINT NOT NULL FK reach_attribution_conversion_links | |
| model_version_id | BIGINT NOT NULL FK reach_attribution_model_versions | |
| ordered_touchpoint_ids | JSONB NOT NULL | ordered array of touchpoint IDs |
| total_touchpoints | INT NOT NULL | |
| identity_confidence | VARCHAR(20) NOT NULL | high, medium, low, pseudonymous |
| completeness_score | NUMERIC(4,3) | |
| limitations_note | TEXT NOT NULL | |
| calculated_at | TIMESTAMPTZ NOT NULL | |

---

### `reach_attribution_allocation_facts` (100186)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| journey_calculation_id | BIGINT NOT NULL FK reach_attribution_journey_calculations | |
| touchpoint_id | BIGINT NOT NULL FK reach_attribution_touchpoints | |
| touch_position | INT NOT NULL | position in journey (1-indexed) |
| allocation_weight | NUMERIC(6,4) NOT NULL | fraction 0.0–1.0 |
| model_name | VARCHAR(50) NOT NULL | |
| model_version | INT NOT NULL | |
| created_at | TIMESTAMPTZ NOT NULL | |

No UPDATE — immutable per journey.

---

### `reach_readiness_audit_runs` (100187)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| tenant_id | BIGINT NOT NULL FK reach_actors | |
| audit_type | VARCHAR(50) NOT NULL | security, privacy, ai_governance, migration, performance, operational |
| status | VARCHAR(20) NOT NULL DEFAULT 'running' | |
| started_at | TIMESTAMPTZ NOT NULL | |
| completed_at | TIMESTAMPTZ | |
| triggered_by | BIGINT FK reach_actors | |

---

### `reach_readiness_findings` (100188)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| audit_run_id | BIGINT NOT NULL FK reach_readiness_audit_runs | |
| severity | VARCHAR(20) NOT NULL | critical, high, medium, low, info |
| category | VARCHAR(50) NOT NULL | |
| title | VARCHAR(200) NOT NULL | |
| description | TEXT NOT NULL | |
| affected_component | VARCHAR(100) | |
| resolution_status | VARCHAR(20) NOT NULL DEFAULT 'open' | open, in_progress, resolved, accepted_risk, deferred |
| accepted_risk_reason | TEXT | required when resolution_status = accepted_risk |
| accepted_by | BIGINT FK reach_actors | |
| accepted_at | TIMESTAMPTZ | |
| resolved_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ NOT NULL | |

---

### `reach_technical_debt_records` (100189)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| classification | VARCHAR(30) NOT NULL | critical_blocker, high_blocker, release_limitation, accepted_medium, accepted_low, deferred, superseded, out_of_scope |
| title | VARCHAR(200) NOT NULL | |
| description | TEXT NOT NULL | |
| impact | TEXT NOT NULL | |
| workaround | TEXT | |
| owner | BIGINT FK reach_actors | |
| target_date | DATE | |
| acceptance_reason | TEXT | |
| accepted_by | BIGINT FK reach_actors | |
| accepted_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ NOT NULL | |
| updated_at | TIMESTAMPTZ NOT NULL | |

---

### `reach_operational_readiness_checks` (100190)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| check_category | VARCHAR(50) NOT NULL | deployment, monitoring, backup, rollback, provider |
| check_name | VARCHAR(200) NOT NULL | |
| status | VARCHAR(20) NOT NULL DEFAULT 'pending' | pending, passed, failed, skipped, not_applicable |
| evidence | TEXT | |
| checked_at | TIMESTAMPTZ | |
| checked_by | BIGINT FK reach_actors | |

---

### `reach_disaster_recovery_tests` (100191)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| test_type | VARCHAR(50) NOT NULL | backup_verify, restore_verify, rollback_verify, migration_verify |
| environment | VARCHAR(30) NOT NULL | local, staging, production (staging only for P9) |
| status | VARCHAR(20) NOT NULL DEFAULT 'pending' | |
| rpo_minutes | INT | |
| rto_minutes | INT | |
| procedure_followed | TEXT | |
| evidence_notes | TEXT | |
| tested_by | BIGINT FK reach_actors | |
| tested_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ NOT NULL | |

---

### `reach_release_acceptance_records` (100192)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| release_name | VARCHAR(100) NOT NULL | e.g. "Phase 9 production release" |
| recommendation | VARCHAR(50) NOT NULL | ready_controlled, ready_with_limitations, not_ready |
| evidence_summary | TEXT NOT NULL | |
| blockers_resolved | BOOLEAN NOT NULL | |
| limitations_accepted | JSONB NOT NULL DEFAULT '[]' | |
| accepted_risks | JSONB NOT NULL DEFAULT '[]' | |
| prerequisite_checks | JSONB NOT NULL | |
| accepted_by | BIGINT NOT NULL FK reach_actors | |
| accepted_at | TIMESTAMPTZ NOT NULL | |
| created_at | TIMESTAMPTZ NOT NULL | |

Only one active record allowed (enforced in service).

---

### `AddRefreshPermissions` (100193)

Adds `refresh.*`, `readiness.*`, `operations.*` permission slugs to `reach_user_permissions` check constraint and app config.
