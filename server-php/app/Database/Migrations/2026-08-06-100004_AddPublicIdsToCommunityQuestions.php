<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Record where a question landed on the public site.
 *
 * Answers and identities already carry public_external_id; questions never did,
 * because nothing in Reach had ever created a question on aicountly.com — the
 * publish path skipped straight to publishing an answer that did not exist
 * there. Now that provisioning is wired, the returned public id and canonical
 * URL need somewhere to live so reconciliation and the UI can link out.
 */
class AddPublicIdsToCommunityQuestions extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE reach_community_questions
                ADD COLUMN IF NOT EXISTS public_external_id VARCHAR(255),
                ADD COLUMN IF NOT EXISTS public_url         TEXT
        ');

        $this->db->query(
            'CREATE INDEX IF NOT EXISTS idx_rcq_public_ext
             ON reach_community_questions(public_external_id)
             WHERE public_external_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_rcq_public_ext');
        $this->db->query('
            ALTER TABLE reach_community_questions
                DROP COLUMN IF EXISTS public_external_id,
                DROP COLUMN IF EXISTS public_url
        ');
    }
}
