<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Phase 2 — Unified content master table.
 *
 * All marketing content types share this record. Type-specific data lives in
 * extension tables (migration 100054). Status lifecycle and approval metadata
 * are stored here; approval decisions in reach_approvals (subject_type = 'content_item').
 */
class CreateReachContentItems extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                       => ['type' => 'BIGSERIAL'],
            'uuid'                     => ['type' => 'UUID', 'null' => false, 'default' => new RawSql('gen_random_uuid()')],
            'content_type'             => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'title'                    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => false],
            'slug'                     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'summary'                  => ['type' => 'TEXT', 'null' => true],
            'objective'                => ['type' => 'TEXT', 'null' => true],
            'language'                 => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => false, 'default' => 'en'],
            // Phase 1 knowledge FK links (nullable — not all content is product-specific)
            'market_id'                => ['type' => 'BIGINT', 'null' => true],
            'primary_product_id'       => ['type' => 'BIGINT', 'null' => true],
            'primary_module_id'        => ['type' => 'BIGINT', 'null' => true],
            'primary_feature_id'       => ['type' => 'BIGINT', 'null' => true],
            'primary_persona_id'       => ['type' => 'BIGINT', 'null' => true],
            'primary_industry_id'      => ['type' => 'BIGINT', 'null' => true],
            'primary_search_intent_id' => ['type' => 'BIGINT', 'null' => true],
            'primary_topic_cluster_id' => ['type' => 'BIGINT', 'null' => true],
            'funnel_stage'             => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'priority'                 => ['type' => 'SMALLINT', 'null' => false, 'default' => 3],
            'risk_level'               => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'low'],
            'creation_source'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'manual'],
            // Version pointer (updated atomically by ContentVersionService)
            'current_version_id'       => ['type' => 'BIGINT', 'null' => true],
            // Workflow
            'workflow_status'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'idea'],
            'approval_status'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'not_required'],
            'validation_status'        => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'not_run'],
            'publication_status'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'none'],
            'scheduled_at'             => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_at'              => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_by'              => ['type' => 'BIGINT', 'null' => true],
            'review_due_at'            => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'refresh_due_at'           => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'published_at'             => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'archived_at'              => ['type' => 'TIMESTAMPTZ', 'null' => true],
            // Actor metadata
            'created_actor_type'       => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'created_by_user_id'       => ['type' => 'BIGINT', 'null' => true],
            'created_by_service'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'updated_by_user_id'       => ['type' => 'BIGINT', 'null' => true],
            'generation_job_id'        => ['type' => 'BIGINT', 'null' => true],
            'request_id'               => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'internal_notes'           => ['type' => 'JSONB', 'null' => true],
            // Timestamps
            'deleted_at'               => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'               => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'               => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('content_type');
        $this->forge->addKey('workflow_status');
        $this->forge->addKey('approval_status');
        $this->forge->addKey('risk_level');
        $this->forge->addKey('primary_product_id');
        $this->forge->addKey('primary_persona_id');
        $this->forge->addKey('review_due_at');
        $this->forge->addKey('scheduled_at');
        $this->forge->addKey('deleted_at');
        $this->forge->createTable('reach_content_items', true);

        // CHECK constraints
        $this->db->query("ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_content_type_chk");
        $this->db->query(
            "ALTER TABLE reach_content_items ADD CONSTRAINT rci_content_type_chk "
            . "CHECK (content_type IN ("
            . "'blog','knowledge_base','community_question','community_answer',"
            . "'video_topic','video_script','social_post','email','whatsapp','sms',"
            . "'landing_page','product_announcement','release_announcement',"
            . "'webinar','case_study','content_refresh'"
            . "))"
        );
        $this->db->query("ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_workflow_status_chk");
        $this->db->query(
            "ALTER TABLE reach_content_items ADD CONSTRAINT rci_workflow_status_chk "
            . "CHECK (workflow_status IN ("
            . "'idea','brief','draft','validation_pending','review_pending',"
            . "'changes_requested','approved','scheduled','ready_for_publication',"
            . "'published','refresh_due','archived','rejected'"
            . "))"
        );
        $this->db->query("ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_approval_status_chk");
        $this->db->query(
            "ALTER TABLE reach_content_items ADD CONSTRAINT rci_approval_status_chk "
            . "CHECK (approval_status IN ('not_required','pending','approved','rejected','overridden'))"
        );
        $this->db->query("ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_validation_status_chk");
        $this->db->query(
            "ALTER TABLE reach_content_items ADD CONSTRAINT rci_validation_status_chk "
            . "CHECK (validation_status IN ('not_run','pending','passed','warning','failed','waived'))"
        );
        $this->db->query("ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_publication_status_chk");
        $this->db->query(
            "ALTER TABLE reach_content_items ADD CONSTRAINT rci_publication_status_chk "
            . "CHECK (publication_status IN ('none','publication_pending','publication_blocked','ready_for_publication'))"
        );
        $this->db->query("ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_risk_level_chk");
        $this->db->query(
            "ALTER TABLE reach_content_items ADD CONSTRAINT rci_risk_level_chk "
            . "CHECK (risk_level IN ('low','medium','high','critical'))"
        );
        $this->db->query("ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_funnel_stage_chk");
        $this->db->query(
            "ALTER TABLE reach_content_items ADD CONSTRAINT rci_funnel_stage_chk "
            . "CHECK (funnel_stage IS NULL OR funnel_stage IN ('top','middle','bottom'))"
        );
        $this->db->query("ALTER TABLE reach_content_items DROP CONSTRAINT IF EXISTS rci_actor_type_chk");
        $this->db->query(
            "ALTER TABLE reach_content_items ADD CONSTRAINT rci_actor_type_chk "
            . "CHECK (created_actor_type IS NULL OR created_actor_type IN ('human','system','bot','service'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_content_items', true);
    }
}
