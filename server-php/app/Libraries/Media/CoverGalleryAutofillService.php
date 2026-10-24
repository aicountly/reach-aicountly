<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use App\Libraries\Ai\Images\CoverPromptBuilder;
use App\Libraries\Automation\Routine\RoutineFlags;
use App\Libraries\Blog\CoverSweepService;
use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Keeps the Cover Gallery stocked without a human (job reach.cover_gallery_autofill,
 * hourly on the `blog` queue).
 *
 * Same rule as the deficit report it replaces as a call to action: upcoming
 * blog-index entries in the next MEDIA_GALLERY_LOOKAHEAD_DAYS versus active,
 * matchable gallery covers. When there is a deficit, Reach generates covers
 * for the entries that have none — earliest target date first — from each
 * entry's own title, brief and scene note (CoverPromptBuilder: flat vector,
 * green/white brand palette, no text), brands them with the Aicountly mark and
 * stores them as active `ai_gallery` assets tagged to the entry (source_key)
 * and its keywords. The article written from that entry later finds its cover
 * by key.
 *
 * Cost rules
 *   - never more than min(deficit, covers.auto.daily_limit − generated today, per-run cap);
 *   - idempotent per entry: an entry with a stocked cover (any status) or whose
 *     article already has a cover is never generated again (unique index on
 *     source_key backs this up);
 *   - stops at the first "not configured" answer and after two failures in a run.
 *
 * Covers that land release parked articles straight away (CoverSweepService).
 */
class CoverGalleryAutofillService
{
    private BaseConnection $db;
    private CoverGenerator $generator;
    private MediaGalleryDeficitService $deficit;

    public function __construct(?CoverGenerator $generator = null, ?BaseConnection $db = null, ?MediaGalleryDeficitService $deficit = null, private readonly ?\Closure $sweep = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->generator = $generator ?? new CoverGenerator(null, $this->db);
        $this->deficit   = $deficit ?? new MediaGalleryDeficitService($this->db);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(int $perRunCap = 5): array
    {
        $flag = RoutineFlags::explain('covers.auto');
        if (! $flag['enabled']) {
            return [
                'status' => 'skipped',
                'reason' => $flag['source'] === 'env_kill_switch'
                    ? "{$flag['kill_switch']}=false in the server .env"
                    : 'covers.auto.enabled is off (Settings → AI & Automation → Images)',
                'generated' => 0,
            ];
        }

        $report  = $this->deficit->report();
        $summary = ['deficit' => $report['deficit'], 'needed' => $report['needed'], 'available' => $report['available']];
        if ($report['deficit'] <= 0) {
            return ['status' => 'ok', 'reason' => 'no_deficit', 'generated' => 0] + $summary;
        }

        $remaining = $this->generator->remainingToday();
        if ($remaining <= 0) {
            return ['status' => 'capped', 'reason' => 'covers.auto.daily_limit reached (' . CoverGenerator::dailyLimit() . ' today)', 'generated' => 0] + $summary;
        }

        $budget  = min($report['deficit'], $remaining, max(1, $perRunCap));
        $entries = $this->entriesNeedingCovers($report['upcoming']);

        $generated = [];
        $failures  = [];
        foreach ($entries as $entry) {
            if (count($generated) >= $budget) {
                break;
            }
            try {
                $asset = $this->generator->generate($this->prompt($entry), [
                    'source_key'       => $entry['key'],
                    'category_tags'    => $this->tags($entry),
                    'portfolio_stream' => $entry['stream'] !== '' ? $entry['stream'] : null,
                ], CoverGenerator::PURPOSE_GALLERY);
                $generated[] = ['key' => $entry['key'], 'asset_id' => (int) $asset['id'], 'branded' => MediaAssetStore::isBranded($asset)];
            } catch (CoverGenerationException $e) {
                $failures[] = ['key' => $entry['key'], 'kind' => $e->kind, 'error' => mb_substr($e->getMessage(), 0, 300)];
                if ($e->kind === CoverGenerationException::NOT_CONFIGURED || count($failures) >= 2) {
                    break;
                }
            }
        }

        $released = null;
        if ($generated !== []) {
            try {
                $released = $this->sweep !== null ? ($this->sweep)() : (new CoverSweepService($this->db))->sweep(25);
            } catch (\Throwable $e) {
                log_message('error', '[CoverAutofill] cover sweep after stocking failed: ' . $e->getMessage());
            }
        }

        return [
            'status'           => $failures !== [] && $generated === [] ? 'failed' : 'ok',
            'generated'        => count($generated),
            'covers'           => $generated,
            'failures'         => $failures,
            'entries_eligible' => count($entries),
            'budget'           => $budget,
            'sweep'            => $released,
        ] + $summary;
    }

    /**
     * Upcoming entries with no stocked cover and no article cover yet.
     *
     * @param list<array<string, mixed>> $upcoming
     * @return list<array<string, mixed>>
     */
    public function entriesNeedingCovers(array $upcoming): array
    {
        $keys = array_values(array_filter(array_map(static fn ($e) => trim((string) ($e['key'] ?? '')), $upcoming)));
        if ($keys === []) {
            return [];
        }

        $stocked = array_column($this->db->table('reach_media_gallery_assets')
            ->select('source_key')
            ->where('kind', 'ai_gallery')
            ->whereIn('source_key', $keys)
            ->get()->getResultArray(), 'source_key');

        $covered = [];
        if (SchemaGuard::hasColumn($this->db, 'reach_content_items', 'content_base_key')) {
            $covered = array_column($this->db->query(
                "SELECT i.content_base_key FROM reach_content_items i
                 JOIN reach_blog_publication_profiles p ON p.content_item_id = i.id
                 WHERE i.content_base_key IN ? AND COALESCE(p.featured_image_reference, '') <> ''",
                [$keys]
            )->getResultArray(), 'content_base_key');
        }

        $skip = array_flip(array_merge($stocked, $covered));
        $out  = [];
        foreach ($upcoming as $entry) {
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key === '' || isset($skip[$key]) || trim((string) ($entry['title'] ?? '')) === '') {
                continue;
            }
            $out[] = $entry + ['stream' => '', 'brief' => '', 'cover_prompt' => '', 'target_date' => ''];
        }
        usort($out, static fn ($a, $b) => [($a['target_date'] ?: '9999'), $a['key']] <=> [($b['target_date'] ?: '9999'), $b['key']]);

        return $out;
    }

    /** @param array<string, mixed> $entry */
    public function prompt(array $entry): string
    {
        // The portfolio stream is not the theme: "marketing" is an editorial
        // bucket and would put megaphones on a GST article.
        return (new CoverPromptBuilder())->build(
            (string) $entry['title'],
            (string) ($entry['brief'] ?? ''),
            null,
            (string) ($entry['cover_prompt'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function tags(array $entry): array
    {
        $keywords = (new CoverRelevanceScorer())->articleKeywords(
            (string) $entry['title'],
            (string) ($entry['product_slug'] ?? ''),
            array_map('strval', (array) ($entry['suggested_tags'] ?? [])),
            (string) ($entry['seed_keyword'] ?? ''),
        );

        return array_slice($keywords, 0, 8);
    }
}
