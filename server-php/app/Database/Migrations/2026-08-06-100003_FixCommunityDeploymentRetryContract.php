<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Align reach_community_deployments with what the publishing code actually writes.
 *
 * Two mismatches, both on the failure path only — which is why they survived
 * until now: nothing had ever published successfully *or* failed in production.
 *
 * 1. OfficialAnswerPublishingService writes status 'dead_letter' when a
 *    deployment exhausts its attempts or its approval is invalidated, but the
 *    original CHECK constraint permitted only pending/executing/succeeded/
 *    failed/retrying/cancelled. On Postgres that write raises SQLSTATE 23514,
 *    so the failure handler itself failed and the row was left mid-flight.
 *    'dead_letter' matches the vocabulary reach_jobs already uses for the same
 *    concept.
 *
 * 2. max_attempts defaulted to 3 while the service reads
 *    `$deployment['max_attempts'] ?? 5` — the column is NOT NULL so the
 *    coalesce never fired and retries stopped two attempts early. New rows now
 *    default to 5. Existing rows keep the value they were created under:
 *    changing an in-flight deployment's budget mid-retry would be a surprise,
 *    and the sweep handles either value correctly.
 */
class FixCommunityDeploymentRetryContract extends Migration
{
    public function up(): void
    {
        // Inline CHECKs get Postgres' auto-generated name; drop that and any
        // previously named variant before installing the replacement.
        $this->db->query('ALTER TABLE reach_community_deployments DROP CONSTRAINT IF EXISTS reach_community_deployments_status_check');
        $this->db->query('ALTER TABLE reach_community_deployments DROP CONSTRAINT IF EXISTS chk_rcd_status');
        $this->db->query("
            ALTER TABLE reach_community_deployments
                ADD CONSTRAINT chk_rcd_status CHECK (status IN (
                    'pending','executing','succeeded','failed','retrying','cancelled','dead_letter'
                ))
        ");

        $this->db->query('ALTER TABLE reach_community_deployments ALTER COLUMN max_attempts SET DEFAULT 5');
    }

    public function down(): void
    {
        // Rows already parked in dead_letter would violate the narrower
        // constraint, so retire them to 'failed' before reinstating it.
        $this->db->query("UPDATE reach_community_deployments SET status = 'failed' WHERE status = 'dead_letter'");

        $this->db->query('ALTER TABLE reach_community_deployments DROP CONSTRAINT IF EXISTS chk_rcd_status');
        $this->db->query("
            ALTER TABLE reach_community_deployments
                ADD CONSTRAINT reach_community_deployments_status_check CHECK (status IN (
                    'pending','executing','succeeded','failed','retrying','cancelled'
                ))
        ");

        $this->db->query('ALTER TABLE reach_community_deployments ALTER COLUMN max_attempts SET DEFAULT 3');
    }
}
