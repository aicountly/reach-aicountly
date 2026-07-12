<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\EvidenceModel;
use CodeIgniter\Model;
use Config\Enums;
use Config\Services;

class EvidenceController extends BaseKnowledgeController
{
    protected function model(): Model { return new EvidenceModel(); }
    protected function entityType(): string { return 'evidence'; }
    protected function writableFields(): array
    {
        return ['slug', 'title', 'summary', 'evidence_type', 'source_id', 'external_url', 'valid_from', 'valid_until'];
    }
    protected function urlFields(): array { return ['external_url']; }

    public function index()
    {
        return $this->listPaged(array_filter([
            'status'        => $this->request->getGet('status'),
            'evidence_type' => $this->request->getGet('evidence_type'),
            'q'             => $this->request->getGet('q'),
        ]));
    }

    public function show(int $id)    { return $this->showById($id); }

    public function store()
    {
        $body  = $this->input();
        $enums = new Enums();

        if (empty($body['title'])) { return $this->fail('title is required.', 422); }

        $evType = $body['evidence_type'] ?? 'internal';
        if (! $enums->isValid('evidenceType', $evType)) {
            return $this->fail('Invalid evidence_type.', 422);
        }

        if (! empty($body['external_url'])) {
            $result = Services::urlPolicy()->validate((string) $body['external_url']);
            if (! $result->allowed) {
                return $this->fail('Rejected external_url: ' . ($result->reason ?? 'invalid'), 422);
            }
        }

        $sanitizer = Services::htmlSanitizer();
        $slug      = $this->uniqueSlug($body['slug'] ?? $body['title']);
        $data = [
            'slug'              => $slug,
            'title'             => $sanitizer->purifyText((string) $body['title']),
            'summary'           => isset($body['summary']) ? $sanitizer->purify((string) $body['summary']) : null,
            'evidence_type'     => $evType,
            'source_id'         => isset($body['source_id']) ? (int) $body['source_id'] : null,
            'external_url'      => $body['external_url'] ?? null,
            'valid_from'        => $body['valid_from'] ?? null,
            'valid_until'       => $body['valid_until'] ?? null,
            'status'            => 'draft',
            'created_by'        => $this->userId(),
            'created_actor_type'=> 'human',
            'request_id'        => $this->request->reachRequestId ?? null,
        ];
        $id  = (new EvidenceModel())->insert($data, true);
        $row = (new EvidenceModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'evidence', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }
}
