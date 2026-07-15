<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachChannelConsents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'BIGSERIAL'],
            'uuid'             => ['type' => 'UUID', 'default' => new RawSql('gen_random_uuid()')],
            'tenant_id'        => ['type' => 'BIGINT', 'null' => false],
            'subject_type'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'subject_id'       => ['type' => 'BIGINT', 'null' => false],
            'channel'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'purpose'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'source'           => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'proof_reference'  => ['type' => 'TEXT', 'null' => true],
            'captured_at'      => ['type' => 'TIMESTAMPTZ', 'null' => false],
            'captured_by'      => ['type' => 'BIGINT', 'null' => true],
            'revoked_at'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'expires_at'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'created_at'       => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey(['tenant_id', 'subject_type', 'subject_id', 'channel', 'purpose'], false, false, 'idx_consents_lookup');
        $this->forge->createTable('reach_channel_consents', true);

        $this->db->query(
            "ALTER TABLE reach_channel_consents ADD CONSTRAINT chk_consents_status "
            . "CHECK (status IN ('granted','revoked','expired'))"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_channel_consents', true);
    }
}
