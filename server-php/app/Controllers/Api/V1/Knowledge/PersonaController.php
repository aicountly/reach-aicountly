<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\PersonaModel;
use CodeIgniter\Model;
use Config\Services;

class PersonaController extends BaseKnowledgeController
{
    protected function model(): Model { return new PersonaModel(); }
    protected function entityType(): string { return 'persona'; }
    protected function writableFields(): array
    {
        return ['slug', 'name', 'role_title', 'description', 'pain_points', 'goals'];
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
            'slug'              => $slug,
            'name'              => $sanitizer->purifyText((string) $body['name']),
            'role_title'        => isset($body['role_title']) ? $sanitizer->purifyText((string) $body['role_title']) : null,
            'description'       => isset($body['description']) ? $sanitizer->purify((string) $body['description']) : null,
            'pain_points'       => isset($body['pain_points']) ? json_encode($body['pain_points']) : null,
            'goals'             => isset($body['goals']) ? json_encode($body['goals']) : null,
            'status'            => 'draft',
            'created_by'        => $this->userId(),
            'created_actor_type'=> 'human',
            'request_id'        => $this->request->reachRequestId ?? null,
        ];
        $id  = (new PersonaModel())->insert($data, true);
        $row = (new PersonaModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'persona', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }
}
