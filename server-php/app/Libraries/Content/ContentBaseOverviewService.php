<?php

declare(strict_types=1);

namespace App\Libraries\Content;

use App\Libraries\Blog\ContentBaseService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Surface-neutral read of the whole content base.
 *
 * The base in `server-php/content-base/` has always covered three surfaces —
 * blog, knowledge base and community Q&A — but the only console view of it
 * was a tab inside the Blog Command Centre roadmap, so it read as a blog
 * artefact. The knowledge-base index was returned raw and the community seeds
 * were not surfaced at all: an operator could not answer "what has Claude got
 * queued for the KB?" without opening git.
 *
 * This service answers that question for every surface in one shape, each
 * entry carrying the same `sync` verdict so the console can show one table per
 * surface instead of three bespoke ones.
 *
 * Sync keys differ per surface because each pipeline owns its own inbox:
 *   blog      — reach_topic_candidates.content_base_key → roadmap → content item
 *   knowledge — reach_content_items.content_base_key (content_type=knowledge_base)
 *   community — reach_community_questions.external_question_id
 *
 * Read-only by construction. The files are git-owned and rsynced on deploy
 * with `--delete`; anything written here would be destroyed by the next
 * deploy, so nothing writes.
 */
class ContentBaseOverviewService
{
    /** Entries whose status marks them as no longer wanted. */
    private const RETIRED = ['retired', 'archived', 'dropped'];

    private BaseConnection $db;
    private ContentBaseService $base;

    public function __construct(?BaseConnection $db = null, ?ContentBaseService $base = null)
    {
        $this->db   = $db ?? Database::connect();
        $this->base = $base ?? new ContentBaseService($this->db);
    }

    /**
     * The whole base, one section per surface.
     *
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        $blog      = $this->blogSection();
        $knowledge = $this->knowledgeSection();
        $community = $this->communitySection();

        return [
            'base_path'     => $this->base->basePath(),
            'base_markdown' => $this->base->baseMarkdown(),
            'last_sync'     => $this->base->lastSyncRun(),
            'surfaces'      => [
                'blog'           => $blog,
                'knowledge_base' => $knowledge,
                'community'      => $community,
            ],
            'totals' => [
                'entries' => $blog['counts']['total'] + $knowledge['counts']['total'] + $community['counts']['total'],
                'pending' => $blog['counts']['pending'] + $knowledge['counts']['pending'] + $community['counts']['pending'],
                'done'    => $blog['counts']['done'] + $knowledge['counts']['done'] + $community['counts']['done'],
            ],
        ];
    }

    // ----------------------------------------------------------------- blog

    /** @return array<string,mixed> */
    private function blogSection(): array
    {
        $index   = $this->base->blogIndex();
        $entries = [];

        foreach ((array) ($index['entries'] ?? []) as $entry) {
            $key       = trim((string) ($entry['key'] ?? ''));
            $candidate = $key !== ''
                ? $this->db->table('reach_topic_candidates')
                    ->select('id, status, content_item_id, updated_at')
                    ->where('content_base_key', $key)
                    ->get()->getRowArray()
                : null;

            $entries[] = [
                'key'          => $key,
                'title'        => (string) ($entry['title'] ?? ''),
                'status'       => (string) ($entry['status'] ?? 'planned'),
                'target_date'  => (string) ($entry['target_date'] ?? ''),
                'stream'       => (string) ($entry['portfolio_stream'] ?? ''),
                'product_slug' => (string) ($entry['product_slug'] ?? ''),
                'prompt'       => (string) ($entry['brief_prompt'] ?? ''),
                'sync'         => $this->verdict(
                    retired: $this->isRetired((string) ($entry['status'] ?? '')),
                    produced: $candidate !== null && ! empty($candidate['content_item_id']),
                    tracked: $candidate !== null,
                    detail: $candidate ? ['candidate_status' => $candidate['status'], 'content_item_id' => $candidate['content_item_id']] : [],
                ),
            ];
        }

        return [
            'label'       => 'Blog',
            'source_file' => 'blog/index.json',
            'meta'        => $this->meta($index),
            'entries'     => $entries,
            'counts'      => $this->counts($entries),
        ];
    }

    // ------------------------------------------------------------ knowledge

