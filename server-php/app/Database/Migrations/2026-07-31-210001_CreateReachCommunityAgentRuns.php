<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — Role-routed operational agent runtime (Decision 2A).
 *
 * Single, unified run log for every action any official identity's
 * operational role performs. Deliberately one table for all five roles
 * (matching the "role-routed runtime, not five duplicate modules" decision)
 * rather than per-role tables — it is also the source of truth
 * CommunityOperationalAgentService queries to enforce daily caps, cooldowns,
 * and self-reply detection, and it satisfies the audit requirement to record
 * on every run: identity, role, style profile, prompt version, provider,
 * model, run ID, and timestamp.
 */
class CreateReachCommunityAgentRuns extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_agent_runs (
                id                   BIGSERIAL PRIMARY KEY,
                run_uuid             UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                identity_id          BIGINT NOT NULL REFERENCES reach_community_official_identities(id) ON DELETE CASCADE,
                operational_role     VARCHAR(40) NOT NULL,
                action               VARCHAR(60) NOT NULL,
                target_type          VARCHAR(30),
                target_id            BIGINT,
                target_external_ref  VARCHAR(160),
                style_profile_id     BIGINT,
                prompt_version       VARCHAR(60),
                provider             VARCHAR(60),
                model_route          VARCHAR(120),
                outcome              VARCHAR(20) NOT NULL DEFAULT 'success'
                                        CHECK (outcome IN ('success', 'blocked', 'failed')),
                block_reason         VARCHAR(120),
                metadata             JSONB NOT NULL DEFAULT '{}'::jsonb,
                actor_id             BIGINT REFERENCES reach_users(id) ON DELETE SET NULL,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcar_identity_action_created ON reach_community_agent_runs(identity_id, action, created_at)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcar_target ON reach_community_agent_runs(target_type, target_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcar_external_ref ON reach_community_agent_runs(target_external_ref)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcar_created ON reach_community_agent_runs(created_at)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_agent_runs CASCADE');
    }
}
