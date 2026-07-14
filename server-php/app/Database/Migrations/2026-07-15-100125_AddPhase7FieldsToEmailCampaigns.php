<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPhase7FieldsToEmailCampaigns extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_email_campaigns ADD COLUMN IF NOT EXISTS uuid UUID DEFAULT gen_random_uuid() UNIQUE');
        $this->db->query('ALTER TABLE reach_email_campaigns ADD COLUMN IF NOT EXISTS tenant_id BIGINT');
        $this->db->query('ALTER TABLE reach_email_campaigns ADD COLUMN IF NOT EXISTS connection_id BIGINT');
        $this->db->query('ALTER TABLE reach_email_campaigns ADD COLUMN IF NOT EXISTS sender_profile_id BIGINT');
        $this->db->query('ALTER TABLE reach_email_campaigns ADD COLUMN IF NOT EXISTS template_version_id BIGINT');
        $this->db->query('ALTER TABLE reach_email_campaigns ADD COLUMN IF NOT EXISTS preview_text VARCHAR(255)');
        $this->db->query('ALTER TABLE reach_email_campaigns ADD COLUMN IF NOT EXISTS dispatch_id BIGINT');
        $this->db->query('ALTER TABLE reach_email_campaigns ADD COLUMN IF NOT EXISTS unsubscribe_count INT DEFAULT 0');
        $this->db->query('ALTER TABLE reach_email_campaigns ADD COLUMN IF NOT EXISTS bounce_count INT DEFAULT 0');
        $this->db->query('ALTER TABLE reach_email_campaigns ADD COLUMN IF NOT EXISTS complaint_count INT DEFAULT 0');

        $this->db->query("UPDATE reach_email_campaigns SET uuid = gen_random_uuid() WHERE uuid IS NULL");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_email_campaigns_uuid ON reach_email_campaigns(uuid)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_email_campaigns_tenant ON reach_email_campaigns(tenant_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_email_campaigns_tenant');
        $this->db->query('DROP INDEX IF EXISTS idx_email_campaigns_uuid');
        foreach (['complaint_count','bounce_count','unsubscribe_count','dispatch_id','preview_text','template_version_id','sender_profile_id','connection_id','tenant_id','uuid'] as $col) {
            $this->db->query("ALTER TABLE reach_email_campaigns DROP COLUMN IF EXISTS {$col}");
        }
    }
}
