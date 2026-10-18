<?php

declare(strict_types=1);

namespace App\Libraries\Content\Quality;

use App\Libraries\Automation\Routine\LaunchApps;
use CodeIgniter\Database\BaseConnection;

/**
 * What the vetting model sees when Reach re-vets content that is already in
 * the database (the publish gate, reach:content-revet, the daily quality
 * sweep): the CURRENT version of a content item, in the same draft shape the
 * routine pipelines vet at intake, plus the title/summary/body text the cheap
 * heuristics read.
 */
final class VettableContent
{
    public const TYPES = ['blog', 'knowledge_base', 'release_note', 'workflow_guide'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array{rubric: string, draft: array<string, mixed>, version_id: int, title: string, summary: string, body: string}|null
     *         null when the item or its current version is missing
     */
    public function forItem(int $itemId): ?array
    {
        $item = $this->db->table('reach_content_items')->where('id', $itemId)->get()->getRowArray();
        if (! $item || ! in_array($item['content_type'], self::TYPES, true)) {
            return null;
        }
        $version = $this->db->table('reach_content_versions')->where('id', (int) ($item['current_version_id'] ?? 0))->get()->getRowArray()
            ?: $this->db->table('reach_content_versions')->where('content_item_id', $itemId)->orderBy('version_number', 'DESC')->limit(1)->get()->getRowArray();
        if (! $version) {
            return null;
        }

        $type    = (string) $item['content_type'];
        $title   = (string) ($version['title'] ?? $item['title'] ?? '');
        $summary = (string) ($version['summary'] ?? '');
        $body    = (string) ($version['body_html'] ?? '');

        if ($type === 'release_note' || $type === 'workflow_guide') {
            $payload = $version['structured_payload'] ?? null;
            $payload = is_string($payload) ? (json_decode($payload, true) ?: []) : (array) ($payload ?? []);
            $identity = $type === 'release_note' ? ['slug', 'product_slug', 'version', 'date_iso'] : ['product_slug', 'slug', 'image_url'];
            $draft    = array_diff_key($payload, array_flip($identity));
            $summary  = (string) ($payload['intro'] ?? $payload['blurb'] ?? $summary);
            $body     = $body !== '' ? $body : self::flatten($draft);

            return ['rubric' => $type, 'draft' => $draft, 'version_id' => (int) $version['id'], 'title' => $title, 'summary' => $summary, 'body' => $body];
        }

        $seo   = $this->db->table('reach_content_seo_profiles')->where('content_item_id', $itemId)->get()->getRowArray() ?? [];
        $draft = [
            'title'            => $title,
            'summary'          => $summary,
            'body_html'        => $body,
            'meta_title'       => (string) ($seo['meta_title'] ?? ''),
            'meta_description' => (string) ($seo['meta_description'] ?? ''),
        ];
        if ($type === 'blog') {
            $draft['slug'] = (string) ($seo['slug'] ?? $item['slug']);
        } else {
            $profile = $this->db->table('reach_kb_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray() ?? [];
            $decode  = static fn ($v): array => is_string($v) ? (json_decode($v, true) ?: []) : (array) ($v ?? []);
            $draft  += [
                'prerequisites'   => $decode($profile['prerequisites_json'] ?? null),
                'steps'           => $decode($profile['steps_json'] ?? null),
                'troubleshooting' => $decode($profile['troubleshooting_json'] ?? null),
            ];
        }

        // The heuristics read the meta title too: the live Aug-2026 posts carried
        // "Untitled draft" there.
        $summary = trim($summary . ' ' . (string) ($draft['meta_title'] ?? ''));

        return ['rubric' => $type, 'draft' => $draft, 'version_id' => (int) $version['id'], 'title' => $title, 'summary' => $summary, 'body' => $body];
    }

    /**
     * Facts the vetting model may rely on when it re-vets stored content.
     *
     * @param list<string> $heuristicFlags
     * @return array<string, mixed>
     */
    public function context(string $type, array $heuristicFlags = []): array
    {
        $products = [];
        try {
            foreach ($this->db->table('reach_products')->select('name, slug, short_description')
                ->where('deleted_at IS NULL', null, false)->whereIn('slug', LaunchApps::slugs())
                ->get()->getResultArray() as $p) {
                $products[] = array_filter($p, static fn ($v) => $v !== null && $v !== '');
            }
        } catch (\Throwable) {
        }

        return array_filter([
            'content_type'    => match ($type) {
                'blog'           => 'Marketing/educational blog for aicountly.com/blogs',
                'knowledge_base' => 'Help-centre article for aicountly.com/help',
                'release_note'   => 'aicountly.com "What\'s new" release note',
                default          => 'aicountly.com workflow guide',
            },
            'launch_apps'     => array_column(LaunchApps::APPS, 'name'),
            'products'        => $products,
            'review'          => 'This text is already stored in Reach (it may already be live). Judge the CURRENT text on its own merits: '
                . 'does the body deliver what the title promises, is it accurate, and is it free of text about how it was produced?',
            'heuristic_flags' => $heuristicFlags === [] ? null
                : 'Automatic checks flagged: ' . implode(', ', $heuristicFlags) . '. Confirm or dismiss each one in your reasons.',
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /** @param array<string, mixed> $value */
    private static function flatten(array $value): string
    {
        $out = [];
        array_walk_recursive($value, static function ($v) use (&$out): void {
            if (is_string($v)) {
                $out[] = $v;
            }
        });

        return implode("\n", $out);
    }
}
