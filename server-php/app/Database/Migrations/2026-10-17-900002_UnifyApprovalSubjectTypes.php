<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * reach_approvals carried two CHECK constraints on subject_type from
 * different phases — reach_approvals_subject_check (blog, campaign, social, …)
 * and reach_approvals_subject_type_check (blog, content_item, kb_article,
 * video_script, …). A row must satisfy both, so only `blog` and
 * `content_item` could ever be inserted: social and video-script approvals
 * failed. Replace them with one constraint allowing the union.
 */
class UnifyApprovalSubjectTypes extends Migration
{
    private const TYPES = [
        'blog', 'campaign', 'social', 'email', 'whatsapp', 'landing', 'bot', 'other',
        'product', 'module', 'feature', 'product_claim', 'evidence', 'source', 'citation',
        'brand_rule', 'content_policy', 'search_intent', 'content_item', 'daily_pack',
        'kb_article', 'community_answer', 'official_answer', 'marketing_pack', 'video_script',
    ];

    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_approvals DROP CONSTRAINT IF EXISTS reach_approvals_subject_check');
        $this->db->query('ALTER TABLE reach_approvals DROP CONSTRAINT IF EXISTS reach_approvals_subject_type_check');
        $list = implode(',', array_map(static fn ($t) => "'{$t}'", self::TYPES));
        $this->db->query("ALTER TABLE reach_approvals ADD CONSTRAINT reach_approvals_subject_type_check CHECK (subject_type IN ({$list}))");
    }

    public function down(): void
    {
        // Keep the unified constraint: restoring the contradictory pair would
        // make most approval types impossible to record again.
    }
}
