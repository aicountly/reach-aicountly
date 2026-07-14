<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignChannelVariants extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                      => ['type' => 'BIGSERIAL'],
            'uuid'                    => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'campaign_version_id'     => ['type' => 'BIGINT', 'null' => false],
            'channel'                 => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'source_content_id'       => ['type' => 'BIGINT', 'null' => true],
            'template_version_id'     => ['type' => 'BIGINT', 'null' => true],
            'content_json'            => ['type' => 'JSONB', 'null' => false, 'default' => new RawSql("'{}'::jsonb")],
            'merge_field_values'      => ['type' => 'JSONB', 'null' => true],
            'validation_status'       => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'pending'],
            'validation_findings'     => ['type' => 'JSONB', 'null' => true],
            'generation_artifact_id'  => ['type' => 'BIGINT', 'null' => true],
            'created_by'              => ['type' => 'BIGINT', 'null' => true],
            'created_at'              => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            // No updated_at — immutable after creation
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey('campaign_version_id');
        $this->forge->addForeignKey('campaign_version_id', 'reach_campaign_versions', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_campaign_channel_variants', true);

        $this->db->query(
            "ALTER TABLE reach_campaign_channel_variants ADD CONSTRAINT chk_variants_channel "
            . "CHECK (channel IN ('social','email','whatsapp','sms'))"
        );
        $this->db->query(
            "ALTER TABLE reach_campaign_channel_variants ADD CONSTRAINT chk_variants_validation_status "
            . "CHECK (validation_status IN ('pending','valid','invalid'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_channel_variants', true);
    }
}
