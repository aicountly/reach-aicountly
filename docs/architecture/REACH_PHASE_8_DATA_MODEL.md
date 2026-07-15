# Phase 8 Data Model

**Phase:** 8
**Baseline:** Migration 100143 (Phase 7 end)
**Phase 8 migrations:** 100144–100171

---

## Canonical Content Identity Domain

### `reach_content_identities` (100144)
Central pivot for all intelligence. One row per tenant/content_type/source_id combination.

Key columns: `uuid`, `tenant_id`, `content_type` (blog|kb|community_question|community_answer|video|campaign_variant|page), `source_id` (FK to type-specific table), `canonical_url`, `publication_status`, `first_published_at`, `last_published_at`, `analytics_eligible`, `privacy_class`

Unique constraint: `(tenant_id, content_type, source_id)`
URL conflict: `canonical_url` unique per tenant with conflict detection

### `reach_content_publication_mappings` (100145)
Maps canonical identity to remote platform representations.

Key columns: `content_identity_id`, `platform` (gsc|ga4|youtube|linkedin|twitter|email), `remote_identifier`, `remote_url`, `verified_at`

Unique: `(content_identity_id, platform, remote_identifier)`

---

## Sitemap Domain

### `reach_sitemap_snapshots` (100146)
Point-in-time snapshot of sitemap state.

Key columns: `uuid`, `tenant_id`, `generated_at`, `total_entries`, `excluded_noindex`, `excluded_withdrawn`, `status` (pending|generated|validated|failed)

### `reach_sitemap_entries` (100147)
Per-URL entries in a snapshot.

Key columns: `snapshot_id`, `content_identity_id`, `url`, `last_modified_at`, `change_frequency`, `priority`, `included` (boolean), `exclusion_reason`

---

## IndexNow Domain

### `reach_indexnow_submissions` (100148)
Idempotent submission records.

Key columns: `uuid`, `tenant_id`, `content_identity_id`, `url`, `provider_endpoint`, `idempotency_key`, `status` (pending|submitted|failed|retrying)

### `reach_indexnow_attempts` (100149)
Individual HTTP attempt log.

Key columns: `submission_id`, `attempt_number`, `http_status`, `provider_response`, `attempted_at`, `succeeded`

---

## Analytics Connector Domain

### `reach_analytics_connections` (100150)
Connector configuration per tenant/provider.

Key columns: `uuid`, `tenant_id`, `provider` (gsc|ga4|bing), `site_property`, `credential_reference` (never raw), `enabled`, `health_status`, `last_health_check_at`

### `reach_analytics_ingestion_cursors` (100151)
Resume point per connector/property/stream. One row per stream; upserted on each run.

Key columns: `connection_id`, `stream_type` (search_metrics|content_metrics), `last_ingested_date`, `backfill_from_date`, `backfill_days_remaining`, `cursor_state` (JSONB), `updated_at`

Unique: `(connection_id, stream_type)`

### `reach_analytics_ingestion_runs` (100154)
Run lifecycle tracking.

Key columns: `uuid`, `connection_id`, `stream_type`, `status` (started|completed|failed|partial), `date_from`, `date_to`, `rows_ingested`, `rows_skipped`, `started_at`, `completed_at`, `error_message`

---

## Search Metric Facts Domain

### `reach_search_metric_facts` (100152)
Deduplicated daily GSC facts. Immutable; corrections create new versioned rows.

Key columns: `content_identity_id`, `connection_id`, `ingestion_run_id`, `metric_date`, `query`, `page_url`, `device`, `country`, `clicks`, `impressions`, `ctr`, `avg_position`, `provider_freshness_at`, `collected_at`

Dedup key: `(content_identity_id, connection_id, metric_date, query, page_url, device, country)` — UNIQUE

---

## Content Metric Facts Domain

### `reach_content_metric_facts` (100153)
Deduplicated daily GA4 per-content facts.

Key columns: `content_identity_id`, `connection_id`, `ingestion_run_id`, `metric_date`, `sessions`, `users`, `engaged_sessions`, `engagement_rate`, `avg_engagement_time_secs`, `entrances`, `source`, `medium`, `campaign_name`, `provider_freshness_at`, `collected_at`

Dedup key: `(content_identity_id, connection_id, metric_date, source, medium)` — UNIQUE

### `reach_content_mapping_findings` (100155)
Unmapped URLs / conflicts during ingestion.

Key columns: `connection_id`, `ingestion_run_id`, `unmapped_url`, `finding_type` (unmapped|conflict|duplicate_canonical), `resolution_status` (unresolved|resolved|suppressed), `created_at`

---

## Attribution Domain

