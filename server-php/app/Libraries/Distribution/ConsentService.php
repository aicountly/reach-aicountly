<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Models\Distribution\ChannelConsentModel;

class ConsentService
{
    public function __construct(
        private readonly ChannelConsentModel $model,
        private readonly AuditLogger         $audit,
    ) {}

    public function grant(int $tenantId, string $subjectType, int $subjectId, string $channel, string $purpose, string $source, ?string $proofReference, ?int $actorId): array
    {
        $id = $this->model->insert([
            'tenant_id'       => $tenantId,
            'subject_type'    => $subjectType,
            'subject_id'      => $subjectId,
            'channel'         => $channel,
            'purpose'         => $purpose,
            'status'          => 'granted',
            'source'          => $source,
            'proof_reference' => $proofReference,
            'captured_at'     => date('Y-m-d H:i:s'),
            'captured_by'     => $actorId,
        ]);

        $record = $this->model->find($id);

        $this->audit->record(AuditLogger::DISTRIBUTION_CONSENT_GRANTED, [
            'consent_id'   => $id,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'channel'      => $channel,
        ], $actorId);

        return $record;
    }

    public function revoke(int $consentId, int $tenantId, ?int $actorId): bool
    {
        $record = $this->model->find($consentId);
        if ($record === null || (int) $record['tenant_id'] !== $tenantId) {
            return false;
        }

        $this->model->update($consentId, [
            'status'     => 'revoked',
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->record(AuditLogger::DISTRIBUTION_CONSENT_REVOKED, [
            'consent_id' => $consentId,
        ], $actorId);

        return true;
    }

    public function isGranted(int $tenantId, string $subjectType, int $subjectId, string $channel): bool
    {
        return $this->model->isGranted($tenantId, $subjectType, $subjectId, $channel);
    }

    public function list(int $tenantId, int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;
        $rows   = $this->model->where('tenant_id', $tenantId)
            ->orderBy('captured_at', 'DESC')
            ->limit($perPage, $offset)
            ->findAll();
        $total  = $this->model->where('tenant_id', $tenantId)->countAllResults();
        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}
