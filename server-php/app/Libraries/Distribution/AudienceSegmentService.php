<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Models\Distribution\AudienceSegmentModel;
use App\Models\Distribution\AudienceSegmentRuleModel;

class AudienceSegmentService
{
    public function __construct(
        private readonly AudienceSegmentModel     $segmentModel,
        private readonly AudienceSegmentRuleModel $ruleModel,
        private readonly AuditLogger              $audit,
    ) {}

    public function list(int $tenantId): array
    {
        return $this->segmentModel->listForTenant($tenantId);
    }

    public function create(int $tenantId, array $data, ?int $actorId): array
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('Segment name is required.');
        }

        $segmentId = $this->segmentModel->insert([
            'tenant_id'        => $tenantId,
            'name'             => $data['name'],
            'description'      => $data['description'] ?? null,
            'segment_type'     => $data['segment_type'] ?? 'dynamic',
            'criteria_summary' => $data['criteria_summary'] ?? null,
            'created_by'       => $actorId,
        ]);

        if (!empty($data['rules']) && is_array($data['rules'])) {
            foreach ($data['rules'] as $rule) {
                $this->addRule((int) $segmentId, $rule);
            }
        }

        $segment = $this->segmentModel->find($segmentId);

        $this->audit->record(AuditLogger::DISTRIBUTION_SEGMENT_CREATED, [
            'segment_id' => $segmentId,
            'name'       => $segment['name'],
        ], $actorId);

        return $segment;
    }

    public function update(int $segmentId, int $tenantId, array $data, ?int $actorId): array
    {
        $segment = $this->segmentModel->findByUuid((string) $segmentId, $tenantId) ?? $this->segmentModel->find($segmentId);
        if ($segment === null || (int) $segment['tenant_id'] !== $tenantId) {
            throw new \RuntimeException('Segment not found.', 404);
        }

        $this->segmentModel->update($segment['id'], array_filter([
            'name'             => $data['name'] ?? null,
            'description'      => $data['description'] ?? null,
            'criteria_summary' => $data['criteria_summary'] ?? null,
        ], fn($v) => $v !== null));

        $this->audit->record(AuditLogger::DISTRIBUTION_SEGMENT_UPDATED, [
            'segment_id' => $segment['id'],
        ], $actorId);

        return $this->segmentModel->find($segment['id']);
    }

    public function delete(int $segmentId, int $tenantId, ?int $actorId): bool
    {
        $segment = $this->segmentModel->find($segmentId);
        if ($segment === null || (int) $segment['tenant_id'] !== $tenantId) {
            return false;
        }

        $this->segmentModel->update($segmentId, ['is_active' => false]);

        $this->audit->record(AuditLogger::DISTRIBUTION_SEGMENT_DELETED, [
            'segment_id' => $segmentId,
        ], $actorId);

        return true;
    }

    public function preview(int $segmentId, int $tenantId): array
    {
        $segment = $this->segmentModel->find($segmentId);
        if ($segment === null || (int) $segment['tenant_id'] !== $tenantId) {
            throw new \RuntimeException('Segment not found.', 404);
        }

        // Preview is a stub — count is not computed from real CRM in Phase 7
        $count = rand(10, 500);
        $this->segmentModel->update($segmentId, ['estimated_count' => $count]);

        return ['segment_id' => $segmentId, 'estimated_count' => $count, 'computed_at' => date('c')];
    }

    private function addRule(int $segmentId, array $rule): void
    {
        $field = $rule['field'] ?? '';
        if (!$this->ruleModel->isAllowedField($field)) {
            throw new \InvalidArgumentException("Field '{$field}' is not allowed in segment rules.");
        }
        $this->ruleModel->insert([
            'segment_id' => $segmentId,
            'rule_group' => (int) ($rule['rule_group'] ?? 0),
            'field'      => $field,
            'operator'   => $rule['operator'] ?? 'eq',
            'value'      => $rule['value'] ?? null,
            'negated'    => (bool) ($rule['negated'] ?? false),
        ]);
    }
}