### `reach_utm_templates` (100156)
Governed UTM template management.

Key columns: `uuid`, `tenant_id`, `name`, `utm_source`, `utm_medium`, `utm_campaign_template` (may contain `{campaign_id}`), `utm_content_template`, `utm_term_template`, `is_active`

### `reach_attribution_touchpoints` (100157)
Visit/click attribution evidence. Privacy-safe: no raw personal identifiers.

Key columns: `uuid`, `tenant_id`, `visitor_pseudonym_hash`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `content_identity_id`, `campaign_id`, `channel`, `touched_at`, `touchpoint_type` (click|visit|form_start), `source_event_ref`

### `reach_attribution_conversion_links` (100158)
Links touchpoints to leads/conversions.

Key columns: `uuid`, `tenant_id`, `lead_id`, `first_touchpoint_id`, `last_touchpoint_id`, `conversion_type`, `converted_at`, `matching_method`, `confidence_state` (confirmed|inferred|unattributed), `calculation_version_id`

### `reach_attribution_calculation_versions` (100159)
Versioned calculation runs for reproducibility.

Key columns: `uuid`, `tenant_id`, `version_number`, `calculated_at`, `method` (first_touch|last_touch), `total_conversions`, `attributed_count`, `unattributed_count`, `calculation_params` (JSONB)

---

## AI Visibility Domain

### `reach_ai_visibility_prompts` (100160)
Prompt definitions. Mutable until first version is approved.

Key columns: `uuid`, `tenant_id`, `name`, `topic`, `intent`, `persona`, `locale`, `product_id`, `purpose` (always `ai_visibility_monitoring`), `schedule_cron`, `status`

### `reach_ai_visibility_prompt_versions` (100161)
Immutable once created. Content hash prevents silent changes.

Key columns: `prompt_id`, `version_number`, `prompt_text`, `content_hash`, `approved_at`, `approved_by`, `is_active`

### `reach_ai_visibility_runs` (100162)
Execution run per prompt version.

Key columns: `uuid`, `tenant_id`, `prompt_version_id`, `run_type` (scheduled|manual_test), `ai_route`, `ai_model`, `ai_provider`, `status` (queued|running|completed|failed|cancelled), `execution_budget_cents`, `actual_cost_cents`, `queued_at`, `started_at`, `completed_at`

### `reach_ai_visibility_responses` (100163)
Immutable raw AI response. Never mutated after creation.

Key columns: `uuid`, `run_id`, `raw_response` (TEXT, bounded by retention policy), `response_timestamp`, `parser_version`, `parse_status`, `tokens_used`, `retention_expires_at`

### `reach_ai_visibility_observations` (100164)
Parsed mention/classification per response.

Key columns: `response_id`, `run_id`, `entity_mentioned` (AICOUNTLY|product|competitor), `mention_type` (brand|product|competitor), `mention_order`, `sentiment_classification`, `coverage_state` (mentioned|not_mentioned|uncertain), `confidence` (0.0–1.0), `evidence_excerpt` (safe substring), `parser_finding`

### `reach_ai_visibility_citations` (100165)
Extracted citations and linked domains.

Key columns: `response_id`, `observation_id`, `cited_url`, `cited_domain`, `citation_type` (source|link|reference), `linked_content_identity_id` (nullable FK)

---

## Competitor Domain

### `reach_competitors` (100166)
Competitor organisation definitions.

Key columns: `uuid`, `tenant_id`, `name`, `legal_name`, `website_domain`, `monitoring_enabled`, `monitoring_status`, `effective_from`, `effective_to`, `created_by`

### `reach_competitor_aliases` (100167)
Products, aliases, and domain variants.

Key columns: `competitor_id`, `alias_type` (product|brand|domain), `alias_value`, `is_canonical`, `added_by`

Unique: `(competitor_id, alias_type, alias_value)` with cross-competitor collision detection

### `reach_competitor_observation_aggregates` (100168)
Aggregated mention counts per prompt/period. Never claims market share.

Key columns: `competitor_id`, `prompt_id`, `period_start`, `period_end`, `total_runs`, `mention_count`, `citation_count`, `mention_rate` (mention_count/total_runs), `sample_scope_note` (required disclosure text)

---

## Operations Domain

### `reach_connector_health` (100169)
Health check records per connection.

Key columns: `connection_id`, `checked_at`, `status` (healthy|degraded|failing|unknown), `latency_ms`, `error_message`, `http_status`

### `reach_metric_freshness` (100170)
Per-source freshness tracking.

Key columns: `connection_id`, `stream_type`, `last_successful_at`, `last_failed_at`, `staleness_threshold_hours`, `is_stale` (computed), `freshness_state`
