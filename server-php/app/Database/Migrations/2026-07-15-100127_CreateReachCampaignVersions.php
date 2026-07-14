<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignVersions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'BIGSERIAL'],
            'uuid'                 => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'campaign_id'          => ['type' => 'BIGINT', 'null' => false],
            'version_number'       => ['type' => 'INT', 'null' => false],
            'content_hash'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'audience_snapshot_id' => ['type' => 'BIGINT', 'null' => true],
            'submitted_by'         => ['type' => 'BIGINT', 'null' => true],
            'submitted_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'approved_by'          => ['type' => 'BIGINT', 'null' => true],
            'approved_at'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'rejected_by'          => ['type' => 'BIGINT', 'null' => true],
            'rejected_at'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'rejection_reason'     => ['type' => 'TEXT', 'null' => true],
            'created_by'           => ['type' => 'BIGINT', 'null' => true],
            'created_at'           => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            // No updated_at — immutable after creation
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey(['campaign_id', 'version_number'], 'uq_campaign_versions_campaign_version');
        $this->forge->addKey('campaign_id');
        $this->forge->addForeignKey('campaign_id', 'reach_campaigns', 'id', '', 'CASCADE');
        $this->forge->createTable('reach_campaign_versions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_versions', true);
    }
}
