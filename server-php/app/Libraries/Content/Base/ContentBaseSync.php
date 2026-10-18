<?php

declare(strict_types=1);

namespace App\Libraries\Content\Base;

use App\Libraries\Blog\ContentBaseService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * One pass of `reach.content_base_sync` (hourly from reach:schedule, after
 * every deploy, and `php spark reach:content-base-sync`):
 *
 *   1. import the git seed files into reach_content_base_entries (adds new
 *      seeds, never resets progress);
 *   2. mirror the blog seeds into roadmap topic candidates (unchanged
 *      behaviour — the optimiser scores them);
 *   3. hand roadmap topics stuck in the retired in-house drafting back to the
 *      routine queue (RoadmapRelease);
 *   4. reconcile every open entry's status from the pipeline tables.
 *
 * Idempotent; safe to run as often as wanted.
 */
final class ContentBaseSync
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $entries = new ContentBaseEntries($this->db);
        $out     = ['available' => $entries->available()];

        $files = new ContentBaseService($this->db);
        $last  = $files->lastSyncRun('blog_index');
        $raw   = (string) @file_get_contents($files->basePath() . '/blog/index.json');
        if ($last === null || $last['file_checksum'] !== hash('sha256', $raw) || strtotime((string) $last['ran_at']) < time() - 23 * 3600) {
            $out['blog_candidates'] = $files->syncBlogIndex();
        }
        if (! $out['available']) {
            $out['note'] = 'reach_content_base_entries is missing — run php spark migrate.';

            return $out;
        }

        $out['seeds']     = $entries->importSeeds();
        $out['released']  = (new RoadmapRelease($this->db))->run();
        $out['reconciled']= $entries->reconcile();

        return $out;
    }
}
