<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Optional per-user permission overrides layered on top of role permissions.
 *
 *   mode = 'grant' → adds the permission for this user (union with role)
 *   mode = 'deny'  → removes the permission for this user (subtract from role)
 *
 * A (user_id, permission) pair may only appear once; unique constraint enforces this.
 */
class CreateReachUserPermissions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'user_id'    => ['type' => 'BIGINT',      'null' => false],
            'permission' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'mode'       => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => false, 'default' => 'grant'],
            'reason'     => ['type' => 'TEXT', 'null' => true],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
            'updated_at' => ['type' => 'TIMESTAMPTZ', 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('user_id', 'reach_users', 'id', '', 'CASCADE');
        $this->forge->addUniqueKey(['user_id', 'permission']);
        $this->forge->createTable('reach_user_permissions', true);

        $this->db->query("ALTER TABLE reach_user_permissions ADD CONSTRAINT reach_user_permissions_mode_chk CHECK (mode IN ('grant', 'deny'))");
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_user_permissions', true);
    }
}
