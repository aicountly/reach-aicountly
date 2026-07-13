<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Ai;

use App\Controllers\BaseApiController;
use App\Libraries\Ai\AiProviderRegistry;

/**
 * Phase 3 — AI Dashboard and Health endpoints.
 *
 * GET /api/v1/ai/dashboard — summary statistics
 * GET /api/v1/ai/health    — live provider health
 */
class AiDashboardController extends BaseApiController
{
    public function dashboard(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db   = \Config\Database::connect();
        $today = date('Y-m-d');

        $total = (int) ($db->query("SELECT COUNT(*) AS cnt FROM reach_ai_generation_requests")->getRowArray()['cnt'] ?? 0);
        $completedToday = (int) ($db->query(
            "SELECT COUNT(*) AS cnt FROM reach_ai_generation_requests WHERE status = 'completed' AND DATE(completed_at) = ?",
            [$today]
        )->getRowArray()['cnt'] ?? 0);
        $failedToday = (int) ($db->query(
            "SELECT COUNT(*) AS cnt FROM reach_ai_generation_requests WHERE status = 'failed' AND DATE(created_at) = ?",
            [$today]
        )->getRowArray()['cnt'] ?? 0);

        $todayCost = $db->query(
            "SELECT COALESCE(SUM(estimated_cost), 0) AS total FROM reach_ai_usage_ledger WHERE usage_date = ?",
            [$today]
        )->getRowArray()['total'] ?? '0';

        $recent = $db->query(
            "SELECT r.uuid, r.task_type, r.content_type, r.status, r.created_at
             FROM reach_ai_generation_requests r
             ORDER BY r.created_at DESC LIMIT 10"
        )->getResultArray();

        return $this->ok([
            'stats' => [
                'total_generations' => $total,
                'completed_today'   => $completedToday,
                'failed_today'      => $failedToday,
                'today_cost'        => number_format((float) $todayCost, 4),
            ],
            'recent_requests' => $recent,
        ]);
    }

    public function health(): \CodeIgniter\HTTP\ResponseInterface
    {
        $registry = new AiProviderRegistry();
        $providers = [];
        $allHealthy = true;

        foreach (['openai', 'mock'] as $key) {
            try {
                $provider = $registry->get($key);
                if (!$provider->isConfigured()) {
                    $providers[] = [
                        'provider_key'   => $key,
                        'healthy'        => false,
                        'error_message'  => 'Not configured',
                        'circuit_open'   => false,
                    ];
                    $allHealthy = false;
                    continue;
                }

                $result = $provider->healthCheck();
                $providers[] = [
                    'provider_key'    => $key,
                    'healthy'         => $result->healthy,
                    'response_time_ms'=> $result->responseTimeMs,
                    'error_message'   => $result->errorMessage,
                    'circuit_open'    => false,
                ];
                if (!$result->healthy) {
                    $allHealthy = false;
                }
            } catch (\Throwable) {
                $providers[] = ['provider_key' => $key, 'healthy' => false, 'error_message' => 'Registry error', 'circuit_open' => false];
                $allHealthy = false;
            }
        }

        return $this->ok([
            'overall_status' => $allHealthy ? 'healthy' : 'degraded',
            'providers'      => $providers,
            'checked_at'     => date('c'),
        ]);
    }
}
