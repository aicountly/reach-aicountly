<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\AttributionConversions;
use App\Models\Intelligence\UtmTemplateModel;
use App\Models\Intelligence\AttributionTouchpointModel;
use App\Models\Intelligence\AttributionConversionLinkModel;
use App\Models\Intelligence\AttributionCalculationVersionModel;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use App\Libraries\Database\SchemaGuard;

class AttributionController extends BaseApiController
{
    /**
     * GET intelligence/attribution — the overview tiles and first-touch bars.
     * Optional model / confidence / type / from / to narrow both, with the same
     * predicates as conversions() (AttributionConversions), so tile = list total.
     */
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
            if (! SchemaGuard::hasTable($db, 'reach_attribution_conversion_links')) {
                return $this->response->setJSON(['data' => $empty]);
            }

            $data = AttributionConversions::overview($db, $tenantId, $this->conversionFilters());

            $data['latest_calculation'] = null;
            if (SchemaGuard::hasTable($db, 'reach_attribution_calculation_versions')) {
                $calcModel = new AttributionCalculationVersionModel();
                $data['latest_calculation'] = $calcModel->where('tenant_id', $tenantId)->orderBy('calculated_at', 'DESC')->first();
            }

            return $this->response->setJSON(['data' => $data]);
        } catch (InvalidArgumentException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
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

    /**
     * GET intelligence/attribution/conversions — the conversion links behind the
     * overview: attributed=0|1, channel (first-touch label, as the bars show it),
     * model (matching_method), confidence, type, lead_id, from / to (YYYY-MM-DD),
     * page / limit. Returns { items, total, page, limit }.
     */
    public function conversions(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        [$page, $limit, $offset] = $this->pagination(50, 200);
        $empty = ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_attribution_conversion_links')) {
                return $this->response->setJSON(['data' => $empty]);
            }

            $list = AttributionConversions::list($db, $tenantId, $this->conversionFilters(), $limit, $offset);
            return $this->response->setJSON(['data' => $list + ['page' => $page, 'limit' => $limit]]);
        } catch (InvalidArgumentException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', 'AttributionController::conversions: ' . $e->getMessage());
            return $this->response->setJSON(['data' => $empty]);
        }
    }

    /** @return array<string,string> */
    private function conversionFilters(): array
    {
        $out = [];
        foreach (['attributed', 'channel', 'model', 'confidence', 'type', 'lead_id', 'from', 'to'] as $key) {
            $out[$key] = (string) ($this->request->getGet($key) ?? '');
        }
        return $out;
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
            if (! SchemaGuard::hasTable($db, 'reach_utm_templates')) {
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
