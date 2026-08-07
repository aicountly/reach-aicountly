<?php

namespace App\Commands;

use App\Libraries\Blog\BlogColdStartService;
use App\Libraries\Blog\Roadmap\RoadmapOptimizerService;
use App\Libraries\Blog\WorkBlockService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * `php spark reach:blog-bootstrap` — seed approved topic clusters + pinned pilots
 * when Knowledge / roadmap backlog is empty, then optionally run optimiser.
 */
class ReachBlogBootstrap extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-bootstrap';
    protected $description = 'Cold-start approved topic clusters and pinned pilot candidates.';
    protected $usage       = 'reach:blog-bootstrap [--pilots=5] [--optimize] [--dispatch]';

    public function run(array $params): int
    {
        $pilots   = max(1, (int) ($this->sparkOption('pilots', $params, '5') ?? '5'));
        $optimize = $this->sparkFlag('optimize', $params);
        $dispatch = $this->sparkFlag('dispatch', $params);

        try {
            $cold   = new BlogColdStartService();
            $result = $cold->bootstrap($pilots);

            if ($optimize) {
                $forDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
                $result['optimize'] = (new RoadmapOptimizerService())->run($forDate, [
                    'limit'   => $pilots,
                    'dry_run' => false,
                ]);
            }

            if ($dispatch) {
                $result['dispatch'] = (new WorkBlockService())->enqueueEligibleBatch(25);
            }

            CLI::write(json_encode([
                'event'  => 'blog_bootstrap.completed',
                'ts'     => gmdate('c'),
                'result' => $result,
                'next'   => [
                    'php spark reach:blog-optimize-roadmap --force',
                    'php spark reach:blog-dispatch --force',
                    'php spark reach:work --queue blog,publishing,community,default --limit 20',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return 0;
        } catch (Throwable $e) {
            CLI::error($e->getMessage());
            return 1;
        }
    }
}
