<?php

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Libraries\Blog\BlogRedraftService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:blog-redraft` — re-queue GENERATE_DRAFT for stub/placeholder bodies.
 *
 * Typical production fix after "Untitled draft" versions landed in internal_review:
 *   php spark reach:blog-redraft --stubs --prefer=gemini
 *   php spark reach:blog-redraft --id=3
 */
class ReachBlogRedraft extends BaseCommand
{
    use ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-redraft';
    protected $description = 'Re-generate genuine blog drafts for stub/placeholder bodies.';
    protected $usage       = 'reach:blog-redraft [--id=N] [--stubs] [--limit=20] [--dispatch] [--work] [--prefer=gemini]';
    protected $options     = [
        '--id'      => 'Content item id to redraft.',
        '--stubs'   => 'Redraft all blog items whose current version is a stub body.',
        '--limit'   => 'Max stub items to scan (default 20).',
        '--dispatch'=> 'Print dispatch hint after queueing.',
        '--work'    => 'Print worker hint after queueing.',
        '--prefer'  => 'Hint which AI provider catalog preference to use (e.g. gemini).',
    ];

    public function run(array $params): int
    {
        $id       = $this->sparkOption('id', $params);
        if (($id === null || $id === '') && isset($params[0]) && is_numeric($params[0])) {
            $id = (string) $params[0];
        }
        $stubs    = $this->sparkFlag('stubs', $params);
        $limit    = max(1, (int) ($this->sparkOption('limit', $params, '20') ?? '20'));
        $dispatch = $this->sparkFlag('dispatch', $params);
        $work     = $this->sparkFlag('work', $params);
        $prefer   = (string) ($this->sparkOption('prefer', $params, '') ?? '');

        if ($prefer !== '') {
            CLI::write("Hint: ensure AI routes prefer {$prefer} (php spark reach:ai-seed-catalog --prefer={$prefer})", 'yellow');
        }

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

        if ($dispatch) {
            CLI::write('Dispatch: run `php spark reach:blog-dispatch --force` if blocks stay pending.', 'yellow');
        }
        if ($work) {
            CLI::write('Worker: run `php spark reach:work --queue blog,publishing,community,default --limit 40`', 'yellow');
        }

        CLI::write(json_encode([
            'event'   => 'blog_redraft.completed',
            'ts'      => gmdate('c'),
            'scanned' => count($targets),
            'items'   => $done,
            'next'    => [
                'php spark reach:ai-seed-catalog --prefer=gemini',
                'php spark reach:blog-dispatch --force',
                'php spark reach:work --queue blog,publishing,community,default --limit 40',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
