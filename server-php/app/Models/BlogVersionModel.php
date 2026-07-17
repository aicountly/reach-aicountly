<?php

namespace App\Models;

use CodeIgniter\Model;

class BlogVersionModel extends Model
{
    protected $table         = 'reach_blog_versions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'blog_post_id', 'version', 'snapshot', 'changed_by', 'change_reason', 'created_at',
    ];

    protected $beforeInsert = ['encodeSnapshotForStorage'];

    public function forPost(int $blogPostId): array
    {
        $rows = $this->where('blog_post_id', $blogPostId)
            ->orderBy('version', 'DESC')
            ->findAll();

        foreach ($rows as &$row) {
            if (array_key_exists('snapshot', $row)) {
                $row['snapshot'] = self::decodeSnapshot($row['snapshot']);
            }
        }
        unset($row);

        return $rows;
    }

    public function findForPost(int $blogPostId, int $version): ?array
    {
        $row = $this->where('blog_post_id', $blogPostId)
            ->where('version', $version)
            ->first();

        if ($row === null) {
            return null;
        }

        if (array_key_exists('snapshot', $row)) {
            $row['snapshot'] = self::decodeSnapshot($row['snapshot']);
        }

        return $row;
    }

    public function latestVersionFor(int $blogPostId): int
    {
        $row = $this->where('blog_post_id', $blogPostId)
            ->orderBy('version', 'DESC')
            ->first();

        return (int) ($row['version'] ?? 0);
    }

    /**
     * PostgreSQL JSONB may arrive as a string or a decoded array. Legacy rows
     * may also be double-encoded from manual json_encode() at insert time.
     */
    public static function decodeSnapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
        }

        return is_array($decoded) ? $decoded : [];
    }

    protected function encodeSnapshotForStorage(array $eventData): array
    {
        $snapshot = $eventData['data']['snapshot'] ?? null;
        if (is_array($snapshot)) {
            $eventData['data']['snapshot'] = json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return $eventData;
    }
}
