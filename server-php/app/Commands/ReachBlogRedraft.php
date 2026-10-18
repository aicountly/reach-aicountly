<?php

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Libraries\Blog\BlogRedraftService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:blog-redraft` — queue a same-slug rewrite by the Claude blog
 * routine for stub/placeholder bodies (RegenerationQueue; Reach's own
 * GENERATE_DRAFT step is retired). The routine picks it up from
 * GET v1/automation/regenerate/next on its next run; the rewrite is vetted
 * and published automatically.
 *   php spark reach:blog-redraft --stubs
 *   php spark reach:blog-redraft --id=3
 */
class ReachBlogRedraft extends BaseCommand
{
    use ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-redraft';
    protected $description = 'Queue routine rewrites (same slug) for blog items whose body is a stub/placeholder.';
    protected $usage       = 'reach:blog-redraft [--id=N] [--stubs] [--limit=20]';
    protected $options     = [
        '--id'      => 'Content item id to redraft.',
        '--stubs'   => 'Redraft all blog items whose current version is a stub body.',
        '--limit'   => 'Max stub items to scan (default 20).',
    ];

    public function run(array $params): int
    {
        $id       = $this->sparkOption('id', $params);
        if (($id === null || $id === '') && isset($params[0]) && is_numeric($params[0])) {
            $id = (string) $params[0];
        }
        $stubs    = $this->sparkFlag('stubs', $params);
        $limit    = max(1, (int) ($this->sparkOption('limit', $params, '20') ?? '20'));

        $svc  = new BlogRedraftService();
        $db   = Database::connect();
        $done = [];

        $targets = [];
        if ($id !== null && $id !== '' && ctype_digit((string) $id)) {
            $row = $db->table('reach_content_items')
                ->select('id, title, workflow_status, current_version_id')
                ->where('id', (int) $id)
                ->where('content_type', 'blog')
                ->get()
                ->getRowArray();
            if (! $row) {
                CLI::error("Blog item #{$id} not found.");
                return 1;
            }
            $targets[] = $row;
        } elseif ($stubs) {
            $targets = $svc->findStubItems($limit);
        } else {
            CLI::error('Pass --id=N or --stubs to select items. Example: php spark reach:blog-redraft --id=3');
            return 1;
        }

        foreach ($targets as $item) {
            try {
                $result = $svc->redraft((int) $item['id']);
                $done[] = array_merge($result, [
                    'title'  => $item['title'] ?? null,
                    'action' => 'queued',
                ]);
            } catch (Throwable $e) {
                $done[] = [
                    'content_item_id' => (int) $item['id'],
                    'action'          => 'error',
                    'error'           => $e->getMessage(),
                ];
            }
        }

        CLI::write(json_encode([
            'event'   => 'blog_redraft.completed',
            'ts'      => gmdate('c'),
            'scanned' => count($targets),
            'items'   => $done,
            'next'    => 'Nothing to run: the reach-blog routine takes queued rewrites first (GET v1/automation/regenerate/next).',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
