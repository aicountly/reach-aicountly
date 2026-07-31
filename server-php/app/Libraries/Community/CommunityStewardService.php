<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use App\Models\CommunityModerationFindingModel;
use App\Models\CommunityQuestionModel;

/**
 * community_steward operational role.
 *
 * Categorises questions, links related questions, and raises moderation
 * hints for a human to review. This class deliberately has NO method that
 * can cast a vote, like, follow, or inflate a view count — the plan requires
 * the steward never manufacture engagement, and the strongest way to
 * guarantee that in code is to never expose the capability at all rather
 * than gate it at call time. CommunityStewardServiceGuardrailTest asserts
 * this via reflection so the guarantee cannot silently regress.
 */
class CommunityStewardService
{
    private const MAX_RELATED_LINKS = 5;
    private const RELATED_THRESHOLD = 0.45; // looser than exact-duplicate detection (0.72)

    public function __construct(
        private readonly CommunityQuestionModel             $questionModel = new CommunityQuestionModel(),
        private readonly CommunityDuplicateDetectionService  $similarity    = new CommunityDuplicateDetectionService(),
        private readonly CommunityModerationFindingModel     $findingModel  = new CommunityModerationFindingModel(),
    ) {}

    /**
     * @param array{question_id:int, category?:string, tags?:list<string>} $context
     */
    public function categorize(array $identity, array $context, ?int $actorId): array
    {
        $questionId = (int) ($context['question_id'] ?? 0);
        if ($questionId <= 0) {
            throw new \InvalidArgumentException('CommunityStewardService::categorize requires question_id.');
        }

        // Raw query builder rather than the Model: the `tags` column is a
        // native Postgres TEXT[], but CommunityQuestionModel casts it as
        // 'json-array' for read/display purposes elsewhere — going through
        // Model::update() here would double-encode the array literal.
        $db     = db_connect();
        $update = [];
        if (isset($context['category']) && trim((string) $context['category']) !== '') {
            $update['category'] = trim((string) $context['category']);
        }
        if (isset($context['tags']) && is_array($context['tags'])) {
            $tags = array_slice(array_values($context['tags']), 0, 10);
            $update['tags'] = '{' . implode(',', array_map(
                static fn ($t) => '"' . str_replace('"', '\\"', (string) $t) . '"',
                $tags
            )) . '}';
        }

        if ($update === []) {
            return ['question_id' => $questionId, 'changed' => false];
        }

        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('reach_community_questions')->where('id', $questionId)->update($update);

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_STATUS_CHANGED, [
            'question_id'      => $questionId,
            'steward_identity' => $identity['slug'],
            'category'         => $update['category'] ?? null,
        ], $actorId);

        return ['question_id' => $questionId, 'changed' => true];
    }

    /**
     * @param array{question_id:int} $context
     */
    public function linkRelated(array $identity, array $context, ?int $actorId): array
    {
        $questionId = (int) ($context['question_id'] ?? 0);
        $question   = $this->questionModel->find($questionId);
        if ($question === null) {
            throw new \RuntimeException("CommunityStewardService::linkRelated: question #{$questionId} not found.");
        }

        $candidates = array_filter(
            $this->similarity->findCandidates($question),
            static fn (array $c) => ((float) $c['similarity']) >= self::RELATED_THRESHOLD
        );
        $candidates = array_slice($candidates, 0, self::MAX_RELATED_LINKS);

        $db      = db_connect();
        $linked  = [];
        foreach ($candidates as $candidate) {
            $relatedId = (int) $candidate['id'];
            $db->query(
                'INSERT INTO reach_community_question_related_links
                    (question_id, related_question_id, similarity, created_by_identity_id, created_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON CONFLICT (question_id, related_question_id) DO UPDATE SET
                    similarity = EXCLUDED.similarity',
                [$questionId, $relatedId, (float) $candidate['similarity'], (int) $identity['id']]
            );
            $linked[] = $relatedId;
        }

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_STATUS_CHANGED, [
            'question_id'      => $questionId,
            'steward_identity' => $identity['slug'],
            'related_linked'   => count($linked),
        ], $actorId);

        return ['question_id' => $questionId, 'related_question_ids' => $linked];
    }

    /**
     * Raise a moderation hint for human review. This never blocks or
     * decides anything by itself — OfficialAnswerModerationService's
     * rule-based scan remains the only thing that can auto-block publication.
     *
     * @param array{question_id?:int, answer_version_id?:int, finding_type:string, severity?:string, detail:string} $context
     */
    public function flagModerationHint(array $identity, array $context, ?int $actorId): array
    {
        $findingType = (string) ($context['finding_type'] ?? '');
        $detail      = (string) ($context['detail'] ?? '');
        if ($findingType === '' || $detail === '') {
            throw new \InvalidArgumentException('CommunityStewardService::flagModerationHint requires finding_type and detail.');
        }

        $id = $this->findingModel->insert([
            'answer_version_id' => $context['answer_version_id'] ?? null,
            'question_id'       => $context['question_id'] ?? null,
            'finding_type'      => $findingType,
            'severity'          => $context['severity'] ?? 'info',
            'details'           => json_encode(['detail' => $detail, 'raised_by' => $identity['slug'], 'role' => 'community_steward']),
            'status'            => 'open',
            'created_at'        => date('Y-m-d H:i:s'),
        ], true);

        AuditLogger::record(AuditLogger::COMMUNITY_MODERATION_FINDING_ESCALATED, [
            'finding_id'       => $id,
            'steward_identity' => $identity['slug'],
            'finding_type'     => $findingType,
        ], $actorId);

        return ['finding_id' => $id];
    }
}
