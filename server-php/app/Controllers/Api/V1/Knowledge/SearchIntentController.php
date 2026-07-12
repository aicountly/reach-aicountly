<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Libraries\AuditLogger;
use App\Models\Knowledge\SearchIntentModel;
use App\Models\Knowledge\KnowledgeRelationModel;
use CodeIgniter\Model;
use Config\Enums;
use Config\Services;

class SearchIntentController extends BaseKnowledgeController
{
    protected function model(): Model { return new SearchIntentModel(); }
    protected function entityType(): string { return 'search_intent'; }
    protected function writableFields(): array
    {
        return ['slug', 'intent_text', 'intent_type', 'funnel_stage', 'search_volume', 'difficulty_score', 'notes'];
    }

    public function index()
    {
        return $this->listPaged(array_filter([
            'status'      => $this->request->getGet('status'),
            'intent_type' => $this->request->getGet('intent_type'),
            'funnel_stage'=> $this->request->getGet('funnel_stage'),
            'q'           => $this->request->getGet('q'),
        ]));
    }

    public function show(int $id)    { return $this->showById($id); }

    public function store()
    {
        $body  = $this->input();
        $enums = new Enums();

        if (empty($body['intent_text'])) { return $this->fail('intent_text is required.', 422); }
        if (! $enums->isValid('intentType', $body['intent_type'] ?? 'informational')) {
            return $this->fail('Invalid intent_type.', 422);
        }
        if (! $enums->isValid('funnelStage', $body['funnel_stage'] ?? 'top')) {
            return $this->fail('Invalid funnel_stage.', 422);
        }

        $sanitizer = Services::htmlSanitizer();
        $slug      = $this->uniqueSlug($body['slug'] ?? $body['intent_text']);
        $data = [
            'slug'             => $slug,
            'intent_text'      => $sanitizer->purifyText((string) $body['intent_text']),
            'intent_type'      => $body['intent_type'] ?? 'informational',
            'funnel_stage'     => $body['funnel_stage'] ?? 'top',
            'search_volume'    => isset($body['search_volume']) ? (int) $body['search_volume'] : null,
            'difficulty_score' => isset($body['difficulty_score']) ? (int) $body['difficulty_score'] : null,
            'notes'            => isset($body['notes']) ? $sanitizer->purifyText((string) $body['notes']) : null,
            'status'           => 'draft',
            'created_by'       => $this->userId(),
            'created_actor_type' => 'human',
            'request_id'       => $this->request->reachRequestId ?? null,
        ];
        $id  = (new SearchIntentModel())->insert($data, true);
        $row = (new SearchIntentModel())->find($id);
        $this->audit(AuditLogger::KNOWLEDGE_CREATED, 'search_intent', (int) $id, null, $row);
        return $this->ok($row, 201);
    }

    public function update(int $id)   { return $this->updateRecord($id); }
    public function destroy(int $id)  { return $this->deleteRecord($id); }
    public function submit(int $id)   { return $this->submitRecord($id); }
    public function approve(int $id)  { return $this->approveRecord($id); }
    public function reject(int $id)   { return $this->rejectRecord($id); }

    /** Sync related products/features/personas for an intent. */
    public function syncRelations(int $id)
    {
        $intent = (new SearchIntentModel())->find($id);
        if (! $intent) { return $this->fail('Search intent not found.', 404); }

        $body     = $this->input();
        $relation = new KnowledgeRelationModel();

        $mapping = [
            'product_ids' => ['reach_intent_products',       'intent_id', 'product_id'],
            'feature_ids' => ['reach_intent_features',       'intent_id', 'feature_id'],
            'persona_ids' => ['reach_intent_personas',       'intent_id', 'persona_id'],
            'cluster_ids' => ['reach_intent_topic_clusters', 'intent_id', 'cluster_id'],
        ];

        foreach ($mapping as $key => [$table, $parentCol, $relatedCol]) {
            if (array_key_exists($key, $body)) {
                $relation->sync($table, $parentCol, $id, $relatedCol,
                    array_map('intval', (array) $body[$key]), $this->userId());
                $this->audit(AuditLogger::KNOWLEDGE_RELATION_ADD, 'search_intent', $id,
                    null, null, [$key => $body[$key]]);
            }
        }
        return $this->ok(['synced' => true]);
    }
}
