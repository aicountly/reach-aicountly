<?php

namespace App\Commands;

use App\Libraries\Blog\ContentBaseService;
use App\Libraries\Community\CommunityAgentWorkSelector;
use App\Libraries\Community\CommunityOperationalAgentService;
use App\Libraries\Community\CommunityQuestionIntakeService;
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
 *   2. intake processing — questions that were never classified (a curation
 *                          that failed part-way, or a source not processed
 *                          inline) get classification, triage and the
 *                          duplicate check, so none reaches a desk unscreened.
 *   3. draft_answer      — screened questions with no official answer,
 *                          routed to the matching expert desk by category,
 *                          plus first drafts whose generation failed, retried
 *                          by their owning desk on a backoff schedule.
 *   4. categorize_question — questions still missing a category.
 *
 * Every dispatch (success/blocked/failed) is audited to
 * reach_community_agent_runs. No engagement actions exist to dispatch.
 */
class CommunityAgentsRun extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

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
        $limit = max(1, (int) ($this->sparkOption('limit', $params, '6') ?? '6'));

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
            $agent    = new CommunityOperationalAgentService();
            $selector = new CommunityAgentWorkSelector();
            $results  = [
                'curated'     => $this->curateSeeds($agent, min(2, $limit)),
                'processed'   => $this->completeIntakeProcessing($selector, $limit),
                'answered'    => $this->draftAnswers($agent, $selector, min(3, $limit)),
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

        // Attempts, not successes, bound the run: a curation that fails after
        // intake has still created a question, so a persistent failure must
        // not sweep through every seed in one tick.
        $attempts   = 0;
        $dispatched = 0;
        $outcomes   = [];
        foreach ($seeds as $seed) {
            if ($attempts >= $limit) {
                break;
            }
            $key = trim((string) ($seed['key'] ?? ''));
            if ($key === '' || trim((string) ($seed['question'] ?? '')) === '') {
                continue;
            }

            // A question left unclassified by a failed curation is finished by
            // the intake-processing step, so existing is enough to skip here.
            $exists = $db->table('reach_community_questions')
                ->where('external_question_id', $key)
                ->countAllResults() > 0;
            if ($exists) {
                continue;
            }

            $attempts++;
            try {
                $result = $agent->dispatch(self::CURATOR_SLUG, 'curate_question', [
                    'title'                => (string) $seed['question'],
                    'body'                 => (string) ($seed['context'] ?? ''),
                    'category'             => (string) ($seed['category'] ?? ''),
                    'source_type'          => 'official_question',
                    'external_question_id' => $key,
                ]);
                $outcomes[] = ['seed' => $key] + self::outcomeOf($result);
                if (! empty($result['blocked'])) {
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
    private function completeIntakeProcessing(CommunityAgentWorkSelector $selector, int $limit): array
    {
        $intake    = new CommunityQuestionIntakeService();
        $completed = 0;
        $outcomes  = [];
        foreach ($selector->questionsAwaitingProcessing($limit) as $questionId) {
            try {
                $intake->completeProcessing($questionId);
                $outcomes[] = ['question_id' => $questionId, 'status' => 'success'];
                $completed++;
            } catch (\Throwable $e) {
                $outcomes[] = ['question_id' => $questionId, 'status' => 'failed', 'error' => substr($e->getMessage(), 0, 120)];
            }
        }

        return ['completed' => $completed, 'outcomes' => $outcomes];
    }

    /**
     * @return array<string,mixed>
     */
    private function draftAnswers(CommunityOperationalAgentService $agent, CommunityAgentWorkSelector $selector, int $limit): array
    {
        $work = $selector->draftCandidates($limit);

        $dispatched = 0;
        $outcomes   = [];
        foreach ($work['candidates'] as $candidate) {
            // A retry goes to the desk that owns the answer; a first draft to the category's desk.
            $desk = $candidate['owner_slug']
                ?? self::CATEGORY_DESKS[strtolower($candidate['category'])]
                ?? self::DEFAULT_DESK;
            $base = [
                'question_id' => $candidate['question_id'],
                'desk'        => $desk,
                'attempt'     => $candidate['attempt'],
            ];

            try {
                $result = $agent->dispatch($desk, 'draft_answer', [
                    'question_uuid' => $candidate['question_uuid'],
                    'attempt'       => $candidate['attempt'],
                ]);
                $outcomes[] = $base + self::outcomeOf($result);
                if (empty($result['blocked'])) {
                    $dispatched++;
                }
            } catch (\Throwable $e) {
                $outcomes[] = $base + ['status' => 'failed', 'error' => substr($e->getMessage(), 0, 120)];
            }
        }

        return ['dispatched' => $dispatched, 'exhausted' => $work['exhausted'], 'outcomes' => $outcomes];
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
                $outcomes[] = ['question_id' => (int) $question['id']] + self::outcomeOf($result);
                $dispatched++;
            } catch (\Throwable $e) {
                $outcomes[] = ['question_id' => (int) $question['id'], 'status' => 'failed', 'error' => substr($e->getMessage(), 0, 120)];
            }
        }

        return ['dispatched' => $dispatched, 'outcomes' => $outcomes];
    }

    /**
     * dispatch() reports a window or cap refusal as ['blocked' => true,
     * 'reason' => ...] rather than throwing; anything else it returns is a
     * success. Reading a 'status' key instead logged every refusal as success.
     *
     * @return array{status: string, reason?: string}
     */
    private static function outcomeOf(array $result): array
    {
        if (! empty($result['blocked'])) {
            return ['status' => 'blocked', 'reason' => (string) ($result['reason'] ?? '')];
        }

        return ['status' => 'success'];
    }
}
