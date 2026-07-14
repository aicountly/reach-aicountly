<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignTemplateVersions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGSERIAL'],
            'uuid'                => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'template_id'         => ['type' => 'BIGINT', 'null' => false],
            'version_number'      => ['type' => 'INT', 'null' => false],
            'content_json'        => ['type' => 'JSONB', 'null' => false],
            'merge_field_schema'  => ['type' => 'JSONB', 'null' => true],
            'character_count'     => ['type' => 'INT', 'null' => true],
            'segment_count'       => ['type' => 'INT', 'default' => 1],
            'approved_by'         => ['type' => 'BIGINT', 'null' => true],
            'approved_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_by'          => ['type' => 'BIGINT', 'null' => true],
            'created_at'          => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            // No updated_at — immutable after creation
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey(['template_id', 'version_number'], 'uq_template_versions');
        $this->forge->addKey('template_id');
        $this->forge->addForeignKey('template_id', 'reach_campaign_templates', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_campaign_template_versions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_template_versions', true);
    }
}
