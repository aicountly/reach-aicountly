<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\CitationModel;
use CodeIgniter\Model;
use Config\Services;

class CitationController extends BaseKnowledgeController
{
    protected function model(): Model { return new CitationModel(); }
    protected function entityType(): string { return 'citation'; }
    protected function writableFields(): array
    {
        return ['source_id', 'evidence_id', 'citation_text', 'page_reference', 'accessed_at'];
    }

    public function index()
    {
        return $this->listPaged(array_filter([
            'source_id' => $this->request->getGet('source_id'),
            'status'    => $this->request->getGet('status'),
        ]));
    }

    public function show(int $id)    { return $this->showById($id); }

    public function store()
    {
        $body = $this->input();
        if (empty($body['source_id'])) { return $this->fail('source_id is required.', 422); }

        $sanitizer = Services::htmlSanitizer();
        $data = [
            'source_id'       => (int) $body['source_id'],
            'evidence_id'     => isset($body['evidence_id']) ? (int) $body['evidence_id'] : null,
            'citation_text'   => isset($body['citation_text']) ? $sanitizer->purify((string) $body['citation_text']) : null,
            'page_reference'  => $body['page_reference'] ?? null,
            'accessed_at'     => $body['accessed_at'] ?? null,
            'status'          => 'draft',
            'created_by'      => $this->userId(),
            'request_id'      => $this->request->reachRequestId ?? null,
        ];
        $id  = (new CitationModel())->insert($data, true);
        $row = (new CitationModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'citation', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }
}
