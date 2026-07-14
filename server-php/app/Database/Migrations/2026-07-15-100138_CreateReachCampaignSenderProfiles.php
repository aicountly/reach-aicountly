<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachCampaignSenderProfiles extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGSERIAL'],
            'uuid'          => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'tenant_id'     => ['type' => 'BIGINT', 'null' => false],
            'channel'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'from_address'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'display_name'  => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'reply_to'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'verified'      => ['type' => 'BOOLEAN', 'default' => false],
            'provider'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'connection_id' => ['type' => 'BIGINT', 'null' => true],
            'dlt_header'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'is_active'     => ['type' => 'BOOLEAN', 'default' => true],
            'created_by'    => ['type' => 'BIGINT', 'null' => true],
            'created_at'    => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at'    => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('reach_campaign_sender_profiles', true);

        $this->db->query(
            "ALTER TABLE reach_campaign_sender_profiles ADD CONSTRAINT chk_sender_profiles_channel "
            . "CHECK (channel IN ('email','sms','whatsapp'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_campaign_sender_profiles', true);
    }
}
