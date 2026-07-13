<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Ai;

use App\Controllers\BaseApiController;

/**
 * Phase 3 — AI Provider API.
 *
 * GET  /api/v1/ai/providers        — list providers
 * GET  /api/v1/ai/providers/:id    — show a single provider
 * PATCH /api/v1/ai/providers/:id/status — enable/disable
 */
class AiProviderController extends BaseApiController
{
    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $rows = $db->query(
            "SELECT p.id, p.provider_key, p.display_name, p.status,
                    p.supports_structured_output, p.supports_tool_calls,
                    p.supports_streaming,
                    h.status AS last_health_status, h.is_circuit_open AS circuit_open
             FROM reach_ai_providers p
             LEFT JOIN reach_ai_provider_health h ON h.provider_id = p.id
             WHERE p.deleted_at IS NULL
             ORDER BY p.display_name"
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['configuration_status'] = 'unconfigured';
        }

        return $this->ok(['providers' => $rows, 'total' => count($rows)]);
    }

    public function show(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $row = $db->query(
            "SELECT p.*, h.status AS last_health_status, h.is_circuit_open AS circuit_open,
                    h.last_checked_at
             FROM reach_ai_providers p
             LEFT JOIN reach_ai_provider_health h ON h.provider_id = p.id
             WHERE p.id = ? AND p.deleted_at IS NULL",
            [(int) $id]
        )->getRowArray();

        if (!$row) {
            return $this->fail('Provider not found', 404);
        }

        unset($row['secret_env_reference']);

        return $this->ok(['provider' => $row]);
    }

    public function updateStatus(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $data = $this->input();
        $status = $data['status'] ?? '';

        $allowed = ['enabled', 'disabled', 'deprecated'];
        if (!in_array($status, $allowed, true)) {
            return $this->fail('Invalid status. Allowed: ' . implode(', ', $allowed), 422);
        }

        $db = \Config\Database::connect();
        $affected = $db->query(
            "UPDATE reach_ai_providers SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL",
            [$status, (int) $id]
        );

        if (!$affected) {
            return $this->fail('Provider not found', 404);
        }

        return $this->ok(['updated' => true, 'status' => $status]);
    }
}
