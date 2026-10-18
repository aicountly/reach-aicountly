<?php

declare(strict_types=1);

namespace App\Libraries\Content\Quality;

use App\Libraries\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * reach_content_regenerations — blogs / KB articles whose current text failed
 * vetting and must be rewritten on the same topic and the same slug.
 *
 * The blog and KB routines call GET v1/automation/regenerate/next first; it
 * claims the oldest queued item for two hours and hands back its title, slug
 * and keyword. The routine submits the rewrite with `regeneration_id`
 * through the ordinary blog-drafts / kb-drafts endpoint
 * (RegenerationIngestService), so it is vetted, approved, covered and
 * published like any routine article — at the same URL.
 */
class RegenerationQueue
{
    public const LEASE_MINUTES = 120;
    public const MAX_CLAIMS    = 4;
    public const OPEN          = ['queued', 'claimed', 'submitted'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Queue an item (no-op when it already has an open row).
     *
     * @param list<string> $reasons
     * @return int|null the regeneration id (existing or new); null for an unsupported type
     */
    public function enqueue(int $itemId, array $reasons, bool $wasLive, ?int $qualityCheckId = null): ?int
    {
        $item = $this->db->table('reach_content_items')->where('id', $itemId)->get()->getRowArray();
        if (! $item || ! in_array($item['content_type'], ['blog', 'knowledge_base'], true)) {
            return null;
        }
        $open = $this->openForItem($itemId);
        if ($open !== null) {
            return (int) $open['id'];
        }

        $seo     = $this->db->table('reach_content_seo_profiles')->where('content_item_id', $itemId)->get()->getRowArray() ?? [];
        $product = null;
        if ($item['content_type'] === 'knowledge_base') {
            $product = $this->db->table('reach_kb_publication_profiles p')->select('pr.slug')
                ->join('reach_products pr', 'pr.id = p.product_id', 'left')
                ->where('p.content_item_id', $itemId)->get()->getRowArray()['slug'] ?? null;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_content_regenerations')->insert([
            'content_item_id'     => $itemId,
            'content_type'        => $item['content_type'],
            'title'               => mb_substr((string) $item['title'], 0, 300),
            'slug'                => (string) $item['slug'],
            'primary_keyword'     => isset($seo['primary_keyword']) ? mb_substr((string) $seo['primary_keyword'], 0, 255) : null,
            'product_slug'        => $product,
            'reasons'             => json_encode(array_values(array_slice($reasons, 0, 12)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'              => 'queued',
            'was_live'            => $wasLive ? 't' : 'f',
            'replaced_version_id' => $item['current_version_id'] ?? null,
            'quality_check_id'    => $qualityCheckId,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $id = (int) $this->db->insertID();
        AuditLogger::record('content.regeneration_queued', ['content_item_id' => $itemId, 'regeneration_id' => $id, 'was_live' => $wasLive]);

        return $id;
    }

    /**
     * Claim the next item for a routine run, or null when the queue is empty.
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(string $contentType): ?array
    {
        $now   = date('Y-m-d H:i:s');
        $until = date('Y-m-d H:i:s', time() + self::LEASE_MINUTES * 60);
        $row   = $this->db->query(
            "UPDATE reach_content_regenerations SET status = 'claimed', claimed_until = ?, claim_count = claim_count + 1, updated_at = ?
             WHERE id = (
                 SELECT id FROM reach_content_regenerations
                 WHERE content_type = ? AND claim_count < ?
                   AND (status = 'queued' OR (status = 'claimed' AND claimed_until < ?))
                 ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED
             )
             RETURNING *",
            [$until, $now, $contentType, self::MAX_CLAIMS, $now]
        )->getRowArray();

        return $row ? $this->forRoutine($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->table('reach_content_regenerations')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function openForItem(int $itemId): ?array
    {
        return $this->db->table('reach_content_regenerations')->where('content_item_id', $itemId)
            ->whereIn('status', self::OPEN)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray() ?: null;
    }

    public function markSubmitted(int $id, int $submissionId, int $newVersionId): void
    {
        $this->db->table('reach_content_regenerations')->where('id', $id)->update([
            'status' => 'submitted', 'submission_id' => $submissionId, 'new_version_id' => $newVersionId,
            'claimed_until' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** The replacement is live: close the item's open regeneration. */
    public function markLiveForItem(int $itemId): void
    {
        $open = $this->openForItem($itemId);
        if ($open === null) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_content_regenerations')->where('id', $open['id'])
            ->update(['status' => 'live', 'completed_at' => $now, 'updated_at' => $now]);
        AuditLogger::record('content.regeneration_live', ['content_item_id' => $itemId, 'regeneration_id' => (int) $open['id']]);
    }

    /**
     * The rewrite was itself rejected by vetting: offer the topic again, up
     * to MAX_CLAIMS rewrites, then give up (cancelled, reasons kept).
     *
     * @param list<string> $reasons
     */
    public function requeueAfterRejection(int $itemId, array $reasons): void
    {
        $open = $this->openForItem($itemId);
        if ($open === null || $open['status'] !== 'submitted') {
            return;
        }
        $giveUp = (int) $open['claim_count'] >= self::MAX_CLAIMS;
        $merged = array_values(array_unique([...((array) json_decode((string) $open['reasons'], true)), ...array_slice($reasons, 0, 6)]));
        $this->db->table('reach_content_regenerations')->where('id', $open['id'])->update([
            'status'        => $giveUp ? 'cancelled' : 'queued',
            'reasons'       => json_encode(array_slice($merged, -12), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'submission_id' => null,
            'claimed_until' => null,
            'completed_at'  => $giveUp ? date('Y-m-d H:i:s') : null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, int> open rows by status, for the daily report */
    public function counts(): array
    {
        $out = ['queued' => 0, 'claimed' => 0, 'submitted' => 0];
        try {
            foreach ($this->db->table('reach_content_regenerations')->select('status, COUNT(*) AS n')
                ->whereIn('status', self::OPEN)->groupBy('status')->get()->getResultArray() as $r) {
                $out[$r['status']] = (int) $r['n'];
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function forRoutine(array $row): array
    {
        $isBlog = $row['content_type'] === 'blog';
        $item   = $this->db->table('reach_content_items')->select('content_base_key')->where('id', $row['content_item_id'])->get()->getRowArray() ?? [];

        return [
            'regeneration_id'  => (int) $row['id'],
            'content_type'     => $row['content_type'],
            'title'            => $row['title'],
            'slug'             => $row['slug'],
            'primary_keyword'  => $row['primary_keyword'],
            'product_slug'     => $row['product_slug'],
            'content_base_key' => $item['content_base_key'] ?? null,
            'why'              => (array) json_decode((string) $row['reasons'], true),
            'claimed_until'    => $row['claimed_until'],
            'submit'           => [
                'endpoint' => $isBlog ? 'POST /api/v1/automation/blog-drafts' : 'POST /api/v1/automation/kb-drafts',
                'include'  => ['regeneration_id' => (int) $row['id']],
            ],
            'rules' => [
                'Write a completely new article on exactly this title\'s topic — do not reuse the failed text.',
                'Keep the topic and primary keyword. You may sharpen the title wording; the slug is fixed and is ignored if you send one.',
                'Send "regeneration_id" with the normal submission fields; it replaces this run\'s new article and counts against today\'s quota.',
                'The article goes through the normal vetting and is republished at the same URL.',
            ],
        ];
    }
}
