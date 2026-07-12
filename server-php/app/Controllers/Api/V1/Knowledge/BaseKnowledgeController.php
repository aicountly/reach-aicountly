<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\HtmlSanitizer;
use App\Libraries\UrlPolicy;
use CodeIgniter\Model;
use Config\Enums;
use Config\Services;

/**
 * Shared CRUD scaffolding for all Phase 1 knowledge resource controllers.
 *
 * Subclasses provide the model, entity type string, allowed-input fields,
 * and optional status-transition guards.
 */
abstract class BaseKnowledgeController extends BaseApiController
{
    abstract protected function model(): Model;
    abstract protected function entityType(): string;

    /** Input keys that may be written on create/update. Override in subclass. */
    protected function writableFields(): array
    {
        return [];
    }

    /** Fields whose text content must be HTML-sanitised. */
    protected function htmlFields(): array
    {
        return ['description'];
    }

    /** Fields that must pass URL policy validation. */
    protected function urlFields(): array
    {
        return [];
    }

    // ── Standard CRUD ─────────────────────────────────────────────────────────

    protected function listPaged(array $filters = [])
    {
        [$page, $limit] = $this->pagination();
        $result = $this->model()->listPaged($page, $limit, $filters);
        return $this->ok(array_merge($result, ['page' => $page, 'limit' => $limit]));
    }

    protected function showById(int $id)
    {
        $row = $this->model()->find($id);
        if (! $row) {
            return $this->fail($this->entityType() . ' not found.', 404);
        }
        return $this->ok($row);
    }

    protected function createRecord(array $extra = [])
    {
        $body = $this->input();
        $data = $this->buildWriteData($body, $extra);

        if ($data instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $data;
        }

        $data['created_by']         = $this->userId();
        $data['created_actor_type'] = 'human';
        $data['request_id']         = $this->request->reachRequestId ?? null;
        $data['status']             = 'draft';

        $id = $this->model()->insert($data, true);
        if (! $id) {
            return $this->fail('Failed to create ' . $this->entityType() . '.', 422);
        }

        $row = $this->model()->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, $this->entityType(), (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    protected function updateRecord(int $id)
    {
        $model = $this->model();
        $existing = $model->find($id);
        if (! $existing) {
            return $this->fail($this->entityType() . ' not found.', 404);
        }
        if (in_array($existing['status'] ?? '', ['approved', 'archived', 'deprecated'], true)) {
            return $this->fail('Cannot edit a record in status: ' . $existing['status'], 422);
        }

        $body = $this->input();
        $data = $this->buildWriteData($body, ['updated_by' => $this->userId()]);

        if ($data instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $data;
        }

        $model->update($id, $data);
        $updated = $model->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_UPDATED, $this->entityType(), $id, $existing, $updated);
        return $this->ok($updated);
    }

    protected function deleteRecord(int $id)
    {
        $model    = $this->model();
        $existing = $model->find($id);
        if (! $existing) {
            return $this->fail($this->entityType() . ' not found.', 404);
        }
        $model->delete($id);
        $this->audit(AuditLogger::KNOWLEDGE_DELETED, $this->entityType(), $id, $existing);
        return $this->ok(['deleted' => true]);
    }

    protected function submitRecord(int $id)
    {
        $model    = $this->model();
        $existing = $model->find($id);
        if (! $existing) {
            return $this->fail($this->entityType() . ' not found.', 404);
        }
        if ($existing['status'] !== 'draft') {
            return $this->fail('Only draft records can be submitted for review.', 422);
        }

        $model->update($id, [
            'status'     => 'needs_review',
            'updated_by' => $this->userId(),
            'request_id' => $this->request->reachRequestId ?? null,
        ]);
        $updated = $model->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_SUBMITTED, $this->entityType(), $id, $existing, $updated);
        return $this->ok($updated);
    }

    protected function approveRecord(int $id)
    {
        $model    = $this->model();
        $existing = $model->find($id);
        if (! $existing) {
            return $this->fail($this->entityType() . ' not found.', 404);
        }
        if ($existing['status'] !== 'needs_review') {
            return $this->fail('Only records in needs_review status can be approved.', 422);
        }

        $body   = $this->input();
        $reason = trim((string) ($body['reason'] ?? ''));
        $now    = date('Y-m-d H:i:s');

        $model->update($id, [
            'status'      => 'approved',
            'approved_by' => $this->userId(),
            'approved_at' => $now,
            'reviewed_by' => $this->userId(),
            'reviewed_at' => $now,
            'updated_by'  => $this->userId(),
            'request_id'  => $this->request->reachRequestId ?? null,
        ]);
        $updated = $model->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_APPROVED, $this->entityType(), $id, $existing, $updated,
            ['reason' => $reason], $reason ?: null);
        return $this->ok($updated);
    }

    protected function rejectRecord(int $id)
    {
        $model    = $this->model();
        $existing = $model->find($id);
        if (! $existing) {
            return $this->fail($this->entityType() . ' not found.', 404);
        }

        $body   = $this->input();
        $reason = trim((string) ($body['reason'] ?? ''));
        $now    = date('Y-m-d H:i:s');

        $model->update($id, [
            'status'      => 'rejected',
            'reviewed_by' => $this->userId(),
            'reviewed_at' => $now,
            'updated_by'  => $this->userId(),
            'request_id'  => $this->request->reachRequestId ?? null,
        ]);
        $updated = $model->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_REJECTED, $this->entityType(), $id, $existing, $updated,
            ['reason' => $reason], $reason ?: null);
        return $this->ok($updated);
    }

    protected function archiveRecord(int $id)
    {
        $model    = $this->model();
        $existing = $model->find($id);
        if (! $existing) {
            return $this->fail($this->entityType() . ' not found.', 404);
        }

        $model->update($id, [
            'status'     => 'archived',
            'updated_by' => $this->userId(),
            'request_id' => $this->request->reachRequestId ?? null,
        ]);
        $updated = $model->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_ARCHIVED, $this->entityType(), $id, $existing, $updated);
        return $this->ok($updated);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    protected function buildWriteData(array $body, array $merge = []): array|\CodeIgniter\HTTP\ResponseInterface
    {
        $sanitizer = Services::htmlSanitizer();
        $urlPolicy = Services::urlPolicy();
        $data      = [];

        foreach ($this->writableFields() as $field) {
            if (array_key_exists($field, $body)) {
                $value = $body[$field];
                if (in_array($field, $this->htmlFields(), true) && is_string($value)) {
                    $value = $sanitizer->purify($value);
                }
                $data[$field] = $value;
            }
        }

        foreach ($this->urlFields() as $field) {
            if (! empty($data[$field])) {
                $result = $urlPolicy->validate((string) $data[$field]);
                if (! $result->allowed) {
                    return $this->fail('Rejected URL in field ' . $field . ': ' . ($result->reason ?? 'invalid'), 422);
                }
            }
        }

        return array_merge($data, $merge);
    }

    protected function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        return trim($text, '_');
    }

    protected function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        $model = $this->model();
        $slug  = $this->slugify($base);
        if (! method_exists($model, 'slugExists') || ! $model->slugExists($slug, $excludeId)) {
            return $slug;
        }
        $i = 2;
        while ($model->slugExists("{$slug}_{$i}", $excludeId)) {
            $i++;
        }
        return "{$slug}_{$i}";
    }
}
