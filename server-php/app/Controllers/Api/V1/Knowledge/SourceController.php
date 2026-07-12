<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\SourceModel;
use CodeIgniter\Model;
use Config\Enums;
use Config\Services;

class SourceController extends BaseKnowledgeController
{
    protected function model(): Model { return new SourceModel(); }
    protected function entityType(): string { return 'source'; }
    protected function writableFields(): array
    {
        return ['slug', 'name', 'url', 'source_type', 'authority_score', 'description', 'is_active', 'valid_from', 'valid_until'];
    }
    protected function urlFields(): array { return ['url']; }

    public function index()
    {
        return $this->listPaged(array_filter([
            'status'      => $this->request->getGet('status'),
            'source_type' => $this->request->getGet('source_type'),
            'q'           => $this->request->getGet('q'),
        ]));
    }

    public function show(int $id)    { return $this->showById($id); }

    public function store()
    {
        $body  = $this->input();
        $enums = new Enums();

        if (empty($body['name'])) { return $this->fail('name is required.', 422); }

        $sourceType = $body['source_type'] ?? 'internal';
        if (! $enums->isValid('sourceType', $sourceType)) {
            return $this->fail('Invalid source_type.', 422);
        }

        if (! empty($body['url'])) {
            $result = Services::urlPolicy()->validate((string) $body['url']);
            if (! $result->allowed) {
                return $this->fail('Rejected URL: ' . ($result->reason ?? 'invalid'), 422);
            }
        }

        $sanitizer = Services::htmlSanitizer();
        $slug      = $this->uniqueSlug($body['slug'] ?? $body['name']);
        $data = [
            'slug'              => $slug,
            'name'              => $sanitizer->purifyText((string) $body['name']),
            'url'               => $body['url'] ?? null,
            'source_type'       => $sourceType,
            'authority_score'   => isset($body['authority_score']) ? (int) $body['authority_score'] : null,
            'description'       => isset($body['description']) ? $sanitizer->purify((string) $body['description']) : null,
            'is_active'         => (bool) ($body['is_active'] ?? true),
            'valid_from'        => $body['valid_from'] ?? null,
            'valid_until'       => $body['valid_until'] ?? null,
            'status'            => 'draft',
            'created_by'        => $this->userId(),
            'created_actor_type'=> 'human',
            'request_id'        => $this->request->reachRequestId ?? null,
        ];
        $id  = (new SourceModel())->insert($data, true);
        $row = (new SourceModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'source', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }
}
