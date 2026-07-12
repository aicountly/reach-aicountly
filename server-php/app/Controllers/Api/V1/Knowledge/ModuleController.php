<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\ProductModuleModel;
use CodeIgniter\Model;
use Config\Services;

class ModuleController extends BaseKnowledgeController
{
    protected function model(): Model { return new ProductModuleModel(); }
    protected function entityType(): string { return 'module'; }
    protected function writableFields(): array { return ['product_id', 'slug', 'name', 'description', 'sort_order']; }

    public function index()
    {
        $productId = (int) ($this->request->getGet('product_id') ?? 0);
        [$page, $limit] = $this->pagination();
        $model = new ProductModuleModel();
        if ($productId) {
            $model->where('product_id', $productId);
        }
        if ($s = $this->request->getGet('status')) { $model->where('status', $s); }
        $total = $model->countAllResults(false);
        $items = $model->orderBy('sort_order', 'ASC')->findAll($limit, ($page - 1) * $limit);
        return $this->ok(['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show(int $id)    { return $this->showById($id); }

    public function store()
    {
        $body = $this->input();
        if (empty($body['product_id'])) { return $this->fail('product_id is required.', 422); }
        if (empty($body['name']))       { return $this->fail('name is required.', 422); }

        $sanitizer = Services::htmlSanitizer();
        $slug      = $this->uniqueSlug($body['slug'] ?? $body['name']);
        $data = [
            'product_id'        => (int) $body['product_id'],
            'slug'              => $slug,
            'name'              => $sanitizer->purifyText((string) $body['name']),
            'description'       => isset($body['description']) ? $sanitizer->purify((string) $body['description']) : null,
            'sort_order'        => (int) ($body['sort_order'] ?? 0),
            'status'            => 'draft',
            'created_by'        => $this->userId(),
            'created_actor_type'=> 'human',
            'request_id'        => $this->request->reachRequestId ?? null,
        ];
        $id  = (new ProductModuleModel())->insert($data, true);
        $row = (new ProductModuleModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'module', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }
}
