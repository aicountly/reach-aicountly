# Reach — Unified Content Model

**Phase 2 — Unified Content Studio**

## Overview

All marketing content types in Reach share one master record (`reach_content_items`) and one common lifecycle. Type-specific data lives in eight extension tables. Every edit produces an immutable version snapshot. All approval decisions are recorded in the existing `reach_approvals` table.

---

## Master table: `reach_content_items`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGSERIAL | Internal PK |
| `uuid` | UUID | External identifier |
| `slug` | VARCHAR(255) UNIQUE | Human-readable URL segment |
| `content_type` | VARCHAR(48) CHECK | See §Content Types below |
| `title` | VARCHAR(512) | Required |
| `workflow_status` | VARCHAR(48) CHECK | See §Workflow States |
| `approval_status` | VARCHAR(32) CHECK | `pending`, `approved`, `rejected`, `changes_requested`, `not_required` |
| `validation_status` | VARCHAR(32) CHECK | `not_run`, `passed`, `failed`, `partial`, `waived`, `blocking` |
| `publication_status` | VARCHAR(32) CHECK | `unpublished`, `scheduled`, `ready`, `published`, `retracted` |
| `risk_level` | VARCHAR(16) CHECK | `low`, `medium`, `high`, `critical` |
| `product_id` | BIGINT FK | Phase 1 product anchor |
| `persona_id` | BIGINT FK | Phase 1 primary persona |
| `market_id` | BIGINT FK | Phase 1 market |
| `topic_cluster_id` | BIGINT FK | Phase 1 topic cluster |
| `review_due_at` | TIMESTAMPTZ | When review is due |
| `refresh_due_at` | TIMESTAMPTZ | When content needs refreshing |
| `language` | VARCHAR(8) | ISO 639-1, default `en` |
| `created_by` | BIGINT FK | Actor |
| `deleted_at` | TIMESTAMPTZ | Soft delete |

---

## Content Types (16)

`blog`, `knowledge_base`, `community_question`, `community_answer`, `video_topic`, `video_script`, `social_post`, `email`, `whatsapp`, `sms`, `landing_page`, `product_announcement`, `release_announcement`, `webinar`, `case_study`, `content_refresh`

---

## Extension Tables (8)

Each extension table has `content_item_id BIGINT UNIQUE NOT NULL` as its primary FK.

| Table | Type(s) | Key columns |
|---|---|---|
| `reach_content_blog_details` | blog | `seo_title`, `meta_description`, `featured_image_url`, `canonical_url`, `estimated_read_minutes`, `tags` (JSONB) |
| `reach_content_knowledge_base_details` | knowledge_base | `article_type`, `difficulty_level`, `related_article_ids` (JSONB) |
| `reach_content_community_details` | community_question/answer | `platform`, `question_id` FK, `accepted_answer` |
| `reach_content_video_details` | video_topic/script | `video_format`, `duration_minutes`, `platform`, `thumbnail_url`, `chapters` (JSONB) |
| `reach_content_social_details` | social_post | `platform`, `character_limit`, `media_urls` (JSONB), `hashtags` (JSONB), `scheduled_networks` (JSONB) |
| `reach_content_email_details` | email | `subject_line`, `preview_text`, `from_name`, `reply_to`, `audience_segment` |
| `reach_content_message_details` | whatsapp/sms | `channel`, `template_name`, `max_characters` |
| `reach_content_landing_details` | landing_page | `page_url`, `conversion_goal`, `cta_text`, `ab_variant` |

---

## Knowledge Maps (14 junction tables)

`reach_content_items` → Phase 1 entity via `reach_content_*_map` tables:

`products`, `modules`, `features`, `personas`, `industries`, `markets`, `business_problems`, `search_intents`, `topic_clusters`, `product_claims`, `evidence`, `sources`, `citations`, `brand_rules`

Each map table has `(content_item_id, <entity>_id)` unique constraint.

---

## Version Model

`reach_content_versions` is append-only. Every save-point creates a new row with `is_current = true` while the previous row is set to `false` atomically. Versions are never deleted.

See [REACH_CONTENT_VERSIONING.md](REACH_CONTENT_VERSIONING.md).

---

## Blog bridge

`reach_blog_posts.content_item_id` is nullable. Legacy blog posts without a content item continue to work unchanged. New blog posts created through the Content Studio have both records linked.
