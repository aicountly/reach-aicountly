<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Blog automation — strict provider alternation state.
 *
 * One row per rotation scope records which provider actually produced the
 * last accepted output, so successive scheduled generations alternate
 * OpenAI ⇄ Gemini regardless of catalog preference or mid-run failover.
 */
class CreateReachAiProviderRotation extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'auto_increment' => true],
            'scope' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'last_provider_key' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'last_generation_request_id' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at' => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('scope');
        $this->forge->createTable('reach_ai_provider_rotation');

        $this->db->query(
            "INSERT INTO reach_ai_provider_rotation (scope, created_at, updated_at) VALUES ('blog_draft', NOW(), NOW()) ON CONFLICT (scope) DO NOTHING"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_ai_provider_rotation', true);
    }
}
