<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\MarketModel;
use CodeIgniter\Model;
use Config\Services;

class MarketController extends BaseKnowledgeController
{
    protected function model(): Model { return new MarketModel(); }
    protected function entityType(): string { return 'market'; }
    protected function writableFields(): array
    {
        return ['slug', 'name', 'region', 'country_codes', 'jurisdiction_notes', 'description'];
    }

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

        $sanitizer = Services::htmlSanitizer();
        $slug      = $this->uniqueSlug($body['slug'] ?? $body['name']);
        $data = [
            'slug'               => $slug,
            'name'               => $sanitizer->purifyText((string) $body['name']),
            'region'             => $body['region'] ?? null,
            'country_codes'      => isset($body['country_codes']) ? json_encode($body['country_codes']) : null,
            'jurisdiction_notes' => isset($body['jurisdiction_notes']) ? $sanitizer->purifyText((string) $body['jurisdiction_notes']) : null,
            'description'        => isset($body['description']) ? $sanitizer->purify((string) $body['description']) : null,
            'status'             => 'draft',
            'created_by'         => $this->userId(),
            'created_actor_type' => 'human',
            'request_id'         => $this->request->reachRequestId ?? null,
        ];
        $id  = (new MarketModel())->insert($data, true);
        $row = (new MarketModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'market', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }
}
