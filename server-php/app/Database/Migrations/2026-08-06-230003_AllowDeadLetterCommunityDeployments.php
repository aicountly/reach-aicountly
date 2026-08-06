<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Additive corrective migration.
 *
 * OfficialAnswerPublishingService retires a deployment to 'dead_letter' when
 * its attempts are exhausted, its approval is invalidated mid-retry, or its
 * risk tier was raised since the original attempt — but the status CHECK
 * shipped in migration 100098 never listed that value, so every one of those
 * writes raised a constraint violation instead of parking the deployment.
 * The retry sweep would then re-pick the same deployment forever.
 */
class AllowDeadLetterCommunityDeployments extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE reach_community_deployments
                DROP CONSTRAINT IF EXISTS reach_community_deployments_status_check
        ');
        $this->db->query('
            ALTER TABLE reach_community_deployments
                DROP CONSTRAINT IF EXISTS chk_rcd_status
        ');
        $this->db->query("
            ALTER TABLE reach_community_deployments
                ADD CONSTRAINT chk_rcd_status CHECK (status IN (
                    'pending','executing','succeeded','failed',
                    'retrying','dead_letter','cancelled'
                ))
        ");
    }

    public function down(): void
    {
        $this->db->query('
            ALTER TABLE reach_community_deployments
                DROP CONSTRAINT IF EXISTS chk_rcd_status
        ');
        $this->db->query("
            ALTER TABLE reach_community_deployments
                ADD CONSTRAINT chk_rcd_status CHECK (status IN (
                    'pending','executing','succeeded','failed','retrying','cancelled'
                ))
        ");
    }
}
