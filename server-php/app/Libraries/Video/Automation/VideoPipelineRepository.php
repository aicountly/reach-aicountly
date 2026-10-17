<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Persistence for reach_video_pipeline_items / _assets. JSONB columns are
 * decoded on read and encoded on write so callers only see arrays.
 */
class VideoPipelineRepository
{
    private const JSON_COLUMNS = ['submitted_json', 'script_json', 'warnings_json', 'vetting_reasons_json', 'vetting_run_ids_json', 'social_post_ids_json', 'render_plan_json'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function db(): BaseConnection
    {
        return $this->db;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->decode($this->db->table('reach_video_pipeline_items')->where('id', $id)->get()->getRowArray() ?: null);
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
            return null;
        }
        return $this->decode($this->db->table('reach_video_pipeline_items')->where('uuid', $uuid)->get()->getRowArray() ?: null);
    }

    /** @return array<string, mixed>|null */
    public function findByExternalKey(string $key): ?array
    {
        return $this->decode($this->db->table('reach_video_pipeline_items')->where('external_key', $key)->get()->getRowArray() ?: null);
    }

    /** @return array<string, mixed>|null */
    public function findByRenderId(string $renderId): ?array
    {
        return $this->decode($this->db->table('reach_video_pipeline_items')->where('render_id', $renderId)->get()->getRowArray() ?: null);
    }

    /**
     * @param array<string, mixed> $script
     * @param array<string, mixed> $submitted
     */
    public function create(array $script, array $submitted): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('reach_video_pipeline_items')->insert($this->encode([
            'external_key'   => $script['external_key'],
            'format'         => $script['format'],
            'title'          => $script['title'],
            'product_slug'   => $script['product_slug'],
            'submitted_json' => $submitted,
            'script_json'    => $script,
            'status'         => 'pending_vetting',
            'step'           => 'vet',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]));
        return (int) $this->db->insertID();
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->db->table('reach_video_pipeline_items')->where('id', $id)->update($this->encode($fields));
    }

    public function addWarning(int $id, string $warning): void
    {
        $item = $this->find($id);
        if ($item === null) {
            return;
        }
        $warnings   = (array) $item['warnings_json'];
        $warnings[] = mb_substr($warning, 0, 500);
        $this->update($id, ['warnings_json' => array_values(array_unique($warnings))]);
    }

    /**
     * Today's submissions for a format (IST calendar day), rejected ones excluded.
     */
    public function countToday(string $format, bool $includeRejected = false): int
    {
        [$from, $to] = self::istDayBoundsUtc();
        $q = $this->db->table('reach_video_pipeline_items')
            ->where('format', $format)
            ->where('created_at >=', $from)
            ->where('created_at <', $to);
        if (! $includeRejected) {
            $q->where('status !=', 'rejected');
        }
        return $q->countAllResults();
    }

    /** @return array{0: string, 1: string} UTC bounds of today's IST date */
    public static function istDayBoundsUtc(?\DateTimeImmutable $now = null): array
    {
        $ist  = new \DateTimeZone('Asia/Kolkata');
        $now  = ($now ?? new \DateTimeImmutable('now'))->setTimezone($ist);
        $from = $now->setTime(0, 0);
        $to   = $from->modify('+1 day');
        $utc  = new \DateTimeZone('UTC');
        return [$from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /**
     * @param array{status?: string, format?: string} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $q = $this->db->table('reach_video_pipeline_items');
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['format'])) {
            $q->where('format', $filters['format']);
        }
        $total = (clone $q)->countAllResults(false);
        $rows  = $q->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        return ['items' => array_map(fn ($r) => $this->decode($r), $rows), 'total' => $total];
    }

    /** @return list<array{title: string, format: string, status: string, created_at: string}> */
    public function recentTitles(int $limit = 30): array
    {
        return $this->db->table('reach_video_pipeline_items')
            ->select('title, format, status, created_at, youtube_url')
            ->orderBy('created_at', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function assets(int $itemId, ?string $kind = null): array
    {
        $q = $this->db->table('reach_video_pipeline_assets')->where('item_id', $itemId);
        if ($kind !== null) {
            $q->where('kind', $kind);
        }
        return $q->orderBy('kind')->orderBy('scene_order')->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function asset(int $itemId, string $kind, int $order): ?array
    {
        return $this->db->table('reach_video_pipeline_assets')
            ->where(['item_id' => $itemId, 'kind' => $kind, 'scene_order' => $order])
            ->get()->getRowArray() ?: null;
    }

    /** Create the asset row if it does not exist yet (status pending). */
    public function ensureAsset(int $itemId, string $kind, int $order, string $source, string $status = 'pending', ?string $detail = null): array
    {
        $existing = $this->asset($itemId, $kind, $order);
        if ($existing !== null) {
            return $existing;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('reach_video_pipeline_assets')->insert([
            'item_id' => $itemId, 'kind' => $kind, 'scene_order' => $order, 'source' => $source,
            'status' => $status, 'detail' => $detail, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return (array) $this->asset($itemId, $kind, $order);
    }

    /** @param array<string, mixed> $fields */
    public function updateAsset(int $assetId, array $fields): void
    {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->db->table('reach_video_pipeline_assets')->where('id', $assetId)->update($fields);
    }

    public function resetAssets(int $itemId): void
    {
        $this->db->table('reach_video_pipeline_assets')->where('item_id', $itemId)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (self::JSON_COLUMNS as $col) {
            if (array_key_exists($col, $row) && ! is_array($row[$col])) {
                $decoded   = json_decode((string) ($row[$col] ?? ''), true);
                $row[$col] = is_array($decoded) ? $decoded : [];
            }
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function encode(array $fields): array
    {
        foreach (self::JSON_COLUMNS as $col) {
            if (array_key_exists($col, $fields) && is_array($fields[$col])) {
                $fields[$col] = json_encode($fields[$col], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $fields;
    }
}
