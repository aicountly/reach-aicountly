<?php

namespace App\Commands;

use App\Libraries\Publishing\PublicationConnectionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:pub-seed-connection`
 *
 * Ensures reach_publication_connections has an enabled aicountly_com row
 * so PUBLISH_BLOG / auto-publish can enqueue deployments.
 */
class ReachPubSeedConnection extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:pub-seed-connection';
    protected $description = 'Seed/enable the default public-site publication connection.';
    protected $usage       = 'reach:pub-seed-connection';

    public function run(array $params): int
    {
        $svc = new PublicationConnectionService();
        $row = $svc->ensureDefaultBlogConnection();
        $key = $svc->resolveBlogConnectionKey();

        CLI::write(json_encode([
            'event'          => 'pub_seed_connection.completed',
            'ts'             => gmdate('c'),
            'connection_key' => $key,
            'connection'     => [
                'id'         => $row['id'] ?? null,
                'base_url'   => $row['base_url'] ?? null,
                'enabled'    => $row['enabled'] ?? null,
                'auth_type'  => $row['authentication_type'] ?? null,
            ],
            'next' => [
                'php spark reach:blog-advance --dispatch',
                'php spark reach:work --queue blog,publishing,community,default --limit 40',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
