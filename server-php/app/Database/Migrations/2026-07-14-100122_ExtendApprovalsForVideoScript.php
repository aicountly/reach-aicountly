<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 6 extension — reach_approvals.subject_type.
 *
 * Phase 2 created reach_approvals with subject_type CHECK limited to
 * Phase 0–5 content types.  Phase 6 script approval uses the same table
 * with subject_type = 'video_script'.
 *
 * This migration widens the CHECK constraint to include 'video_script'.
 */
class ExtendApprovalsForVideoScript extends Migration
{
    public function up(): void
    {
        $this->db->query("
            ALTER TABLE reach_approvals
                DROP CONSTRAINT IF EXISTS reach_approvals_subject_type_check
        ");
        $this->db->query("
            ALTER TABLE reach_approvals
                ADD CONSTRAINT reach_approvals_subject_type_check
                CHECK (subject_type IN (
                    'blog','content_item','kb_article','community_answer',
                    'official_answer','marketing_pack','video_script'
                ))
        ");
    }

    public function down(): void
    {
        $this->db->query("
            ALTER TABLE reach_approvals
                DROP CONSTRAINT IF EXISTS reach_approvals_subject_type_check
        ");
        $this->db->query("
            ALTER TABLE reach_approvals
                ADD CONSTRAINT reach_approvals_subject_type_check
                CHECK (subject_type IN (
                    'blog','content_item','kb_article','community_answer',
                    'official_answer','marketing_pack'
                ))
        ");
    }
}
