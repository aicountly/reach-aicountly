<?php

namespace App\Commands;

use App\Libraries\Blog\ContentBaseService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:content-base-sync`
 *
 * Ingests server-php/content-base/blog/index.json into reach_topic_candidates.
 * Run post-deploy and daily (ReachSchedule). Idempotent by content_base_key.
 */
class ReachContentBaseSync extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:content-base-sync';
    protected $description = 'Sync repo-versioned content-base blog index into topic candidates.';
    protected $usage       = 'reach:content-base-sync';

    public function run(array $params): int
    {
        try {
            $result = (new ContentBaseService())->syncBlogIndex();
            CLI::write(json_encode([
                'event' => 'content_base_sync.completed',
                'ts'    => gmdate('c'),
            ] + $result, JSON_UNESCAPED_SLASHES));

            return 0;
        } catch (\Throwable $e) {
            CLI::error('content-base sync failed: ' . $e->getMessage());

            return 1;
        }
    }
}
