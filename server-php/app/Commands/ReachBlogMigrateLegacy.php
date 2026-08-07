<?php

namespace App\Commands;

use App\Libraries\ContentItemService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * `php spark reach:blog-migrate-legacy` — idempotent legacy blog post → content item migration.
 */
class ReachBlogMigrateLegacy extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-migrate-legacy';
    protected $description = 'Migrate reach_blog_posts rows into reach_content_items (content_type=blog).';
    protected $usage       = 'reach:blog-migrate-legacy [--dry-run] [--batch-size=50] [--resume-from=0] [--report]';

    public function run(array $params): int
    {
        $dryRun     = $this->sparkFlag('dry-run', $params);
        $batchSize  = max(1, (int) ($this->sparkOption('batch-size', $params, '50') ?? '50'));
        $resumeFrom = max(0, (int) ($this->sparkOption('resume-from', $params, '0') ?? '0'));
        $report     = $this->sparkFlag('report', $params);

        $db = Database::connect();

        $query = $db->table('reach_blog_posts')
            ->where('id >', $resumeFrom)
            ->orderBy('id', 'ASC')
            ->limit($batchSize);

        $posts = $query->get()->getResultArray();

        $stats = [
            'processed' => 0,
            'migrated'  => 0,
            'skipped'   => 0,
            'errors'    => 0,
            'dry_run'   => $dryRun,
        ];

        $contentService = new ContentItemService();

        foreach ($posts as $post) {
            $stats['processed']++;
            $legacyId = (int) $post['id'];

            try {
                $existingMap = $db->table('reach_blog_legacy_migration_map')
                    ->where('legacy_blog_post_id', $legacyId)
                    ->get()
                    ->getRowArray();

                if ($existingMap && ($existingMap['status'] ?? '') === 'completed') {
                    $stats['skipped']++;
                    continue;
                }

                if (! empty($post['content_item_id'])) {
                    $this->upsertMap($db, $legacyId, (int) $post['content_item_id'], null, 'completed', null, $dryRun);
                    $stats['skipped']++;
                    continue;
                }

                if ($dryRun) {
                    $stats['migrated']++;
                    continue;
                }

                $result = $contentService->create(
                    [
                        'content_type'    => 'blog',
                        'title'           => $post['title'] ?? 'Untitled',
                        'slug'            => $post['slug'] ?? ('legacy-' . $legacyId),
                        'summary'         => $post['excerpt'] ?? null,
                        'workflow_status' => $post['status'] ?? 'draft',
                        'creation_source' => 'legacy_migration',
                        'published_at'    => $post['published_at'] ?? null,
                        'scheduled_at'    => $post['scheduled_at'] ?? null,
                    ],
                    [
                        'body_html' => $post['content'] ?? '',
                        'title'     => $post['title'] ?? 'Untitled',
                    ],
                    ['type' => 'system', 'service' => 'reach:blog-migrate-legacy'],
                );

                $contentItemId    = (int) $result['item']['id'];
                $contentVersionId = (int) $result['version']['id'];

                $db->table('reach_blog_posts')->where('id', $legacyId)->update([
                    'content_item_id' => $contentItemId,
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);

                $this->upsertMap(
                    $db,
                    $legacyId,
                    $contentItemId,
                    $contentVersionId,
                    'completed',
                    null,
                    false,
                );

                $stats['migrated']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                if ($existingMap ?? null) {
                    $this->upsertMap($db, $legacyId, (int) ($existingMap['content_item_id'] ?? 0), null, 'failed', $e->getMessage(), $dryRun);
                }
                CLI::error("Legacy post {$legacyId}: " . $e->getMessage());
            }
        }

        $output = json_encode(array_merge(['event' => 'blog_migrate_legacy.batch'], $stats), JSON_UNESCAPED_SLASHES);
        CLI::write($output);

        if ($report) {
            $reportPath = WRITEPATH . 'logs/blog-migrate-legacy-' . date('Ymd-His') . '.json';
            file_put_contents($reportPath, $output . PHP_EOL);
            CLI::write("Report written to {$reportPath}");
        }

        return $stats['errors'] > 0 ? 1 : 0;
    }

    private function upsertMap(
        mixed $db,
        int $legacyId,
        int $contentItemId,
        ?int $contentVersionId,
        string $status,
        ?string $error,
        bool $dryRun,
    ): void {
        if ($dryRun) {
            return;
        }

        $existing = $db->table('reach_blog_legacy_migration_map')
            ->where('legacy_blog_post_id', $legacyId)
            ->get()
            ->getRowArray();

        $row = [
            'legacy_blog_post_id' => $legacyId,
            'content_item_id'     => $contentItemId > 0 ? $contentItemId : (int) ($existing['content_item_id'] ?? 0),
            'content_version_id'  => $contentVersionId,
            'status'              => $status,
            'error_message'       => $error,
            'migrated_at'         => $status === 'completed' ? date('Y-m-d H:i:s') : null,
            'updated_at'          => date('Y-m-d H:i:s'),
        ];

        if ($row['content_item_id'] <= 0 && $status !== 'completed') {
            return;
        }

        if ($existing) {
            $db->table('reach_blog_legacy_migration_map')->where('id', $existing['id'])->update($row);
            return;
        }

        $row['created_at'] = date('Y-m-d H:i:s');
        $db->table('reach_blog_legacy_migration_map')->insert($row);
    }
}
