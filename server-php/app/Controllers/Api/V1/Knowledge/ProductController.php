<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\ProductModel;
use App\Models\Knowledge\ProductAliasModel;
use App\Models\Knowledge\KnowledgeRelationModel;
use CodeIgniter\Model;
use Config\Services;

class ProductController extends BaseKnowledgeController
{
    protected function model(): Model { return new ProductModel(); }
    protected function entityType(): string { return 'product'; }
    protected function htmlFields(): array { return ['description', 'short_description']; }
    protected function urlFields(): array  { return ['public_url']; }

    protected function writableFields(): array
    {
        return ['name', 'short_description', 'description', 'public_url', 'legacy_code_path'];
    }

    public function index()
    {
        $filters = array_filter([
            'status' => $this->request->getGet('status'),
            'q'      => $this->request->getGet('q'),
        ]);
        return $this->listPaged($filters);
    }

    public function show(int $id)  { return $this->showById($id); }

    public function store()
    {
        $body = $this->input();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->fail('name is required.', 422);
        }
        $slug = $this->uniqueSlug($body['slug'] ?? $name);
        $body['slug'] = $slug;
        return $this->createFromBody($body);
    }

    public function update(int $id) { return $this->updateRecord($id); }
    public function destroy(int $id) { return $this->deleteRecord($id); }
    public function submit(int $id) { return $this->submitRecord($id); }
    public function approve(int $id) { return $this->approveRecord($id); }
    public function reject(int $id) { return $this->rejectRecord($id); }
    public function archive(int $id) { return $this->archiveRecord($id); }

    public function aliases(int $id)
    {
        $product = (new ProductModel())->find($id);
        if (! $product) { return $this->fail('Product not found.', 404); }
        $aliases = (new ProductAliasModel())->forProduct($id);
        return $this->ok(['items' => $aliases, 'total' => count($aliases)]);
    }

    public function storeAlias(int $id)
    {
        $product = (new ProductModel())->find($id);
        if (! $product) { return $this->fail('Product not found.', 404); }

        $body    = $this->input();
        $alias   = trim((string) ($body['alias'] ?? ''));
        $source  = $body['source'] ?? 'user_defined';

        if ($alias === '') { return $this->fail('alias is required.', 422); }
        $aliasModel = new ProductAliasModel();
        if ($aliasModel->aliasExists($alias, $id)) {
            return $this->fail('Alias already exists for this product.', 422);
        }
        $aliasModel->insert(['product_id' => $id, 'alias' => $alias, 'source' => $source, 'created_by' => $this->userId()]);
        return $this->ok(['product_id' => $id, 'alias' => $alias], 201);
    }

    private function createFromBody(array $body)
    {
        $sanitizer = Services::htmlSanitizer();
        $urlPolicy = Services::urlPolicy();

        if (! empty($body['public_url'])) {
            $result = $urlPolicy->validate((string) $body['public_url']);
            if (! $result->allowed) {
                return $this->fail('Rejected public_url: ' . ($result->reason ?? 'invalid'), 422);
            }
        }

        $data = [
            'slug'              => $body['slug'],
            'name'              => $sanitizer->purifyText((string) ($body['name'] ?? '')),
            'short_description' => isset($body['short_description']) ? $sanitizer->purifyText((string) $body['short_description']) : null,
            'description'       => isset($body['description'])       ? $sanitizer->purify((string) $body['description']) : null,
            'public_url'        => $body['public_url'] ?? null,
            'status'            => 'draft',
            'created_by'        => $this->userId(),
            'created_actor_type'=> 'human',
            'request_id'        => $this->request->reachRequestId ?? null,
        ];

        $id  = (new ProductModel())->insert($data, true);
        $row = (new ProductModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'product', (int) $id, null, $row);
        return $this->ok($row, 201);
    }
}
