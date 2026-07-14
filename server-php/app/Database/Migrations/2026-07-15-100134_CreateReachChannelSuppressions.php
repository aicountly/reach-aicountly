<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachChannelSuppressions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGSERIAL'],
            'uuid'            => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'tenant_id'       => ['type' => 'BIGINT', 'null' => false],
            'channel'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'address_hash'    => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'address_masked'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'reason'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'source'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'suppressed_at'   => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'suppressed_by'   => ['type' => 'BIGINT', 'null' => true],
            'expires_at'      => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'      => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey(['tenant_id', 'channel', 'address_hash'], 'uq_suppressions_address');
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('reach_channel_suppressions', true);

        $this->db->query(
            "ALTER TABLE reach_channel_suppressions ADD CONSTRAINT chk_suppressions_reason "
            . "CHECK (reason IN ('unsubscribe','bounce','complaint','manual','legal','opt_out','invalid_address'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_channel_suppressions', true);
    }
}
