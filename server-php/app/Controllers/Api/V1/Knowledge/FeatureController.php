<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\ProductFeatureModel;
use CodeIgniter\Model;
use Config\Enums;
use Config\Services;

class FeatureController extends BaseKnowledgeController
{
    protected function model(): Model { return new ProductFeatureModel(); }
    protected function entityType(): string { return 'feature'; }
    protected function writableFields(): array
    {
        return ['module_id', 'slug', 'name', 'description', 'availability', 'availability_notes', 'sort_order'];
    }

    public function index()
    {
        $moduleId = (int) ($this->request->getGet('module_id') ?? 0);
        [$page, $limit] = $this->pagination();
        $model = new ProductFeatureModel();
        if ($moduleId) { $model->where('module_id', $moduleId); }
        if ($s = $this->request->getGet('status'))       { $model->where('status', $s); }
        if ($a = $this->request->getGet('availability')) { $model->where('availability', $a); }
        $total = $model->countAllResults(false);
        $items = $model->orderBy('sort_order', 'ASC')->findAll($limit, ($page - 1) * $limit);
        return $this->ok(['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show(int $id)   { return $this->showById($id); }

    public function store()
    {
        $body = $this->input();
        if (empty($body['module_id'])) { return $this->fail('module_id is required.', 422); }
        if (empty($body['name']))      { return $this->fail('name is required.', 422); }

        $enums        = new Enums();
        $availability = $body['availability'] ?? 'unknown';
        if (! $enums->isValid('featureAvailability', $availability)) {
            return $this->fail('Invalid availability value.', 422);
        }

        $sanitizer = Services::htmlSanitizer();
        $slug      = $this->uniqueSlug($body['slug'] ?? $body['name']);
        $data = [
            'module_id'          => (int) $body['module_id'],
            'slug'               => $slug,
            'name'               => $sanitizer->purifyText((string) $body['name']),
            'description'        => isset($body['description']) ? $sanitizer->purify((string) $body['description']) : null,
            'availability'       => $availability,
            'availability_notes' => isset($body['availability_notes']) ? $sanitizer->purifyText((string) $body['availability_notes']) : null,
            'sort_order'         => (int) ($body['sort_order'] ?? 0),
            'status'             => 'draft',
            'created_by'         => $this->userId(),
            'created_actor_type' => 'human',
            'request_id'         => $this->request->reachRequestId ?? null,
        ];
        $id  = (new ProductFeatureModel())->insert($data, true);
        $row = (new ProductFeatureModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'feature', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)  { return $this->updateRecord($id); }
    public function destroy(int $id) { return $this->deleteRecord($id); }
    public function submit(int $id)  { return $this->submitRecord($id); }
    public function approve(int $id) { return $this->approveRecord($id); }
    public function reject(int $id)  { return $this->rejectRecord($id); }
}
