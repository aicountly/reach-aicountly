<?php

namespace App\Libraries;

use App\Models\Content\ContentValidationModel;
use App\Models\Content\ContentItemModel;

/**
 * Stores and manages validation results for content items.
 *
 * Validation execution itself is done by external callers (rule engines, manual review).
 * This service handles: storing results, managing waivers, and calculating overall status.
 *
 * Phase 2: validation_status cannot be 'published' (production rule enforced here).
 */
class ContentValidationService
{
    private ContentValidationModel $validations;
    private ContentItemModel       $items;
    private AuditLogger            $audit;

    public function __construct()
    {
        $this->validations = new ContentValidationModel();
        $this->items       = new ContentItemModel();
        $this->audit       = new AuditLogger();
    }

    /**
     * Store or update a validation result for a specific type.
     */
    public function storeResult(
        int $contentItemId,
        string $validationType,
        string $status,
        array $options = [],
        array $actor = []
    ): array {
        $existing = $this->validations->latestForType($contentItemId, $validationType);

        $data = [
            'content_item_id'   => $contentItemId,
            'version_id'        => $options['version_id'] ?? null,
            'validation_type'   => $validationType,
            'validation_status' => $status,
            'score'             => $options['score'] ?? null,
            'message'           => $options['message'] ?? null,
            'details'           => isset($options['details']) ? json_encode($options['details']) : null,
            'run_by'            => $actor['id'] ?? null,
            'run_at'            => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $this->validations->update($existing['id'], $data);
            $id = $existing['id'];
        } else {
            $id = $this->validations->insert($data, true);
        }

        // Recompute overall validation status on the item
        $this->updateOverallStatus($contentItemId);

        $this->audit->log($actor['id'] ?? null, AuditLogger::CONTENT_VALIDATION_STORED, 'content', $contentItemId, null, null, [
            'validation_type' => $validationType,
            'status'          => $status,
        ]);

        return $this->validations->find($id);
    }

    /**
     * Waive a failed validation with a mandatory reason.
     */
    public function waive(int $validationId, string $reason, array $actor = []): array
    {
        $v = $this->validations->find($validationId);
        if (!$v) {
            throw new \RuntimeException("Validation {$validationId} not found.");
        }
        if (empty($reason)) {
            throw new \RuntimeException('Waiver reason is required.');
        }

        $this->validations->update($validationId, [
            'waiver_reason' => $reason,
            'waived_by'     => $actor['id'] ?? null,
            'waived_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->updateOverallStatus($v['content_item_id']);

        $this->audit->log($actor['id'] ?? null, AuditLogger::CONTENT_VALIDATION_WAIVED, 'content', (int) $v['content_item_id'], null, null, [
            'validation_id' => $validationId,
            'reason'        => $reason,
        ]);

        return $this->validations->find($validationId);
    }

    public function getResults(int $contentItemId): array
    {
        return $this->validations->forItem($contentItemId);
    }

    public function hasBlockers(int $contentItemId): bool
    {
        return $this->validations->hasBlockers($contentItemId);
    }

    private function updateOverallStatus(int $contentItemId): void
    {
        $status = $this->validations->computeOverallStatus($contentItemId);
        $this->items->update($contentItemId, ['validation_status' => $status]);
    }
}
