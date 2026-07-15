<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseController;
use App\Libraries\AuditLogger;
use App\Models\Intelligence\UtmTemplateModel;
use App\Models\Intelligence\AttributionTouchpointModel;
use App\Models\Intelligence\AttributionConversionLinkModel;
use App\Models\Intelligence\AttributionCalculationVersionModel;
use CodeIgniter\HTTP\ResponseInterface;

class AttributionController extends BaseController
{
    public function overview(): ResponseInterface
    {
        $tenantId   = (int) ($this->request->getGet('tenant_id') ?? 1);
        $calcModel  = new AttributionCalculationVersionModel();
        $latest     = $calcModel->where('tenant_id', $tenantId)->orderBy('calculated_at', 'DESC')->first();
        return $this->response->setJSON(['data' => ['latest_calculation' => $latest]]);
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
        $model    = new AttributionConversionLinkModel();
        $data     = $model->where('tenant_id', $tenantId)->orderBy('converted_at', 'DESC')->findAll(50);
        return $this->response->setJSON(['data' => $data]);
    }

    public function correct(int $id): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $model = new AttributionConversionLinkModel();
        $conv  = $model->find($id);
        if (!$conv) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);

        $model->update($id, [
            'confidence_state'        => 'corrected',
            'manual_correction_note'  => $body['note'] ?? '',
            'corrected_at'            => date('Y-m-d H:i:s'),
        ]);

        (new AuditLogger())->log(null, AuditLogger::ATTRIBUTION_CORRECTION_APPLIED, 'attribution_conversion', $id,
            $conv, $model->find($id), null, 'human');

        return $this->response->setJSON(['data' => $model->find($id)]);
    }

    public function utmTemplates(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        $model    = new UtmTemplateModel();
        return $this->response->setJSON(['data' => $model->getActiveForTenant($tenantId)]);
    }

    public function createUtmTemplate(): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $model = new UtmTemplateModel();
        $id    = $model->insert($body);
        return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($id)]);
    }
}
