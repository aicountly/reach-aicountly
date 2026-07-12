<?php

namespace App\Libraries;

use App\Models\Content\ContentVersionModel;
use App\Models\Content\ContentItemModel;

/**
 * Manages immutable version history for content items.
 *
 * Invariants:
 * - Versions are never updated once created
 * - Exactly one version per item has is_current = TRUE (enforced by partial unique index)
 * - Version numbers are sequential and protected against concurrent creation by a DB lock
 */
class ContentVersionService
{
    private ContentVersionModel $versions;
    private ContentItemModel    $items;

    public function __construct()
    {
        $this->versions = new ContentVersionModel();
        $this->items    = new ContentItemModel();
    }

    /**
     * Create a new version for a content item, atomically making it current.
     * Uses an advisory lock keyed on the content_item_id to prevent concurrent
     * version number collisions.
     */
    public function createVersion(int $contentItemId, array $body, array $actor, string $changeSummary = ''): array
    {
        $item = $this->items->find($contentItemId);
        if (!$item) {
            throw new \RuntimeException("Content item {$contentItemId} not found.");
        }

        $db = \Config\Database::connect();
        $db->transStart();

        // Advisory lock to prevent concurrent version number conflicts
        $db->query("SELECT pg_advisory_xact_lock(?)", [$contentItemId]);

        // Mark previous current version as not-current
        $db->table('reach_content_versions')
            ->where('content_item_id', $contentItemId)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        $versionNumber = $this->versions->nextVersionNumber($contentItemId);

        $versionId = $this->versions->insert([
            'content_item_id'    => $contentItemId,
            'version_number'     => $versionNumber,
            'title'              => $body['title'] ?? $item['title'],
            'summary'            => $body['summary'] ?? null,
            'body_html'          => $body['body_html'] ?? null,
            'body_markdown'      => $body['body_markdown'] ?? null,
            'body_plain_text'    => $body['body_plain_text'] ?? null,
            'structured_payload' => isset($body['structured_payload']) ? json_encode($body['structured_payload']) : null,
            'change_summary'     => $changeSummary,
            'is_current'         => true,
            'created_actor_type' => $actor['type'] ?? 'human',
            'created_by_user_id' => $actor['id'] ?? null,
            'created_by_service' => $actor['service'] ?? null,
            'created_at'         => date('Y-m-d H:i:s'),
        ], true);

        // Update pointer on parent item
        $db->table('reach_content_items')
            ->where('id', $contentItemId)
            ->update(['current_version_id' => $versionId, 'updated_at' => date('Y-m-d H:i:s')]);

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new \RuntimeException('Transaction failed creating version.');
        }

        return $this->versions->find($versionId);
    }

    public function getHistory(int $contentItemId): array
    {
        return $this->versions->allForItem($contentItemId);
    }

    public function getVersion(int $versionId): ?array
    {
        return $this->versions->find($versionId);
    }

    public function compare(int $versionAId, int $versionBId): array
    {
        $a = $this->versions->find($versionAId);
        $b = $this->versions->find($versionBId);

        if (!$a || !$b) {
            throw new \RuntimeException('One or both versions not found.');
        }

        return [
            'version_a'     => $a,
            'version_b'     => $b,
            'fields_changed' => $this->diffVersions($a, $b),
        ];
    }

    private function diffVersions(array $a, array $b): array
    {
        $fields  = ['title', 'summary', 'body_html', 'body_markdown', 'body_plain_text'];
        $changed = [];

        foreach ($fields as $f) {
            if (($a[$f] ?? null) !== ($b[$f] ?? null)) {
                $changed[] = $f;
            }
        }

        return $changed;
    }
}
