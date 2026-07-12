<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\ContentPolicyModel;
use CodeIgniter\Model;
use Config\Enums;
use Config\Services;

class ContentPolicyController extends BaseKnowledgeController
{
    protected function model(): Model { return new ContentPolicyModel(); }
    protected function entityType(): string { return 'content_policy'; }
    protected function writableFields(): array
    {
        return ['name', 'policy_type', 'policy_text', 'applies_to_channels', 'is_mandatory'];
    }
    protected function htmlFields(): array { return ['policy_text']; }

    public function index()
    {
        return $this->listPaged(array_filter([
            'status'      => $this->request->getGet('status'),
            'policy_type' => $this->request->getGet('policy_type'),
            'q'           => $this->request->getGet('q'),
        ]));
    }

    public function show(int $id)    { return $this->showById($id); }

    public function store()
    {
        $body  = $this->input();
        $enums = new Enums();

        if (empty($body['name']))        { return $this->fail('name is required.', 422); }
        if (empty($body['policy_text'])) { return $this->fail('policy_text is required.', 422); }

        $policyType = $body['policy_type'] ?? 'brand';
        if (! $enums->isValid('contentPolicyType', $policyType)) {
            return $this->fail('Invalid policy_type.', 422);
        }

        $sanitizer = Services::htmlSanitizer();
        $data = [
            'name'                => $sanitizer->purifyText((string) $body['name']),
            'policy_type'         => $policyType,
            'policy_text'         => $sanitizer->purify((string) $body['policy_text']),
            'applies_to_channels' => isset($body['applies_to_channels']) ? json_encode($body['applies_to_channels']) : null,
            'is_mandatory'        => (bool) ($body['is_mandatory'] ?? false),
            'status'              => 'draft',
            'created_by'          => $this->userId(),
            'created_actor_type'  => 'human',
            'request_id'          => $this->request->reachRequestId ?? null,
        ];
        $id  = (new ContentPolicyModel())->insert($data, true);
        $row = (new ContentPolicyModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'content_policy', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }
}
