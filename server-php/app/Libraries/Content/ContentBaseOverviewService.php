<?php

declare(strict_types=1);

namespace App\Libraries\Content;

use App\Libraries\Blog\ContentBaseService;
use App\Libraries\Content\Base\ContentBaseEntries;
use App\Libraries\Content\Base\ContentBaseSync;
use App\Libraries\Content\Base\RoutineActivity;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Console view of the living content base (Quality Centre → Content Base):
 * blog, knowledge base and community Q&A topics from
 * reach_content_base_entries, each with the status Reach derived from its own
 * pipeline, plus when each routine last reached Reach.
 *
 * The page used to read the git files and compare their seed keys with the
 * pipeline: a topic counted as "produced" as soon as any item existed (even an
 * idea Reach's retired drafting left at brief_ready), KB articles written
 * under the routine's own keys never counted at all, and the "base updated"
 * date was the date someone last edited a JSON file. Now:
 *   status   planned · queued · produced · published · retired (one vocabulary)
 *   origin   seed (git baseline) · routine (proposed through the API)
 *   routines last call / submission per content type, flagged when quiet
 *
 * Reading the page imports the seeds when the table is empty and reconciles
 * statuses (both idempotent), so what it shows is current.
 */
class ContentBaseOverviewService
{
    private BaseConnection $db;
    private ContentBaseService $base;

    public function __construct(?BaseConnection $db = null, ?ContentBaseService $base = null)
    {
        $this->db   = $db ?? Database::connect();
        $this->base = $base ?? new ContentBaseService($this->db);
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $entries = new ContentBaseEntries($this->db, $this->base);
        $health  = (new RoutineActivity($this->db))->health();

        if (! $entries->available()) {
            return [
                'available'     => false,
                'note'          => 'The content base table is not migrated on this server — run php spark migrate.',
                'base_markdown' => $this->base->baseMarkdown(),
                'surfaces'      => [],
                'routines'      => $health,
                'totals'        => ['entries' => 0, 'published' => 0, 'in_progress' => 0, 'planned' => 0, 'done' => 0, 'pending' => 0],
            ];
        }
        if ($entries->isEmpty()) {
            (new ContentBaseSync($this->db))->run();
        }
        $entries->reconcile();

        $surfaces = [
            'blog'           => $this->section($entries, 'blog', 'Blog', 'blog/index.json', $health['blog'] ?? null),
            'knowledge_base' => $this->knowledgeSection($entries, $health['knowledge_base'] ?? null),
            'community'      => $this->section($entries, 'community', 'Community Q&A', 'community/question-seeds.json', $health['community'] ?? null),
        ];

        $totals = ['entries' => 0, 'published' => 0, 'in_progress' => 0, 'planned' => 0];
        foreach ($surfaces as $s) {
            $totals['entries']     += $s['counts']['total'];
            $totals['published']   += $s['counts']['published'];
            $totals['in_progress'] += $s['counts']['queued'] + $s['counts']['produced'];
            $totals['planned']     += $s['counts']['planned'];
        }
        $totals['done']    = $totals['published'];
        $totals['pending'] = $totals['planned'] + $totals['in_progress'];

        return [
            'available'     => true,
            'base_path'     => $this->base->basePath(),
            'base_markdown' => $this->base->baseMarkdown(),
            'last_sync'     => $this->lastSeedImport(),
            'routines'      => $health,
            'stale'         => RoutineActivity::staleLines($health),
            'surfaces'      => $surfaces,
            'totals'        => $totals,
        ];
    }

    /** @return array<string,mixed> */
    private function section(ContentBaseEntries $entries, string $type, string $label, string $seedFile, ?array $health): array
    {
        $rows = array_map(fn (array $r): array => $this->row($r), $entries->all($type));

        return [
            'label'     => $label,
            'seed_file' => $seedFile,
            'source_file' => $seedFile,
            'routine'   => $health,
            'activity'  => $entries->activity($type),
            'entries'   => $rows,
            'counts'    => $entries->counts($type),
        ];
    }

    /**
     * KB topics stay grouped per product with its daily cadence.
     *
     * @return array<string,mixed>
     */
    private function knowledgeSection(ContentBaseEntries $entries, ?array $health): array
    {
        $section  = $this->section($entries, 'knowledge_base', 'Knowledge base', 'knowledge-base/index.json', $health);
        $cadence  = $this->cadenceBySlug();
        $products = [];
        foreach ($section['entries'] as $row) {
            $products[(string) $row['product_slug']][] = $row;
        }

        $out = [];
        foreach ($products as $slug => $topics) {
            $counts = array_fill_keys(ContentBaseEntries::STATUSES, 0);
            foreach ($topics as $t) {
                $counts[$t['status']]++;
            }
            $out[] = [
                'product_slug' => $slug,
                'tier'         => (string) ($cadence[$slug]['tier'] ?? ''),
                'daily_quota'  => (int) ($cadence[$slug]['daily_quota'] ?? 0),
                'enabled'      => (bool) ($cadence[$slug]['enabled'] ?? false),
                'topics'       => $topics,
                'counts'       => ['total' => count($topics)] + $counts,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['enabled'] ? 0 : 1, -$a['daily_quota'], $a['product_slug']]
            <=> [$b['enabled'] ? 0 : 1, -$b['daily_quota'], $b['product_slug']]);
        $section['products'] = $out;

        return $section;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function row(array $r): array
    {
        return [
            'key'             => (string) $r['entry_key'],
            'content_base_key'=> (string) ($r['content_key'] ?: $r['entry_key']),
            'title'           => (string) $r['title'],
            'status'          => (string) $r['status'],
            'status_reason'   => $r['status_reason'],
            'origin'          => (string) $r['origin'],
            'target_date'     => (string) ($r['target_date'] ?? ''),
            'stream'          => (string) ($r['category'] ?? ''),
            'product_slug'    => (string) ($r['product_slug'] ?? ''),
            'prompt'          => (string) ($r['brief'] ?? ''),
            'attempts'        => (int) $r['attempts'],
            'content_item_id' => $r['content_item_id'] !== null ? (int) $r['content_item_id'] : null,
            'question_id'     => $r['community_question_id'] !== null ? (int) $r['community_question_id'] : null,
            'updated_at'      => $r['updated_at'],
            // Kept for older consoles that read sync.state.
            'sync'            => ['state' => (string) $r['status']],
        ];
    }

    /** @return array<string,mixed>|null the newest seed import that changed something */
    private function lastSeedImport(): ?array
    {
        try {
            return $this->db->table('reach_content_base_sync_runs')
                ->whereIn('file_key', ['seed_blog', 'seed_knowledge_base', 'seed_community', 'blog_index'])
                ->orderBy('id', 'DESC')->limit(1)
                ->get()->getRowArray() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, array<string,mixed>> */
    private function cadenceBySlug(): array
    {
        if (! $this->db->tableExists('reach_kb_product_cadence')) {
            return [];
        }

        $out = [];
        foreach ($this->db->table('reach_kb_product_cadence')->get()->getResultArray() as $row) {
            $out[(string) $row['product_slug']] = [
                'tier'        => $row['tier'],
                'daily_quota' => (int) $row['daily_quota'],
                'enabled'     => \App\Libraries\Support\PgBoolean::isTrue($row['enabled']),
            ];
        }

        return $out;
    }
}
