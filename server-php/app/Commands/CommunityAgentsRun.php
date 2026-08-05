<?php

namespace App\Commands;

use App\Libraries\Blog\ContentBaseService;
use App\Libraries\Community\CommunityOperationalAgentService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * `php spark community:agents-run` — the trigger the operational agent
 * runtime never had. Cron: every 30 minutes; content-creating actions are
 * window-gated (09:00–19:00 IST) and daily-capped inside
 * CommunityOperationalAgentService, so this command only SELECTS work:
 *
 *   1. curate_question   — content-base question seeds not yet ingested
 *                          (the "approved source" the curation service
 *                          documented as a business decision).
 *   2. draft_answer      — triaged questions with no official answer,
 *                          routed to the matching expert desk by category.
 *   3. categorize_question — questions still missing a category.
 *
 * Every dispatch (success/blocked/failed) is audited to
 * reach_community_agent_runs. No engagement actions exist to dispatch.
 */
class CommunityAgentsRun extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'community:agents-run';
    protected $description = 'Select community work and dispatch the disclosed official-identity agents.';
    protected $usage       = 'community:agents-run [--limit=6]';

    private const CURATOR_SLUG = 'aicountly-question-curator';
    private const STEWARD_SLUG = 'aicountly-community-steward';

    private const CATEGORY_DESKS = [
        'accounting'          => 'aicountly-accounting-guide',
        'gst'                 => 'aicountly-gst-guide',
        'income-tax'          => 'aicountly-income-tax-desk',
        'tds-tcs'             => 'aicountly-income-tax-desk',
        'payroll-hr'          => 'aicountly-payroll-desk',
        'product-guides'      => 'aicountly-smart-books-guide',
        'books'               => 'aicountly-smart-books-guide',
    ];
    private const DEFAULT_DESK = 'aicountly-compliance-desk';

    public function run(array $params): int
    {
        $limit = max(1, (int) (CLI::getOption('limit') ?? ($params['limit'] ?? 6)));

        $lockFile = WRITEPATH . 'community-agents-run.lock';
        $fp       = fopen($lockFile, 'c+');
        if ($fp === false || ! flock($fp, LOCK_EX | LOCK_NB)) {
            CLI::error('Another community agents run is in progress.');
            if ($fp !== false) {
                fclose($fp);
            }

            return 1;
        }

        try {
            $agent   = new CommunityOperationalAgentService();
            $results = [
                'curated'     => $this->curateSeeds($agent, min(2, $limit)),
                'answered'    => $this->draftAnswers($agent, min(3, $limit)),
                'categorized' => $this->categorizeQuestions($agent, min(3, $limit)),
            ];

            CLI::write(json_encode([
                'event' => 'community_agents_run.completed',
                'ts'    => gmdate('c'),
            ] + $results, JSON_UNESCAPED_SLASHES));

            return 0;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function curateSeeds(CommunityOperationalAgentService $agent, int $limit): array
    {
        $db    = Database::connect();
        $seeds = (new ContentBaseService($db))->communityQuestionSeeds()['seeds'] ?? [];

        $dispatched = 0;
        $outcomes   = [];
        foreach ($seeds as $seed) {
            if ($dispatched >= $limit) {
                break;
            }
            $key = trim((string) ($seed['key'] ?? ''));
            if ($key === '' || trim((string) ($seed['question'] ?? '')) === '') {
                continue;
            }

            $exists = $db->table('reach_community_questions')
                ->where('external_question_id', $key)
                ->countAllResults() > 0;
            if ($exists) {
                continue;
            }

            try {
                $result = $agent->dispatch(self::CURATOR_SLUG, 'curate_question', [
                    'title'                => (string) $seed['question'],
                    'body'                 => (string) ($seed['context'] ?? ''),
                    'category'             => (string) ($seed['category'] ?? ''),
                    'source_type'          => 'official_question',
                    'external_question_id' => $key,
                ]);
                $outcomes[] = ['seed' => $key, 'status' => $result['status'] ?? 'success'];
                if (($result['status'] ?? '') === 'blocked') {
                    break; // window closed or cap reached — no point iterating further
                }
                $dispatched++;
            } catch (\Throwable $e) {
                $outcomes[] = ['seed' => $key, 'status' => 'failed', 'error' => substr($e->getMessage(), 0, 120)];
            }
        }

        return ['dispatched' => $dispatched, 'outcomes' => $outcomes];
    }

    /**
     * @return array<string,mixed>
     */
    private function draftAnswers(CommunityOperationalAgentService $agent, int $limit): array
    {
        $db = Database::connect();

        $questions = $db->query(
            "SELECT q.id, q.uuid, q.category
             FROM reach_community_questions q
             WHERE q.status IN ('intake', 'triaged')
               AND q.moderation_state = 'clean'
               AND q.personal_data_detected = FALSE
               AND NOT EXISTS (
                   SELECT 1 FROM reach_community_official_answers a WHERE a.question_id = q.id
               )
             ORDER BY q.triage_score DESC, q.id ASC
             LIMIT ?",
            [$limit]
        )->getResultArray();

        $dispatched = 0;
        $outcomes   = [];
        foreach ($questions as $question) {
            $desk = self::CATEGORY_DESKS[strtolower((string) ($question['category'] ?? ''))] ?? self::DEFAULT_DESK;

            try {
                $result = $agent->dispatch($desk, 'draft_answer', [
                    'question_uuid' => (string) $question['uuid'],
                ]);
                $outcomes[] = ['question_id' => (int) $question['id'], 'desk' => $desk, 'status' => $result['status'] ?? 'success'];
                if (($result['status'] ?? '') !== 'blocked') {
                    $dispatched++;
                }
            } catch (\Throwable $e) {
                $outcomes[] = ['question_id' => (int) $question['id'], 'desk' => $desk, 'status' => 'failed', 'error' => substr($e->getMessage(), 0, 120)];
            }
        }

        return ['dispatched' => $dispatched, 'outcomes' => $outcomes];
    }

    /**
     * @return array<string,mixed>
     */
    private function categorizeQuestions(CommunityOperationalAgentService $agent, int $limit): array
    {
        $db = Database::connect();

        $questions = $db->query(
            "SELECT id FROM reach_community_questions
             WHERE (category IS NULL OR category = '')
               AND moderation_state = 'clean'
             ORDER BY id ASC
             LIMIT ?",
            [$limit]
        )->getResultArray();

        $classifier = new \App\Libraries\Community\CommunityQuestionClassificationService();
        $dispatched = 0;
        $outcomes   = [];
        foreach ($questions as $question) {
            try {
                $classification = $classifier->classifyById((int) $question['id']);
                $category       = (string) ($classification['category_classification'] ?? '');
                if ($category === '') {
                    $outcomes[] = ['question_id' => (int) $question['id'], 'status' => 'skipped_no_classification'];
                    continue;
                }
                $result = $agent->dispatch(self::STEWARD_SLUG, 'categorize_question', [
                    'question_id' => (int) $question['id'],
                    'category'    => $category,
                ]);
                $outcomes[] = ['question_id' => (int) $question['id'], 'status' => $result['status'] ?? 'success'];
                $dispatched++;
            } catch (\Throwable $e) {
                $outcomes[] = ['question_id' => (int) $question['id'], 'status' => 'failed', 'error' => substr($e->getMessage(), 0, 120)];
            }
        }

        return ['dispatched' => $dispatched, 'outcomes' => $outcomes];
    }
}
