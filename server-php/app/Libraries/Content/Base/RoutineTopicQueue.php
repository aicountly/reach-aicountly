<?php

declare(strict_types=1);

namespace App\Libraries\Content\Base;

use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * GET v1/automation/content-base/next — the one queue a routine reads to know
 * what to write next.
 *
 * Blog order:
 *   1. regeneration — published articles Reach wants rewritten. Supplied by an
 *      optional provider class (see REGENERATION_PROVIDER); absent → none.
 *   2. roadmap      — topics the roadmap optimiser selected (CREATE_NEW) that
 *      have no article yet, highest score first.
 *   3. content_base — planned content-base topics (git seeds and routine
 *      proposals), by priority and target date.
 * KB and community: planned content-base topics only.
 *
 * Every item carries the `content_base_key` the routine must submit under, so
 * Reach can link the submission back and move the topic along by itself.
 */
final class RoutineTopicQueue
{
    /**
     * Contract for the regeneration stream: a class with
     *   public function forNextFeed(string $type, int $limit): list<array{key, content_base_key, title, brief?, ...}>
     * Items it returns are passed through with kind "regeneration".
     */
    public const REGENERATION_PROVIDER = '\\App\\Libraries\\Automation\\Routine\\RegenerationQueue';

    private BaseConnection $db;
    private ContentBaseEntries $entries;

    public function __construct(?BaseConnection $db = null, ?ContentBaseEntries $entries = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->entries = $entries ?? new ContentBaseEntries($this->db);
    }

    /** @return array<string,mixed> */
    public function next(string $type, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $items = [];

        if ($type === 'blog') {
            foreach ($this->regeneration($limit) as $item) {
                $items[] = $item;
            }
            $seen = [];
            foreach ($this->roadmapSelected($limit) as $item) {
                $seen[$item['key']] = true;
                $items[] = $item;
            }
            foreach ($this->entries->planned('blog', $limit * 2) as $item) {
                if (! isset($seen[$item['key']]) && ! isset($seen[$item['content_base_key']])) {
                    $items[] = $item;
                }
            }
        } else {
            $items = $this->entries->planned($type, $limit);
        }

        $items = array_slice($items, 0, $limit);
        $counts = $this->entries->counts($type);

        return [
            'type'         => $type,
            'generated_at' => gmdate('c'),
            'items'        => $items,
            'counts'       => $counts,
            'activity'     => $this->entries->activity($type),
            'rules'        => [
                'Take items in the order given. Submit each under its content_base_key exactly.',
                'After submitting, POST v1/automation/content-base/produced {type, key, outcome:"submitted", content_base_key}.',
                'If the product does not do what a topic assumes, do not write it: POST …/produced {type, key, outcome:"not_applicable", reason}.',
                'Describe only features you can see in the product repository (code and git log). Never invent features, screens, labels, prices or dates.',
                'When this list is short, propose new topics from real product changes with POST v1/automation/content-base/propose.',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function regeneration(int $limit): array
    {
        $class = self::REGENERATION_PROVIDER;
        if (! class_exists($class) || ! method_exists($class, 'forNextFeed')) {
            return [];
        }
        try {
            $out = [];
            foreach ((array) (new $class())->forNextFeed('blog', $limit) as $item) {
                if (is_array($item) && ! empty($item['content_base_key'])) {
                    $out[] = ['kind' => 'regeneration'] + $item;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            log_message('error', '[RoutineTopicQueue] regeneration provider failed: ' . $e->getMessage());

            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function roadmapSelected(int $limit): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_topic_candidates')) {
            return [];
        }

        $rows = $this->db->table('reach_topic_candidates tc')
            ->select('tc.id, tc.content_base_key, tc.title, tc.slug_hint, tc.portfolio_stream, tc.funnel_stage, tc.search_intent, tc.seed_keyword, tc.audience, tc.notes, ts.total_score, ts.scored_for_date')
            ->join('reach_topic_scores ts', 'ts.topic_candidate_id = tc.id AND ts.is_current = TRUE', 'left', false)
            ->where('tc.status', 'roadmap_selected')
            ->where('tc.content_item_id IS NULL', null, false)
            ->orderBy('ts.total_score DESC NULLS LAST', '', false)
            ->orderBy('tc.id', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['content_base_key'] ?? '');
            if ($key === '') {
                // Candidates discovered from topic clusters carry no key; give
                // them one so the routine's submission links back.
                $key = 'roadmap-' . $row['id'];
                $this->db->table('reach_topic_candidates')->where('id', $row['id'])->where('content_base_key IS NULL', null, false)
                    ->update(['content_base_key' => $key]);
            }
            $entry = $this->entries->find('blog', $key);
            if ($entry !== null && $entry['status'] !== 'planned') {
                continue;
            }
            $notes = (string) ($row['notes'] ?? '');
            preg_match('/\[brief\]\s*(.+)/', $notes, $brief);
            preg_match('/\[product\]\s*(\S+)/', $notes, $product);

            $out[] = [
                'kind'             => 'roadmap',
                'key'              => $key,
                'content_base_key' => $entry !== null ? (string) ($entry['content_key'] ?: $key) : $key,
                'title'            => (string) $row['title'],
                'product_slug'     => $entry['product_slug'] ?? ($product[1] ?? null),
                'category'         => $row['portfolio_stream'],
                'taxonomy'         => \App\Libraries\Blog\BlogTaxonomy::queueHints(
                    $row['portfolio_stream'] ?? null,
                    $entry['product_slug'] ?? ($product[1] ?? null),
                    $entry !== null
                        ? (array) (json_decode((string) ($entry['payload_json'] ?? '{}'), true) ?: []) + ['topic_category' => $entry['category'] ?? null]
                        : [],
                ),
                'brief'            => $entry['brief'] ?? ($brief[1] ?? null),
                'target_date'      => $entry['target_date'] ?? null,
                'origin'           => $entry['origin'] ?? 'roadmap',
                'status'           => 'planned',
                'score'            => $row['total_score'] !== null ? (float) $row['total_score'] : null,
                'scored_for_date'  => $row['scored_for_date'],
                'details'          => array_filter([
                    'slug_hint'     => $row['slug_hint'],
                    'funnel_stage'  => $row['funnel_stage'],
                    'search_intent' => $row['search_intent'],
                    'seed_keyword'  => $row['seed_keyword'],
                    'audience'      => $row['audience'],
                ]),
            ];
        }

        return $out;
    }
}
