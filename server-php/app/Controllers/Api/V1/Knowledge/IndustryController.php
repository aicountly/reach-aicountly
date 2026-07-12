<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\IndustryModel;
use CodeIgniter\Model;
use Config\Services;

class IndustryController extends BaseKnowledgeController
{
    protected function model(): Model { return new IndustryModel(); }
    protected function entityType(): string { return 'industry'; }
    protected function writableFields(): array { return ['slug', 'name', 'description', 'parent_id']; }

    public function index()
    {
        return $this->listPaged(array_filter([
            'status' => $this->request->getGet('status'),
            'q'      => $this->request->getGet('q'),
        ]));
    }

    public function show(int $id)    { return $this->showById($id); }

    public function store()
    {
        $body = $this->input();
        if (empty($body['name'])) { return $this->fail('name is required.', 422); }

        $parentId = isset($body['parent_id']) ? (int) $body['parent_id'] : null;
        $sanitizer = Services::htmlSanitizer();
        $slug      = $this->uniqueSlug($body['slug'] ?? $body['name']);
        $data = [
            'slug'              => $slug,
            'name'              => $sanitizer->purifyText((string) $body['name']),
            'description'       => isset($body['description']) ? $sanitizer->purify((string) $body['description']) : null,
            'parent_id'         => $parentId,
            'status'            => 'draft',
            'created_by'        => $this->userId(),
            'created_actor_type'=> 'human',
            'request_id'        => $this->request->reachRequestId ?? null,
        ];
        $id  = (new IndustryModel())->insert($data, true);
        $row = (new IndustryModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'industry', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)
    {
        $model    = new IndustryModel();
        $existing = $model->find($id);
        if (! $existing) { return $this->fail('Industry not found.', 404); }

        $body = $this->input();
        if (isset($body['parent_id']) && (int) $body['parent_id'] !== 0) {
            if ($model->wouldCreateCircle($id, (int) $body['parent_id'])) {
                return $this->fail('Setting this parent would create a circular hierarchy.', 422);
            }
        }
        return $this->updateRecord($id);
    }

    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }
}
