<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignTemplates extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGSERIAL'],
            'uuid'                  => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'tenant_id'             => ['type' => 'BIGINT', 'null' => false],
            'channel'               => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'name'                  => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'provider_template_id'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'language'              => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'en'],
            'approval_status'       => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'draft'],
            'is_active'             => ['type' => 'BOOLEAN', 'default' => true],
            'created_by'            => ['type' => 'BIGINT', 'null' => true],
            'created_at'            => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'            => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('reach_campaign_templates', true);

        $this->db->query(
            "ALTER TABLE reach_campaign_templates ADD CONSTRAINT chk_templates_channel "
            . "CHECK (channel IN ('email','whatsapp','sms','social'))"
        );
        $this->db->query(
            "ALTER TABLE reach_campaign_templates ADD CONSTRAINT chk_templates_approval_status "
            . "CHECK (approval_status IN ('draft','pending','approved','rejected','paused'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_templates', true);
    }
}
