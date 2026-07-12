<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\TopicClusterModel;
use CodeIgniter\Model;
use Config\Services;

class TopicClusterController extends BaseKnowledgeController
{
    protected function model(): Model { return new TopicClusterModel(); }
    protected function entityType(): string { return 'topic_cluster'; }
    protected function writableFields(): array { return ['slug', 'name', 'pillar_topic', 'description']; }

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
            'pillar_topic'      => isset($body['pillar_topic']) ? $sanitizer->purifyText((string) $body['pillar_topic']) : null,
            'description'       => isset($body['description']) ? $sanitizer->purify((string) $body['description']) : null,
            'status'            => 'draft',
            'created_by'        => $this->userId(),
            'created_actor_type'=> 'human',
            'request_id'        => $this->request->reachRequestId ?? null,
        ];
        $id  = (new TopicClusterModel())->insert($data, true);
        $row = (new TopicClusterModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'topic_cluster', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }
}
