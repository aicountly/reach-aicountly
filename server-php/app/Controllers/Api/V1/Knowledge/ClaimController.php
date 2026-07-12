<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\ProductClaimModel;
use App\Models\Knowledge\EvidenceModel;
use App\Models\Knowledge\KnowledgeRelationModel;
use CodeIgniter\Model;
use Config\Enums;
use Config\Services;

class ClaimController extends BaseKnowledgeController
{
    protected function model(): Model { return new ProductClaimModel(); }
    protected function entityType(): string { return 'product_claim'; }
    protected function writableFields(): array
    {
        return ['product_id', 'claim_text', 'claim_summary', 'risk_level', 'requires_evidence', 'valid_from', 'valid_until'];
    }
    protected function htmlFields(): array { return ['claim_text']; }

    public function index()
    {
        return $this->listPaged(array_filter([
            'product_id' => $this->request->getGet('product_id'),
            'status'     => $this->request->getGet('status'),
            'risk_level' => $this->request->getGet('risk_level'),
            'q'          => $this->request->getGet('q'),
        ]));
    }

    public function show(int $id)    { return $this->showById($id); }

    public function store()
    {
        $body  = $this->input();
        $enums = new Enums();

        if (empty($body['product_id'])) { return $this->fail('product_id is required.', 422); }
        if (empty($body['claim_text'])) { return $this->fail('claim_text is required.', 422); }

        $risk = $body['risk_level'] ?? 'medium';
        if (! $enums->isValid('claimRisk', $risk)) {
            return $this->fail('Invalid risk_level.', 422);
        }

        $sanitizer = Services::htmlSanitizer();
        $data = [
            'product_id'        => (int) $body['product_id'],
            'claim_text'        => $sanitizer->purify((string) $body['claim_text']),
            'claim_summary'     => isset($body['claim_summary']) ? $sanitizer->purifyText((string) $body['claim_summary']) : null,
            'risk_level'        => $risk,
            'requires_evidence' => (bool) ($body['requires_evidence'] ?? true),
            'valid_from'        => $body['valid_from'] ?? null,
            'valid_until'       => $body['valid_until'] ?? null,
            'status'            => 'draft',
            'created_by'        => $this->userId(),
            'created_actor_type'=> 'human',
            'request_id'        => $this->request->reachRequestId ?? null,
        ];
        $id  = (new ProductClaimModel())->insert($data, true);
        $row = (new ProductClaimModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'product_claim', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }

    /**
     * Approve a claim. High-risk and critical claims require approved evidence.
     */
    public function approve(int $id)
    {
        $claimModel = new ProductClaimModel();
        $claim      = $claimModel->find($id);
        if (! $claim) { return $this->fail('Claim not found.', 404); }
        if ($claim['status'] !== 'needs_review') {
            return $this->fail('Only needs_review claims can be approved.', 422);
        }

        if (in_array($claim['risk_level'], ['high', 'critical'], true) || $claim['requires_evidence']) {
            $evCount = $claimModel->approvedEvidenceCount($id);
            if ($evCount === 0) {
                $this->audit(
                    AuditLogger::KNOWLEDGE_CLAIM_HIGH_RISK_BLOCKED,
                    'product_claim', $id, null, null,
                    ['risk_level' => $claim['risk_level'], 'evidence_count' => 0]
                );
                return $this->fail(
                    'High-risk and critical claims require at least one piece of approved evidence before approval.',
                    422,
                    ['risk_level' => $claim['risk_level'], 'evidence_count' => 0]
                );
            }
        }
        return $this->approveRecord($id);
    }

    /** Attach/detach evidence from a claim. */
    public function syncEvidence(int $id)
    {
        $claim = (new ProductClaimModel())->find($id);
        if (! $claim) { return $this->fail('Claim not found.', 404); }

        $body = $this->input();
        (new KnowledgeRelationModel())->sync(
            'reach_claim_evidence', 'claim_id', $id, 'evidence_id',
            array_map('intval', (array) ($body['evidence_ids'] ?? [])),
            $this->userId()
        );
        $this->audit(AuditLogger::KNOWLEDGE_RELATION_ADD, 'product_claim', $id,
            null, null, ['evidence_ids' => $body['evidence_ids'] ?? []]);
        return $this->ok(['synced' => true]);
    }
}
