<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Models\Intelligence\UtmTemplateModel;
use App\Models\Intelligence\AttributionTouchpointModel;
use App\Models\Intelligence\AttributionConversionLinkModel;
use App\Models\Intelligence\AttributionCalculationVersionModel;
use CodeIgniter\HTTP\ResponseInterface;

class AttributionController extends BaseApiController
{
    public function overview(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        $empty    = [
            'stats' => [
                'total_conversions' => 0,
                'attributed'        => 0,
                'unattributed'      => 0,
                'attribution_rate'  => 0.0,
            ],
            'first_touch_breakdown' => [],
            'latest_calculation'    => null,
        ];

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_attribution_conversion_links')) {
                return $this->response->setJSON(['data' => $empty]);
            }

            $totals = $db->query(
                "SELECT
                    COUNT(*)::int AS total_conversions,
                    COUNT(*) FILTER (
                        WHERE confidence_state IS DISTINCT FROM 'unattributed'
                          AND matching_method IS DISTINCT FROM 'unattributed'
                          AND (first_touchpoint_id IS NOT NULL OR last_touchpoint_id IS NOT NULL)
                    )::int AS attributed,
                    COUNT(*) FILTER (
                        WHERE confidence_state = 'unattributed'
                           OR matching_method = 'unattributed'
                           OR (first_touchpoint_id IS NULL AND last_touchpoint_id IS NULL)
                    )::int AS unattributed
                 FROM reach_attribution_conversion_links
                 WHERE tenant_id = ?",
                [$tenantId]
            )->getRowArray() ?: [
                'total_conversions' => 0,
                'attributed'        => 0,
                'unattributed'      => 0,
            ];

            $total        = (int) ($totals['total_conversions'] ?? 0);
            $attributed   = (int) ($totals['attributed'] ?? 0);
            $unattributed = (int) ($totals['unattributed'] ?? 0);
            // Keep counts consistent if filters overlap oddly
            if ($attributed + $unattributed !== $total && $total > 0) {
                $unattributed = max(0, $total - $attributed);
            }
            $rate = $total > 0 ? round(($attributed / $total) * 100, 1) : 0.0;

            $channelRows = [];
            if ($db->tableExists('reach_attribution_touchpoints')) {
                $channelRows = $db->query(
                    "SELECT
                        COALESCE(NULLIF(TRIM(reach_attribution_touchpoints.channel), ''), NULLIF(TRIM(reach_attribution_touchpoints.utm_source), ''), 'Unknown') AS channel,
                        COUNT(*)::int AS conversions
                     FROM reach_attribution_conversion_links
                     LEFT JOIN reach_attribution_touchpoints
                       ON reach_attribution_touchpoints.id = reach_attribution_conversion_links.first_touchpoint_id
                     WHERE reach_attribution_conversion_links.tenant_id = ?
                       AND reach_attribution_conversion_links.confidence_state IS DISTINCT FROM 'unattributed'
                       AND reach_attribution_conversion_links.matching_method IS DISTINCT FROM 'unattributed'
                       AND reach_attribution_conversion_links.first_touchpoint_id IS NOT NULL
                     GROUP BY 1
                     ORDER BY conversions DESC
                     LIMIT 20",
                    [$tenantId]
                )->getResultArray() ?: [];
            }

            $channelTotal = array_sum(array_map(static fn ($r) => (int) $r['conversions'], $channelRows));
            $breakdown    = array_map(static function (array $row) use ($channelTotal): array {
                $count = (int) $row['conversions'];
                return [
                    'channel'     => $row['channel'] ?: 'Unknown',
                    'conversions' => $count,
                    'pct'         => $channelTotal > 0 ? (int) round(($count / $channelTotal) * 100) : 0,
                ];
            }, $channelRows);

            $latest = null;
            if ($db->tableExists('reach_attribution_calculation_versions')) {
                $calcModel = new AttributionCalculationVersionModel();
                $latest    = $calcModel->where('tenant_id', $tenantId)->orderBy('calculated_at', 'DESC')->first();
            }

            return $this->response->setJSON([
                'data' => [
                    'stats' => [
                        'total_conversions' => $total,
                        'attributed'        => $attributed,
                        'unattributed'      => $unattributed,
                        'attribution_rate'  => $rate,
                    ],
                    'first_touch_breakdown' => $breakdown,
                    'latest_calculation'    => $latest,
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'AttributionController::overview: ' . $e->getMessage());
            return $this->response->setJSON(['data' => $empty]);
        }
    }

    public function recordTouchpoint(): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $model = new AttributionTouchpointModel();
        $id    = $model->insert($body);
        return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($id)]);
    }

    public function calculate(): ResponseInterface
    {
        $body       = $this->request->getJSON(true) ?? [];
        $tenantId   = (int) ($body['tenant_id'] ?? 1);
        $method     = $body['method'] ?? 'last_touch';
        $model      = new AttributionCalculationVersionModel();
        $existing   = $model->where('tenant_id', $tenantId)->orderBy('version_number', 'DESC')->first();
        $nextVersion = ((int) ($existing['version_number'] ?? 0)) + 1;

        $id = $model->insert([
            'tenant_id'      => $tenantId,
            'version_number' => $nextVersion,
            'method'         => $method,
            'triggered_by'   => 'manual',
        ]);
        return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($id)]);
    }

    public function conversions(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_attribution_conversion_links')) {
                return $this->response->setJSON(['data' => []]);
            }

            $model = new AttributionConversionLinkModel();
            $data  = $model->where('tenant_id', $tenantId)->orderBy('converted_at', 'DESC')->findAll(50);
            return $this->response->setJSON(['data' => is_array($data) ? $data : []]);
        } catch (\Throwable $e) {
            log_message('error', 'AttributionController::conversions: ' . $e->getMessage());
            return $this->response->setJSON(['data' => []]);
        }
    }

    public function correct(int $id): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $model = new AttributionConversionLinkModel();
        $conv  = $model->find($id);
        if (!$conv) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }

        $model->update($id, [
            'confidence_state'       => 'corrected',
            'manual_correction_note' => $body['note'] ?? '',
            'corrected_at'           => date('Y-m-d H:i:s'),
        ]);

        (new AuditLogger())->log(
            null,
            AuditLogger::ATTRIBUTION_CORRECTION_APPLIED,
            'attribution_conversion',
            $id,
            $conv,
            $model->find($id),
            null,
            'human'
        );

        return $this->response->setJSON(['data' => $model->find($id)]);
    }

    public function utmTemplates(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_utm_templates')) {
                return $this->response->setJSON(['data' => []]);
            }

            $model = new UtmTemplateModel();
            $rows  = $model->getActiveForTenant($tenantId);
            return $this->response->setJSON(['data' => is_array($rows) ? $rows : []]);
        } catch (\Throwable $e) {
            log_message('error', 'AttributionController::utmTemplates: ' . $e->getMessage());
            return $this->response->setJSON(['data' => []]);
        }
    }

    public function createUtmTemplate(): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $model = new UtmTemplateModel();
        $id    = $model->insert($body);
        return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($id)]);
    }
}
