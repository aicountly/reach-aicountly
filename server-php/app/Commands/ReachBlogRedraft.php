<?php

namespace App\Commands;

use App\Libraries\Blog\BlogRedraftService;
use App\Libraries\Blog\WorkBlockService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:blog-redraft` — re-queue GENERATE_DRAFT for stub/placeholder bodies.
 *
 * Typical production fix after "Untitled draft" versions landed in internal_review:
 *   php spark reach:blog-redraft --stubs --prefer=gemini --dispatch --work
 */
class ReachBlogRedraft extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:blog-redraft';
    protected $description = 'Re-generate genuine blog drafts for stub/placeholder bodies.';
    protected $usage       = 'reach:blog-redraft [--id=] [--stubs] [--limit=20] [--dispatch] [--work] [--prefer=gemini]';

    public function run(array $params): int
    {
        $id       = CLI::getOption('id') ?? ($params['id'] ?? null);
        $stubs    = (bool) (CLI::getOption('stubs') ?? ($params['stubs'] ?? false));
        $limit    = max(1, (int) (CLI::getOption('limit') ?? ($params['limit'] ?? 20)));
        $dispatch = (bool) (CLI::getOption('dispatch') ?? ($params['dispatch'] ?? false));
        $work     = (bool) (CLI::getOption('work') ?? ($params['work'] ?? false));
        $prefer   = (string) (CLI::getOption('prefer') ?? ($params['prefer'] ?? ''));

        if ($prefer !== '') {
            // Soft hint for operators; seed catalog is separate.
            CLI::write("Hint: ensure AI routes prefer {$prefer} (php spark reach:ai-seed-catalog --prefer={$prefer})", 'yellow');
        }

        $svc  = new BlogRedraftService();
        $db   = Database::connect();
        $done = [];

        $targets = [];
        if ($id !== null && $id !== '') {
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
            CLI::error('Pass --id=N or --stubs to select items.');
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
