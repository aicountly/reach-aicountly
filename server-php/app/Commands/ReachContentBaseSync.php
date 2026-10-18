<?php

namespace App\Commands;

use App\Libraries\Content\Base\ContentBaseSync;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:content-base-sync`
 *
 * Imports the git seed files (server-php/content-base/**) into
 * reach_content_base_entries, mirrors blog seeds into roadmap candidates,
 * releases stuck roadmap topics and reconciles statuses (ContentBaseSync).
 * Runs post-deploy and hourly (ReachSchedule). Idempotent.
 */
class ReachContentBaseSync extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:content-base-sync';
    protected $description = 'Import content-base seeds and reconcile topic statuses (blog, KB, community).';
    protected $usage       = 'reach:content-base-sync';

    public function run(array $params): int
    {
        try {
            $result = (new ContentBaseSync())->run();
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
