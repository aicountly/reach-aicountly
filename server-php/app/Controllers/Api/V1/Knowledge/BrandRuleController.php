<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\BrandRuleModel;
use CodeIgniter\Model;
use Config\Enums;
use Config\Services;

class BrandRuleController extends BaseKnowledgeController
{
    protected function model(): Model { return new BrandRuleModel(); }
    protected function entityType(): string { return 'brand_rule'; }
    protected function writableFields(): array
    {
        return ['product_id', 'rule_type', 'rule_text', 'applies_to', 'is_mandatory'];
    }
    protected function htmlFields(): array { return ['rule_text']; }

    public function index()
    {
        return $this->listPaged(array_filter([
            'product_id' => $this->request->getGet('product_id'),
            'status'     => $this->request->getGet('status'),
            'rule_type'  => $this->request->getGet('rule_type'),
        ]));
    }

    public function show(int $id)    { return $this->showById($id); }

    public function store()
    {
        $body  = $this->input();
        $enums = new Enums();

        if (empty($body['rule_text'])) { return $this->fail('rule_text is required.', 422); }

        $ruleType = $body['rule_type'] ?? 'tone';
        if (! $enums->isValid('brandRuleType', $ruleType)) {
            return $this->fail('Invalid rule_type.', 422);
        }

        $sanitizer = Services::htmlSanitizer();
        $data = [
            'product_id'  => isset($body['product_id']) ? (int) $body['product_id'] : null,
            'rule_type'   => $ruleType,
            'rule_text'   => $sanitizer->purify((string) $body['rule_text']),
            'applies_to'  => isset($body['applies_to']) ? json_encode($body['applies_to']) : null,
            'is_mandatory'=> (bool) ($body['is_mandatory'] ?? false),
            'status'      => 'draft',
            'created_by'  => $this->userId(),
            'created_actor_type' => 'human',
            'request_id'  => $this->request->reachRequestId ?? null,
        ];
        $id  = (new BrandRuleModel())->insert($data, true);
        $row = (new BrandRuleModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'brand_rule', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }
}