    /**
     * KB topics are grouped per product, and each product carries its own
     * daily quota, so the section keeps that grouping rather than flattening
     * it into a topic list that loses the cadence.
     *
     * @return array<string,mixed>
     */
    private function knowledgeSection(): array
    {
        $index    = $this->base->knowledgeBaseIndex();
        $cadence  = $this->cadenceBySlug();
        $entries  = [];
        $products = [];

        foreach ((array) ($index['products'] ?? []) as $product) {
            $slug   = trim((string) ($product['product_slug'] ?? ''));
            $topics = [];

            foreach ((array) ($product['topics'] ?? []) as $topic) {
                $key  = trim((string) ($topic['key'] ?? ''));
                $item = $key !== ''
                    ? $this->db->table('reach_content_items')
                        ->select('id, workflow_status, publication_status')
                        ->where('content_base_key', $key)
                        ->where('deleted_at IS NULL', null, false)
                        ->get()->getRowArray()
                    : null;

                $row = [
                    'key'          => $key,
                    'title'        => (string) ($topic['title'] ?? ''),
                    'status'       => (string) ($topic['status'] ?? 'planned'),
                    'target_date'  => (string) ($topic['target_date'] ?? ''),
                    'stream'       => $slug,
                    'product_slug' => $slug,
                    'prompt'       => (string) ($topic['brief_prompt'] ?? $topic['prompt'] ?? ''),
                    'sync'         => $this->verdict(
                        retired: $this->isRetired((string) ($topic['status'] ?? '')),
                        produced: $item !== null,
                        tracked: $item !== null,
                        detail: $item ? ['content_item_id' => (int) $item['id'], 'workflow_status' => $item['workflow_status']] : [],
                    ),
                ];

                $topics[]  = $row;
                $entries[] = $row;
            }

            $products[] = [
                'product_slug' => $slug,
                'tier'         => (string) ($cadence[$slug]['tier'] ?? $product['tier'] ?? ''),
                'daily_quota'  => (int) ($cadence[$slug]['daily_quota'] ?? 0),
                'enabled'      => (bool) ($cadence[$slug]['enabled'] ?? false),
                'topics'       => $topics,
                'counts'       => $this->counts($topics),
            ];
        }

        return [
            'label'       => 'Knowledge base',
            'source_file' => 'knowledge-base/index.json',
            'meta'        => $this->meta($index),
            'products'    => $products,
            'entries'     => $entries,
            'counts'      => $this->counts($entries),
        ];
    }

    // ------------------------------------------------------------ community

    /** @return array<string,mixed> */
    private function communitySection(): array
    {
        $seeds   = $this->base->communityQuestionSeeds();
        $entries = [];

        foreach ((array) ($seeds['seeds'] ?? []) as $seed) {
            $key      = trim((string) ($seed['key'] ?? ''));
            $question = $key !== ''
                ? $this->db->table('reach_community_questions')
                    ->select('id, status, category')
                    ->where('external_question_id', $key)
                    ->get()->getRowArray()
                : null;

            $entries[] = [
                'key'          => $key,
                'title'        => (string) ($seed['question'] ?? ''),
                'status'       => (string) ($seed['status'] ?? 'planned'),
                'target_date'  => (string) ($seed['target_date'] ?? ''),
                'stream'       => (string) ($seed['category'] ?? ''),
                'product_slug' => (string) ($seed['product_slug'] ?? ''),
                'prompt'       => (string) ($seed['context'] ?? ''),
                'sync'         => $this->verdict(
                    retired: $this->isRetired((string) ($seed['status'] ?? '')),
                    produced: $question !== null,
                    tracked: $question !== null,
                    detail: $question ? ['question_id' => (int) $question['id'], 'question_status' => $question['status']] : [],
                ),
            ];
        }

        return [
            'label'       => 'Community Q&A',
            'source_file' => 'community/question-seeds.json',
            'meta'        => $this->meta($seeds),
            'entries'     => $entries,
            'counts'      => $this->counts($entries),
        ];
    }

    // -------------------------------------------------------------- helpers

    /**
     * One vocabulary for "where is this entry up to", so the console renders
     * the same badge on every surface.
     *
     * @param array<string,mixed> $detail
     * @return array<string,mixed>
     */
    private function verdict(bool $retired, bool $produced, bool $tracked, array $detail): array
    {
        $state = match (true) {
            $retired  => 'retired',
            $produced => 'produced',
            $tracked  => 'queued',
            default   => 'pending_sync',
        };

        return ['state' => $state] + $detail;
    }

    private function isRetired(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::RETIRED, true);
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string,int>
     */
    private function counts(array $entries): array
    {
        $counts = ['total' => count($entries), 'produced' => 0, 'queued' => 0, 'pending_sync' => 0, 'retired' => 0];

        foreach ($entries as $entry) {
            $state = (string) ($entry['sync']['state'] ?? 'pending_sync');
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        $counts['done']    = $counts['produced'];
        $counts['pending'] = $counts['queued'] + $counts['pending_sync'];

        return $counts;
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    private function meta(array $file): array
    {
        return [
            'version'    => $file['version'] ?? null,
            'updated_at' => $file['updated_at'] ?? null,
            'notes'      => $file['notes'] ?? null,
            'readable'   => $file !== [],
        ];
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
                'enabled'     => (bool) $row['enabled'],
            ];
        }

        return $out;
    }
}
