<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPhase7FieldsToWhatsappCampaigns extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_whatsapp_campaigns ADD COLUMN IF NOT EXISTS uuid UUID DEFAULT gen_random_uuid() UNIQUE');
        $this->db->query('ALTER TABLE reach_whatsapp_campaigns ADD COLUMN IF NOT EXISTS tenant_id BIGINT');
        $this->db->query('ALTER TABLE reach_whatsapp_campaigns ADD COLUMN IF NOT EXISTS connection_id BIGINT');
        $this->db->query('ALTER TABLE reach_whatsapp_campaigns ADD COLUMN IF NOT EXISTS template_version_id BIGINT');
        $this->db->query('ALTER TABLE reach_whatsapp_campaigns ADD COLUMN IF NOT EXISTS dispatch_id BIGINT');
        $this->db->query('ALTER TABLE reach_whatsapp_campaigns ADD COLUMN IF NOT EXISTS sender_profile_id BIGINT');

        $this->db->query("UPDATE reach_whatsapp_campaigns SET uuid = gen_random_uuid() WHERE uuid IS NULL");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_whatsapp_campaigns_uuid ON reach_whatsapp_campaigns(uuid)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_whatsapp_campaigns_tenant ON reach_whatsapp_campaigns(tenant_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_whatsapp_campaigns_tenant');
        $this->db->query('DROP INDEX IF EXISTS idx_whatsapp_campaigns_uuid');
        foreach (['sender_profile_id','dispatch_id','template_version_id','connection_id','tenant_id','uuid'] as $col) {
            $this->db->query("ALTER TABLE reach_whatsapp_campaigns DROP COLUMN IF EXISTS {$col}");
        }
    }
}
