<?php

namespace App\Commands;

use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Blog\WorkBlockService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:blog-advance` — enqueue the next production work block for
 * blog content items stuck after brief/outline (or a specific --id=).
 *
 * Then run:
 *   php spark reach:blog-dispatch --force
 *   php spark reach:work --queue blog,publishing,community,default --once --limit 10
 */
class ReachBlogAdvance extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:blog-advance';
    protected $description = 'Enqueue next blog work block(s) for stuck brief/outline/draft items.';
    protected $usage       = 'reach:blog-advance [--id=] [--limit=20] [--dispatch] [--work]';

    /** @var array<string, string> */
    private const NEXT_BY_STATUS = [
        BlogStateMachine::BRIEF_READY      => WorkBlockService::TYPE_GENERATE_OUTLINE,
        BlogStateMachine::OUTLINE_READY    => WorkBlockService::TYPE_GENERATE_DRAFT,
        BlogStateMachine::DRAFT_GENERATING => WorkBlockService::TYPE_GENERATE_DRAFT,
        BlogStateMachine::FAILED           => WorkBlockService::TYPE_GENERATE_DRAFT,
        BlogStateMachine::DRAFT            => WorkBlockService::TYPE_FACT_VERIFY,
        BlogStateMachine::FACT_VERIFIED    => WorkBlockService::TYPE_SEO_OPTIMIZE,
        BlogStateMachine::SEO_REVIEW       => WorkBlockService::TYPE_CROSS_REVIEW,
    ];

    public function run(array $params): int
    {
        $id       = CLI::getOption('id') ?? ($params['id'] ?? null);
        $limit    = max(1, (int) (CLI::getOption('limit') ?? ($params['limit'] ?? 20)));
        $dispatch = (bool) (CLI::getOption('dispatch') ?? ($params['dispatch'] ?? false));
        $work     = (bool) (CLI::getOption('work') ?? ($params['work'] ?? false));

        $db  = Database::connect();
        $svc = new WorkBlockService();

        $builder = $db->table('reach_content_items')
            ->select('id, title, workflow_status, current_version_id')
            ->where('content_type', 'blog')
            ->where('deleted_at IS NULL', null, false)
            ->whereIn('workflow_status', array_keys(self::NEXT_BY_STATUS))
            ->orderBy('id', 'ASC')
            ->limit($limit);

        if ($id !== null && $id !== '') {
            $builder->where('id', (int) $id);
        }

        $items   = $builder->get()->getResultArray();
        $created = [];

        if ($items === [] && $id !== null && $id !== '') {
            $row = $db->table('reach_content_items')
                ->select('id, title, content_type, workflow_status, current_version_id, deleted_at')
                ->where('id', (int) $id)
                ->get()
                ->getRowArray();

            CLI::write(json_encode([
                'event'   => 'blog_advance.completed',
                'ts'      => gmdate('c'),
                'scanned' => 0,
                'items'   => [],
                'lookup'  => $row ? [
                    'id'                 => (int) $row['id'],
                    'title'              => $row['title'] ?? null,
                    'content_type'       => $row['content_type'] ?? null,
                    'workflow_status'    => $row['workflow_status'] ?? null,
                    'current_version_id' => $row['current_version_id'] ?? null,
                    'deleted'            => ! empty($row['deleted_at']),
                    'advanceable'        => false,
                    'reason'             => 'workflow_status_not_in_advance_map',
                    'advanceable_statuses' => array_keys(self::NEXT_BY_STATUS),
                ] : [
                    'id'     => (int) $id,
                    'found'  => false,
                    'reason' => 'content_item_not_found',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        foreach ($items as $item) {
            $contentItemId = (int) $item['id'];
            $status        = (string) $item['workflow_status'];
            $blockType     = self::NEXT_BY_STATUS[$status];
            $versionId     = (int) ($item['current_version_id'] ?? 0);

            $existing = $db->table('reach_work_blocks')
                ->where('content_item_id', $contentItemId)
                ->where('block_type', $blockType)
                ->whereIn('eligibility_status', ['pending', 'eligible', 'queued', 'running'])
                ->countAllResults();

            if ($existing > 0) {
                $created[] = [
                    'content_item_id' => $contentItemId,
                    'status'          => $status,
                    'action'          => 'skipped_existing_block',
                    'block_type'      => $blockType,
                ];
                continue;
            }

            if (in_array($blockType, [
                WorkBlockService::TYPE_FACT_VERIFY,
                WorkBlockService::TYPE_CROSS_REVIEW,
            ], true) && $versionId <= 0) {
                $created[] = [
                    'content_item_id' => $contentItemId,
                    'status'          => $status,
                    'action'          => 'skipped_missing_version',
                    'block_type'      => $blockType,
                ];
                continue;
            }

            try {
                $blockId = $svc->create([
                    'block_type'         => $blockType,
                    'scope'              => 'blog',
                    'content_item_id'    => $contentItemId,
                    'content_version_id' => $versionId > 0 ? $versionId : null,
                    'eligibility_status' => 'eligible',
                    'priority'           => 20,
                    'idempotency_key'    => "blog-{$contentItemId}-advance-{$blockType}-" . gmdate('YmdHis'),
                ]);
                $created[] = [
                    'content_item_id' => $contentItemId,
                    'title'           => $item['title'],
                    'status'          => $status,
                    'action'          => 'created',
                    'block_type'      => $blockType,
                    'work_block_id'   => $blockId,
                ];
            } catch (Throwable $e) {
                $created[] = [
                    'content_item_id' => $contentItemId,
                    'status'          => $status,
                    'action'          => 'error',
                    'error'           => $e->getMessage(),
                ];
            }
        }

        $result = [
            'event'   => 'blog_advance.completed',
            'ts'      => gmdate('c'),
            'scanned' => count($items),
            'items'   => $created,
        ];

        if ($dispatch) {
            $result['dispatch'] = $svc->enqueueEligibleBatch(25);
        }

        CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($work) {
            CLI::write('Run worker separately: php spark reach:work --queue blog,publishing,community,default --once --limit 10');
        }

        return 0;
    }
}
