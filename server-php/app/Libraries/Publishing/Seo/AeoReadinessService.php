<?php

namespace App\Libraries\Publishing\Seo;

/**
 * Phase 4 — AEO (Answer Engine Optimisation) readiness evaluation.
 *
 * Checks that content is structured for machine comprehension.
 * Does not create thin or repetitive content.
 */
class AeoReadinessService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * @return array{status: string, findings: array<int,array>}
     */
    public function evaluate(int $contentItemId): array
    {
        $aeo = $this->db->table('reach_content_aeo_profiles')
            ->where('content_item_id', $contentItemId)->get()->getRowArray();

        if (!$aeo) {
            return ['status' => 'not_applicable', 'findings' => []];
        }

        $findings = [];
        $blocking = false;

        // Concise answer
        $this->check($findings, $blocking, !empty($aeo['concise_answer']), 'warning', 'no_concise_answer', 'Concise answer block is missing (recommended for AEO)');

        // Questions answered
        $questions = json_decode($aeo['questions_answered_json'] ?? '[]', true) ?? [];
        $this->check($findings, $blocking, count($questions) > 0, 'warning', 'no_questions', 'No questions answered are defined');

        // Entity mentions
        $entities = json_decode($aeo['entity_mentions_json'] ?? '[]', true) ?? [];
        $this->check($findings, $blocking, count($entities) > 0, 'info', 'no_entities', 'No entity mentions defined');

        // Citations
        $citations = json_decode($aeo['citation_summary_json'] ?? '[]', true) ?? [];
        $this->check($findings, $blocking, count($citations) > 0, 'warning', 'no_citations', 'No citations found — citations support answerability');

        // Revision date awareness
        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)->get()->getRowArray();
        $this->check($findings, $blocking, !empty($item['review_due_at']), 'info', 'no_review_date', 'No review date set — AEO content should have a revision schedule');

        $status = $blocking ? 'blocked' : (empty($findings) ? 'ready' : 'warning');

        // Update profile
        $this->db->table('reach_content_aeo_profiles')
            ->where('content_item_id', $contentItemId)
            ->update([
                'aeo_status'    => $status,
                'findings_json' => json_encode($findings),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

        return ['status' => $status, 'findings' => $findings];
    }

    private function check(
        array &$findings,
        bool &$blocking,
        bool $condition,
        string $level,
        string $code,
        string $message,
        bool $isBlocking = false
    ): void {
        if (!$condition) {
            $findings[] = ['level' => $level, 'code' => $code, 'message' => $message];
            if ($isBlocking || $level === 'error') {
                $blocking = true;
            }
        }
    }
}
