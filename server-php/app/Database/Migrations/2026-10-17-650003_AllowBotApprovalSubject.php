<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marketing Bot approvals insert reach_approvals rows with subject_type
 * 'bot' (MarketingBotService::execute in Confirm mode), but
 * 2026-07-14-100122 rebuilt the subject-type CHECK without 'bot', so every
 * Confirm-mode bot action failed at the approval insert. Put 'bot' back.
 */
class AllowBotApprovalSubject extends Migration
{
    private const TYPES = ['blog', 'content_item', 'kb_article', 'community_answer', 'official_answer', 'marketing_pack', 'video_script'];

    public function up(): void
    {
        $this->replace(array_merge(self::TYPES, ['bot']), false);
    }

    public function down(): void
    {
        // NOT VALID: bot approvals recorded meanwhile stay readable after a rollback.
        $this->replace(self::TYPES, true);
    }

    /** @param list<string> $types */
    private function replace(array $types, bool $notValid): void
    {
        $list = implode(',', array_map(static fn (string $t): string => "'" . $t . "'", $types));
        $this->db->query('ALTER TABLE reach_approvals DROP CONSTRAINT IF EXISTS reach_approvals_subject_type_check');
        $this->db->query("ALTER TABLE reach_approvals ADD CONSTRAINT reach_approvals_subject_type_check CHECK (subject_type IN ({$list}))" . ($notValid ? ' NOT VALID' : ''));
    }
}
