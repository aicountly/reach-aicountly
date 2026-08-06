<?php

namespace App\Commands;

use App\Libraries\Blog\CoverSweepService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:blog-cover-sweep [--limit=25]`
 *
 * Re-attempts the cover step for articles parked for want of one. Run it after
 * uploading a batch of covers rather than waiting for the hourly job.
 *
 * The sweep only re-enqueues the work block; the block decides the outcome, so
 * an article whose cover still cannot be sourced simply stays parked.
 */
class ReachBlogCoverSweep extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:blog-cover-sweep';
    protected $description = 'Re-attempt cover assignment for blog articles parked without one.';
    protected $usage       = 'reach:blog-cover-sweep [--limit=25]';
    protected $options     = ['--limit' => 'Maximum parked articles to re-attempt (default 25).'];

    public function run(array $params): int
    {
        $limit = (int) ($params['limit'] ?? CLI::getOption('limit') ?? 25);

        try {
            $result = (new CoverSweepService())->sweep($limit);
        } catch (\Throwable $e) {
            CLI::error('cover sweep failed: ' . $e->getMessage());

            return 1;
        }

        CLI::write(json_encode([
            'event' => 'blog_cover_sweep.completed',
            'ts'    => gmdate('c'),
        ] + $result, JSON_UNESCAPED_SLASHES));

        if ($result['requeued'] > 0) {
            CLI::write(CLI::color(
                'Re-attempts queued. Run `php spark reach:work --queue=blog --limit=' . $result['requeued'] . '` '
                . 'to process them now, or wait for the worker.',
                'yellow',
            ));
        }

        return 0;
    }
}
