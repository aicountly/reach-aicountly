<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Enums\SuppressionReason;
use App\Models\Distribution\ChannelSuppressionModel;

class SuppressionService
{
    public function __construct(
        private readonly ChannelSuppressionModel $model,
        private readonly AuditLogger             $audit,
    ) {}

    public function suppress(int $tenantId, string $channel, string $address, SuppressionReason $reason, string $source, ?int $actorId, ?\DateTime $expiresAt = null): array
    {
        $hash   = ChannelSuppressionModel::hashAddress($tenantId, $channel, $address);
        $masked = ChannelSuppressionModel::maskAddress($address);

        // Upsert pattern — if already suppressed, return existing
        $existing = $this->model->where('tenant_id', $tenantId)->where('channel', $channel)->where('address_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $id = $this->model->insert([
            'tenant_id'      => $tenantId,
            'channel'        => $channel,
            'address_hash'   => $hash,
            'address_masked' => $masked,
            'reason'         => $reason->value,
            'source'         => $source,
            'suppressed_by'  => $actorId,
            'expires_at'     => $expiresAt?->format('Y-m-d H:i:s'),
        ]);

        $record = $this->model->find($id);

        $this->audit->record(AuditLogger::DISTRIBUTION_SUPPRESSION_ADDED, [
            'suppression_id' => $id,
            'channel'        => $channel,
            'reason'         => $reason->value,
        ], $actorId);

        return $record;
    }

    public function remove(int $suppressionId, int $tenantId, ?int $actorId): bool
    {
        $record = $this->model->find($suppressionId);
        if ($record === null || (int) $record['tenant_id'] !== $tenantId) {
            return false;
        }

        $this->model->delete($suppressionId);

        $this->audit->record(AuditLogger::DISTRIBUTION_SUPPRESSION_REMOVED, [
            'suppression_id' => $suppressionId,
        ], $actorId);

        return true;
    }

    public function isSuppressed(int $tenantId, string $channel, string $address): bool
    {
        return $this->model->isSuppressed($tenantId, $channel, ChannelSuppressionModel::hashAddress($tenantId, $channel, $address));
    }

    public function list(int $tenantId, int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;
        $rows   = $this->model->where('tenant_id', $tenantId)
            ->orderBy('suppressed_at', 'DESC')
            ->limit($perPage, $offset)
            ->findAll();
        $total  = $this->model->where('tenant_id', $tenantId)->countAllResults();
        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}
