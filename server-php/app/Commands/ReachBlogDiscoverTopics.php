<?php

namespace App\Commands;

use App\Libraries\Blog\BlogColdStartService;
use App\Libraries\Blog\WorkBlockService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:blog-discover-topics` — create topic candidates so the
 * roadmap optimiser has something to score.
 *
 * Modes:
 *   (default)  Run DISCOVER_TOPICS from approved Knowledge topic clusters.
 *              Auto-bootstraps foundation clusters + pilots if Knowledge is empty.
 *   --title=   Seed one candidate manually (use --pin to force CREATE_NEW).
 */
class ReachBlogDiscoverTopics extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:blog-discover-topics';
    protected $description = 'Discover or seed topic candidates for the blog roadmap.';
    protected $usage       = 'reach:blog-discover-topics [--limit=10] [--title=...] [--pin] [--stream=marketing] [--enqueue] [--no-bootstrap]';

    public function run(array $params): int
    {
        $title  = CLI::getOption('title') ?? ($params['title'] ?? null);
        $limit  = max(1, (int) (CLI::getOption('limit') ?? ($params['limit'] ?? 10)));
        $pin    = CLI::getOption('pin') !== null || array_key_exists('pin', $params);
        $stream = (string) (CLI::getOption('stream') ?? ($params['stream'] ?? 'marketing'));
        $enqueue = CLI::getOption('enqueue') !== null || array_key_exists('enqueue', $params);
        $noBootstrap = CLI::getOption('no-bootstrap') !== null || array_key_exists('no-bootstrap', $params);

        try {
            if (is_string($title) && trim($title) !== '') {
                $result = $this->seedCandidate(trim($title), $pin, $stream);
            } else {
                $result = $this->discoverFromClusters($limit, ! $noBootstrap);
            }

            if ($enqueue) {
                $dispatch = (new WorkBlockService())->enqueueEligibleBatch(25);
                $result['dispatch'] = $dispatch;
            }

            CLI::write(json_encode([
                'event'  => 'blog_discover_topics.completed',
                'ts'     => gmdate('c'),
                'result' => $result,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return 0;
        } catch (Throwable $e) {
            CLI::error($e->getMessage());
            return 1;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function discoverFromClusters(int $limit, bool $allowBootstrap): array
    {
        $cold = new BlogColdStartService();
        $bootstrap = null;

        if ($allowBootstrap && $cold->countApprovedClusters() === 0) {
            $bootstrap = $cold->bootstrap($limit);
        }

        $approved = $cold->countApprovedClusters();
        if ($approved === 0) {
            throw new \RuntimeException(
                'No approved topic clusters found. Approve clusters in Knowledge Foundation, '
                . 'or run: php spark reach:blog-bootstrap'
            );
        }

        $svc = new WorkBlockService();
        $id  = $svc->create([
            'block_type'         => WorkBlockService::TYPE_DISCOVER_TOPICS,
            'eligibility_status' => 'eligible',
            'priority'           => 10,
            'input_json'         => ['limit' => $limit],
            'idempotency_key'    => 'discover-topics-cli-' . gmdate('YmdHis'),
        ]);

        $output = $svc->execute($id);

        // Discover only fills gaps for clusters with no open candidate. If every
        // cluster already had a spent candidate, seed pinned pilots so cron continues.
        $created = (int) ($output['candidates_created'] ?? 0);
        $pilotTopUp = null;
        if ($allowBootstrap && $created === 0 && $cold->countEligibleCandidates() === 0) {
            $pilotTopUp = $cold->ensurePilotCandidates($limit);
        }

        return [
            'mode'                => 'discover_clusters',
            'approved_clusters'   => $approved,
            'work_block_id'       => $id,
            'clusters_considered' => $output['clusters_considered'] ?? 0,
            'candidates_created'  => $created,
            'topic_candidate_ids' => $output['topic_candidate_ids'] ?? [],
            'bootstrap'           => $bootstrap,
            'pilot_top_up'        => $pilotTopUp,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function seedCandidate(string $title, bool $pin, string $stream): array
    {
        $db = Database::connect();
        if (! $db->tableExists('reach_topic_candidates')) {
            throw new \RuntimeException('reach_topic_candidates table is missing — run migrations.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('reach_topic_candidates')->insert([
            'candidate_uuid'   => bin2hex(random_bytes(16)),
            'title'            => $title,
            'normalized_title' => strtolower(trim($title)),
            'slug_hint'        => preg_replace('/[^a-z0-9]+/', '-', strtolower($title)),
            'portfolio_stream' => $stream !== '' ? $stream : 'marketing',
            'status'           => 'candidate',
            'source'           => 'cli_seed',
            'is_human_pinned'  => $pin,
            'is_locked'        => false,
            'evidence_ready'   => true,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $id = (int) $db->insertID();

        return [
            'mode'              => 'seed',
            'topic_candidate_id'=> $id,
            'title'             => $title,
            'is_human_pinned'   => $pin,
            'portfolio_stream'  => $stream,
            'hint'              => $pin
                ? 'Pinned candidates force CREATE_NEW on the next optimiser run.'
                : 'Unpinned candidates need score >= BLOG_ROADMAP_MIN_SCORE (default 40).',
        ];
    }
}
