<?php

namespace App\Libraries;

use App\Models\Content\ContentItemModel;
use App\Models\Content\ContentVersionModel;
use App\Models\Content\ContentBriefModel;

/**
 * Core service for creating and updating content items.
 *
 * Rules enforced here (not in controllers):
 * - Slug uniqueness
 * - Initial version created with every new item
 * - Editing an approved item creates a new version and reverts to draft
 * - Phase 1 FK references validated on create/update
 */
class ContentItemService
{
    private ContentItemModel   $items;
    private ContentVersionModel $versions;
    private ContentBriefModel  $briefs;
    private AuditLogger        $audit;

    public function __construct()
    {
        $this->items    = new ContentItemModel();
        $this->versions = new ContentVersionModel();
        $this->briefs   = new ContentBriefModel();
        $this->audit    = new AuditLogger();
    }

    /**
     * Create a new content item with its first version.
     *
     * @param array $data        Item fields
     * @param array $versionData Initial version body fields
     * @param array $actor       ['id' => int, 'type' => 'human'|'system', 'service' => string]
     * @return array{item: array, version: array}
     * @throws \RuntimeException
     */
    public function create(array $data, array $versionData = [], array $actor = []): array
    {
        $db = \Config\Database::connect();
        $db->transStart();

        // Ensure slug uniqueness
        if (empty($data['slug'])) {
            $data['slug'] = $this->items->buildUniqueSlug($data['title'] ?? '');
        } else {
            $existing = $this->items->findBySlug($data['slug']);
            if ($existing) {
                $data['slug'] = $this->items->buildUniqueSlug($data['slug']);
            }
        }

        $data['uuid']               = $data['uuid'] ?? null;
        $data['workflow_status']    = $data['workflow_status'] ?? 'idea';
        $data['approval_status']    = 'not_required';
        $data['validation_status']  = 'not_run';
        $data['publication_status'] = 'none';
        $data['created_actor_type'] = $actor['type'] ?? 'human';
        $data['created_by_user_id'] = $actor['id'] ?? null;
        $data['created_by_service'] = $actor['service'] ?? null;
        $data['updated_by_user_id'] = $actor['id'] ?? null;

        $itemId = $this->items->insert($data, true);
        if (!$itemId) {
            $db->transRollback();
            throw new \RuntimeException('Failed to create content item: ' . implode(', ', $this->items->errors()));
        }

        // Create initial version
        $version = $this->createVersion((int) $itemId, $data['title'] ?? '', $versionData, $actor, 'Initial version');

        // Update current_version_id pointer
        $this->items->update($itemId, ['current_version_id' => $version['id']]);

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new \RuntimeException('Transaction failed creating content item.');
        }

        $item = $this->items->find($itemId);

        $this->audit->log(AuditLogger::CONTENT_CREATED, $actor['id'] ?? null, [
            'content_item_id' => $itemId,
            'content_type'    => $item['content_type'],
            'title'           => $item['title'],
        ]);

        return ['item' => $item, 'version' => $version];
    }

    /**
     * Update an item. If the current workflow_status is 'approved',
     * a new version is created and the status reverts to 'draft'.
     */
    public function update(int $id, array $data, array $versionData = [], array $actor = []): array
    {
        $item = $this->items->find($id);
        if (!$item) {
            throw new \RuntimeException("Content item {$id} not found.");
        }

        $db = \Config\Database::connect();
        $db->transStart();

        $data['updated_by_user_id'] = $actor['id'] ?? null;

        // Slug uniqueness check if slug changed
        if (!empty($data['slug']) && $data['slug'] !== $item['slug']) {
            $existing = $this->items->findBySlug($data['slug']);
            if ($existing && $existing['id'] !== $id) {
                $data['slug'] = $this->items->buildUniqueSlug($data['slug'], $id);
            }
        }

        $needsNewVersion = !empty($versionData) && in_array($item['workflow_status'], ['approved', 'scheduled', 'ready_for_publication'], true);

        if ($needsNewVersion) {
            $data['workflow_status'] = 'draft';
            $data['approval_status'] = 'not_required';
        }

        $this->items->update($id, $data);

        $version = null;
        if ($needsNewVersion && !empty($versionData)) {
            $version = $this->createVersion($id, $data['title'] ?? $item['title'], $versionData, $actor, 'Post-approval edit');
            $this->items->update($id, ['current_version_id' => $version['id']]);
        }

        $db->transComplete();

        $this->audit->log(AuditLogger::CONTENT_UPDATED, $actor['id'] ?? null, [
            'content_item_id' => $id,
            'new_version'     => $version ? $version['id'] : null,
        ]);

        return ['item' => $this->items->find($id), 'version' => $version];
    }

    /** Archive a content item with a mandatory reason. */
    public function archive(int $id, string $reason, array $actor = []): array
    {
        $item = $this->items->find($id);
        if (!$item) {
            throw new \RuntimeException("Content item {$id} not found.");
        }
        if ($item['workflow_status'] === 'archived') {
            throw new \RuntimeException('Content item is already archived.');
        }

        $this->items->update($id, [
            'workflow_status' => 'archived',
            'archived_at'     => date('Y-m-d H:i:s'),
            'updated_by_user_id' => $actor['id'] ?? null,
        ]);

        $this->audit->log(AuditLogger::CONTENT_ARCHIVED, $actor['id'] ?? null, [
            'content_item_id' => $id,
            'reason'          => $reason,
        ]);

        return $this->items->find($id);
    }

    private function createVersion(int $itemId, string $title, array $body, array $actor, string $summary): array
    {
        // Mark all existing versions as not-current first
        $this->versions->where('content_item_id', $itemId)
            ->where('is_current', true)
            ->set(['is_current' => false])
            ->update();

        $versionNumber = $this->versions->nextVersionNumber($itemId);

        $versionId = $this->versions->insert([
            'content_item_id'    => $itemId,
            'version_number'     => $versionNumber,
            'title'              => $title,
            'summary'            => $body['summary'] ?? null,
            'body_html'          => $body['body_html'] ?? null,
            'body_markdown'      => $body['body_markdown'] ?? null,
            'body_plain_text'    => $body['body_plain_text'] ?? null,
            'structured_payload' => isset($body['structured_payload']) ? (is_array($body['structured_payload']) ? json_encode($body['structured_payload']) : $body['structured_payload']) : null,
            'change_summary'     => $summary,
            'is_current'         => true,
            'created_actor_type' => $actor['type'] ?? 'human',
            'created_by_user_id' => $actor['id'] ?? null,
            'created_by_service' => $actor['service'] ?? null,
            'created_at'         => date('Y-m-d H:i:s'),
        ], true);

        return $this->versions->find($versionId);
    }
}
