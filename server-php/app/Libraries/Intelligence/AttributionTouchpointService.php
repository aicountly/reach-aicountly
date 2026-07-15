<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\AuditLogger;
use App\Models\Intelligence\AttributionTouchpointModel;
use App\Models\Intelligence\AttributionConversionLinkModel;
use App\Models\Intelligence\AttributionCalculationVersionModel;

class AttributionTouchpointService
{
    public function __construct(
        private AttributionTouchpointModel          $touchpointModel,
        private AttributionConversionLinkModel      $conversionModel,
        private AttributionCalculationVersionModel  $calcVersionModel,
        private AuditLogger                         $auditLogger,
    ) {}

    public function recordTouchpoint(array $data): array
    {
        $id = $this->touchpointModel->insert($data);
        $this->auditLogger->log(null, AuditLogger::ATTRIBUTION_TOUCHPOINT_RECORDED, 'attribution_touchpoint', (int)$id,
            null, null, null, 'system');
        return $this->touchpointModel->find($id);
    }

    public function linkConversion(int $tenantId, int $leadId, string $conversionType, \DateTimeImmutable $convertedAt): array
    {
        $hash    = $this->findVisitorHash($tenantId, $leadId);
        $links   = $hash ? $this->touchpointModel->getForVisitor($hash, $tenantId) : [];
        $firstId = !empty($links) ? (int) $links[0]['id'] : null;
        $lastId  = !empty($links) ? (int) $links[count($links) - 1]['id'] : null;

        $id = $this->conversionModel->insert([
            'tenant_id'          => $tenantId,
            'lead_id'            => $leadId,
            'first_touchpoint_id' => $firstId,
            'last_touchpoint_id'  => $lastId,
            'conversion_type'    => $conversionType,
            'converted_at'       => $convertedAt->format('Y-m-d H:i:s'),
            'matching_method'    => $firstId ? 'last_touch' : 'unattributed',
            'confidence_state'   => $firstId ? 'inferred' : 'unattributed',
        ]);

        $this->auditLogger->log(null, AuditLogger::ATTRIBUTION_CONVERSION_LINKED, 'attribution_conversion', (int)$id,
            null, null, ['lead_id' => $leadId], 'system');

        return $this->conversionModel->find($id);
    }

    public function calculateAttributions(int $tenantId, string $method = 'last_touch', ?string $periodFrom = null, ?string $periodTo = null): array
    {
        $existing    = $this->calcVersionModel->where('tenant_id', $tenantId)->orderBy('version_number', 'DESC')->first();
        $nextVersion = ((int) ($existing['version_number'] ?? 0)) + 1;

        $q      = $this->conversionModel->where('tenant_id', $tenantId);
        if ($periodFrom) $q->where('converted_at >=', $periodFrom);
        if ($periodTo)   $q->where('converted_at <=', $periodTo);
        $conversions = $q->findAll();

        $total       = count($conversions);
        $attributed  = count(array_filter($conversions, fn($c) => $c['confidence_state'] !== 'unattributed'));
        $unattributed = $total - $attributed;

        $id = $this->calcVersionModel->insert([
            'tenant_id'          => $tenantId,
            'version_number'     => $nextVersion,
            'method'             => $method,
            'period_from'        => $periodFrom,
            'period_to'          => $periodTo,
            'total_conversions'  => $total,
            'attributed_count'   => $attributed,
            'unattributed_count' => $unattributed,
            'triggered_by'       => 'service',
        ]);

        $this->auditLogger->log(null, AuditLogger::ATTRIBUTION_CALCULATION_RUN, 'attribution_calculation', (int)$id,
            null, ['total' => $total, 'attributed' => $attributed], null, 'system');

        return $this->calcVersionModel->find($id);
    }

    private function findVisitorHash(int $tenantId, int $leadId): ?string
    {
        return null;
    }
}
