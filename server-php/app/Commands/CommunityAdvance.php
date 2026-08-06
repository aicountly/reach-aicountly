<?php

namespace App\Commands;

use App\Enums\CommunityAnswerStatus;
use App\Libraries\Community\CommunityAutoPublishService;
use App\Libraries\Community\CommunityTriageService;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark community:advance` — the missing second half of Community Q&A
 * automation.
 *
 * `community:agents-run` creates an official answer draft and asks the AI to
 * generate it. Nothing then moved that draft forward: a generation failure
 * parked the answer in `validation_failed` with zero versions and no retry
 * path, and a *successful* draft sat in `draft_generated` forever because
 * validation, moderation and review-queue routing were UI-only actions. Either
 * way nothing was ever approved, so nothing was ever published, so
 * aicountly.com's community stayed empty.
 *
 * This command runs the four steps that were missing, in order:
 *
 *   1. triage   — score questions still sitting at 0.000 so the inbox ranks.
 *   2. regenerate — retry generation for answers stuck with no usable version
 *                   (bounded by --max-generation-attempts).
 *   3. advance  — validate + moderate generated drafts, then either queue them
 *                 for human approval (tier 2/3, or flag off) or auto-approve
 *                 and publish them (tier 0/1 with COMMUNITY_AUTO_PUBLISH_ENABLED).
 *   4. report   — one JSON line per run, suitable for a cron log.
 *
 * Cron (every 15 minutes is plenty):
 *   asterisk/15 * * * * cd /path/to/server-php && php spark community:advance
 */
class CommunityAdvance extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'community:advance';
    protected $description = 'Triage questions, retry stuck answer generation, and route generated drafts to review or publication.';
    protected $usage       = 'community:advance [--limit=10] [--max-generation-attempts=3] [--skip-triage] [--dry-run]';
    protected $options     = [
        '--limit'                    => 'Max answers to touch per stage (default 10).',
        '--max-generation-attempts'  => 'Give up regenerating an answer after N versions-less attempts (default 3).',
        '--skip-triage'              => 'Do not re-score question triage.',
        '--dry-run'                  => 'Report what would happen without changing anything.',
    ];

    public function run(array $params): int
    {
        $limit    = max(1, (int) (CLI::getOption('limit') ?? ($params['limit'] ?? 10)));
        $maxTries = max(1, (int) (CLI::getOption('max-generation-attempts') ?? ($params['max-generation-attempts'] ?? 3)));
        $skipTriage = (bool) (CLI::getOption('skip-triage') ?? ($params['skip-triage'] ?? false));
        $dryRun   = (bool) (CLI::getOption('dry-run') ?? ($params['dry-run'] ?? false));

        $lockFile = WRITEPATH . 'community-advance.lock';
        $fp       = fopen($lockFile, 'c+');
        if ($fp === false || ! flock($fp, LOCK_EX | LOCK_NB)) {
            CLI::error('Another community advance run is in progress.');
            if ($fp !== false) {
                fclose($fp);
            }

            return 1;
        }

        try {
            $report = [
                'event'            => 'community_advance.completed',
                'ts'               => gmdate('c'),
                'dry_run'          => $dryRun,
                'auto_publish'     => CommunityAutoPublishService::isEnabled(),
                'triaged'          => $skipTriage ? ['skipped' => true] : $this->triage($limit, $dryRun),
                'regenerated'      => $this->regenerate($limit, $maxTries, $dryRun),
                'advanced'         => $this->advance($limit, $dryRun),
            ];

            CLI::write(json_encode($report, JSON_UNESCAPED_SLASHES));

            return 0;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Questions land at triage_score 0.000 and stay there, so the inbox
     * ordering ("Sort: triage score") is meaningless and the answering desk
     * picks work arbitrarily.
     *
     * @return array<string,mixed>
     */
    private function triage(int $limit, bool $dryRun): array
    {
        $db = Database::connect();

        try {
            $rows = $db->table('reach_community_questions')
                ->select('id')
                ->where('triage_score', 0)
                ->whereIn('status', ['intake', 'triaged', 'draft_requested'])
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray();
        } catch (Throwable $e) {
            return ['scored' => 0, 'error' => mb_substr($e->getMessage(), 0, 160)];
        }

        if ($dryRun) {
            return ['scored' => 0, 'would_score' => count($rows)];
        }

        $triage = new CommunityTriageService();
        $scored = 0;
        $errors = [];

        foreach ($rows as $row) {
            try {
                $triage->scoreById((int) $row['id']);
                $scored++;
            } catch (Throwable $e) {
                $errors[] = ['question_id' => (int) $row['id'], 'error' => mb_substr($e->getMessage(), 0, 120)];
            }
        }

        return array_filter(['scored' => $scored, 'errors' => $errors ?: null], static fn ($v) => $v !== null);
    }

    /**
     * Answers whose generation threw are parked in `validation_failed` with no
     * version at all — a state the UI cannot resolve and no other job retries.
     * Regenerate them, bounded so a permanently broken provider cannot loop.
     *
     * @return array<string,mixed>
     */
    private function regenerate(int $limit, int $maxAttempts, bool $dryRun): array
    {
        $db = Database::connect();

        try {
            $rows = $db->query(
                "SELECT a.id, a.uuid, a.status,
                        (SELECT COUNT(*) FROM reach_community_answer_versions v WHERE v.answer_id = a.id) AS version_count
                   FROM reach_community_official_answers a
                  WHERE a.status IN (?, ?)
                    AND a.updated_at < NOW() - INTERVAL '10 minutes'
                  ORDER BY a.id ASC
                  LIMIT ?",
                [
                    CommunityAnswerStatus::ValidationFailed->value,
                    CommunityAnswerStatus::Generating->value,
                    $limit,
                ]
            )->getResultArray();
        } catch (Throwable $e) {
            return ['attempted' => 0, 'error' => mb_substr($e->getMessage(), 0, 160)];
        }

        $candidates = array_values(array_filter(
            $rows,
            static fn (array $r): bool => (int) $r['version_count'] === 0
        ));

        if ($dryRun) {
            return ['attempted' => 0, 'would_attempt' => count($candidates)];
        }

        $lifecycle = new OfficialAnswerLifecycleService();
        $repo      = new \App\Libraries\Community\OfficialAnswerRepository();
        $outcomes  = [];
        $succeeded = 0;

        foreach ($candidates as $row) {
            $answerId = (int) $row['id'];

            if ($this->generationAttempts($db, $answerId) >= $maxAttempts) {
                $outcomes[] = ['answer_id' => $answerId, 'outcome' => 'attempts_exhausted'];
                continue;
            }

            // A worker that died mid-generation leaves the answer in
            // `generating`, and generating→generating is not a legal
            // transition, so the retry would throw. Park it in the state the
            // enum *does* allow a retry from first.
            if ((string) $row['status'] === CommunityAnswerStatus::Generating->value) {
                try {
                    $repo->transitionStatus(
                        $answerId,
                        CommunityAnswerStatus::Generating,
                        CommunityAnswerStatus::ValidationFailed,
                    );
                } catch (Throwable $e) {
                    $outcomes[] = [
                        'answer_id' => $answerId,
                        'outcome'   => 'stuck_generating_unrecoverable',
                        'error'     => mb_substr($e->getMessage(), 0, 160),
                    ];
                    continue;
                }
            }

            try {
                $lifecycle->requestGeneration((string) $row['uuid']);
                $succeeded++;
                $outcomes[] = ['answer_id' => $answerId, 'outcome' => 'regenerated'];
            } catch (Throwable $e) {
                $outcomes[] = [
                    'answer_id' => $answerId,
                    'outcome'   => 'failed',
                    'error'     => mb_substr($e->getMessage(), 0, 160),
                ];
            }
        }

        return ['attempted' => count($candidates), 'succeeded' => $succeeded, 'outcomes' => $outcomes];
    }

    /**
     * How many generation requests this answer has already burned. Counted from
     * the audit trail so it survives the answer row being rewritten.
     */
    private function generationAttempts(\CodeIgniter\Database\BaseConnection $db, int $answerId): int
    {
        try {
            if (! $db->tableExists('reach_audit_logs')) {
                return 0;
            }

            return (int) $db->table('reach_audit_logs')
                ->where('action', \App\Libraries\AuditLogger::COMMUNITY_ANSWER_GENERATION_REQUESTED)
                ->like('metadata', '"answer_id":' . $answerId . ',', 'both')
                ->countAllResults();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function advance(int $limit, bool $dryRun): array
    {
        $db = Database::connect();

        try {
            $rows = $db->table('reach_community_official_answers')
                ->select('id')
                ->where('status', CommunityAnswerStatus::DraftGenerated->value)
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray();
        } catch (Throwable $e) {
            return ['processed' => 0, 'error' => mb_substr($e->getMessage(), 0, 160)];
        }

        if ($dryRun) {
            return ['processed' => 0, 'would_process' => count($rows)];
        }

        $service  = new CommunityAutoPublishService();
        $outcomes = [];

        foreach ($rows as $row) {
            try {
                $outcomes[] = $service->advance((int) $row['id']);
            } catch (Throwable $e) {
                $outcomes[] = [
                    'answer_id' => (int) $row['id'],
                    'outcome'   => 'error',
                    'detail'    => mb_substr($e->getMessage(), 0, 160),
                ];
            }
        }

        $published = count(array_filter($outcomes, static fn (array $o): bool => ($o['outcome'] ?? '') === 'published'));
        $queued    = count(array_filter($outcomes, static fn (array $o): bool => ($o['outcome'] ?? '') === 'queued_for_human_review'));

        return [
            'processed' => count($outcomes),
            'published' => $published,
            'queued_for_human_review' => $queued,
            'outcomes'  => $outcomes,
        ];
    }
}
