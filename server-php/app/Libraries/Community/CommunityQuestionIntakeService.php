<?php

namespace App\Libraries\Community;

use App\Enums\CommunityQuestionStatus;
use App\Libraries\AuditLogger;
use App\Libraries\SecretRedactor;
use App\Models\CommunityQuestionModel;
use RuntimeException;

/**
 * Accepts genuine questions from all intake sources and normalises them
 * into reach_community_questions.
 *
 * Source types: manual | import | content_request | official_question | public_submission
 */
class CommunityQuestionIntakeService
{
    public function __construct(
        private readonly CommunityQuestionRepository      $repo              = new CommunityQuestionRepository(),
        private readonly CommunityQuestionClassificationService $classifier  = new CommunityQuestionClassificationService(),
        private readonly CommunityTriageService           $triage            = new CommunityTriageService(),
        private readonly CommunityDuplicateDetectionService $duplicates      = new CommunityDuplicateDetectionService()
    ) {}

    /**
     * Intake a single question.
     *
     * @param array{
     *   source_type: string,
     *   title: string,
     *   body?: string,
     *   space_id?: int,
     *   language?: string,
     *   product?: string,
     *   category?: string,
     *   tags?: string[],
     *   jurisdiction?: string,
     *   source_url?: string,
     *   external_question_id?: string,
     *   author_reference?: string,
     *   author_display_consent?: bool,
     *   question_timestamp?: string,
     * } $data
     * @param int|null $actorId
     */
    public function intake(array $data, ?int $actorId = null): array
    {
        $this->validateIntakeData($data);

        $normalised = $this->normalise($data);
        $spamScore  = $this->estimateSpamScore($normalised);

        $normalised['spam_score']   = $spamScore;
        $normalised['status']       = CommunityQuestionStatus::Intake->value;
        $normalised['intake_timestamp'] = date('Y-m-d H:i:s');

        $id = $this->repo->save($normalised);
        $question = $this->repo->findById($id);

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_INTAKE, [
            'question_id'  => $id,
            'source_type'  => $normalised['source_type'],
            'title_length' => strlen($normalised['title']),
        ], $actorId);

        // Async classification and triage happen via job queue (CP11).
        // For synchronous intake (manual), run classification inline.
        if ($normalised['source_type'] === 'manual') {
            $question = $this->classifier->classifyInline($question);
            $question = $this->triage->scoreInline($question);
            $question = $this->duplicates->checkInline($question);
        }

        return $question;
    }

    /**
     * Import multiple genuine questions retaining source provenance.
     */
    public function importBatch(array $questions, string $sourceType, ?int $actorId = null): array
    {
        $results = [];
        foreach ($questions as $q) {
            $q['source_type'] = $sourceType;
            try {
                $results[] = ['status' => 'ok', 'question' => $this->intake($q, $actorId)];
            } catch (\Throwable $e) {
                $results[] = ['status' => 'error', 'error' => $e->getMessage(), 'input' => $q['title'] ?? ''];
            }
        }

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_IMPORT, [
            'source_type' => $sourceType,
            'total'       => count($questions),
            'ok'          => count(array_filter($results, fn($r) => $r['status'] === 'ok')),
        ], $actorId);

        return $results;
    }

    private function validateIntakeData(array $data): void
    {
        if (empty($data['title']) || strlen(trim($data['title'])) < 5) {
            throw new RuntimeException('Question title must be at least 5 characters.');
        }
        if (empty($data['source_type'])) {
            throw new RuntimeException('source_type is required.');
        }
        $allowed = ['manual', 'import', 'content_request', 'official_question', 'public_submission'];
        if (!in_array($data['source_type'], $allowed, true)) {
            throw new RuntimeException("Invalid source_type: {$data['source_type']}");
        }
    }

    private function normalise(array $data): array
    {
        return [
            'source_type'             => $data['source_type'],
            'title'                   => substr(trim($data['title']), 0, 512),
            'body'                    => substr(trim($data['body'] ?? ''), 0, 65535),
            'space_id'                => isset($data['space_id']) ? (int) $data['space_id'] : null,
            'language'                => substr($data['language'] ?? 'en', 0, 10),
            'product'                 => substr($data['product'] ?? '', 0, 120) ?: null,
            'category'                => substr($data['category'] ?? '', 0, 120) ?: null,
            'tags'                    => array_values(array_filter((array) ($data['tags'] ?? []))),
            'jurisdiction'            => substr($data['jurisdiction'] ?? '', 0, 80) ?: null,
            'source_url'              => $data['source_url'] ?? null,
            'external_question_id'    => $data['external_question_id'] ?? null,
            'author_reference'        => $data['author_reference'] ?? null,
            'author_display_consent'  => (bool) ($data['author_display_consent'] ?? false),
            'question_timestamp'      => $data['question_timestamp'] ?? null,
            'sensitivity_flags'       => [],
            'personal_data_detected'  => false,
            'moderation_state'        => 'clean',
        ];
    }

    private function estimateSpamScore(array $data): float
    {
        $score = 0.0;
        $title = strtolower($data['title']);
        $body  = strtolower($data['body'] ?? '');

        // Basic heuristics — production should use a proper spam classifier
        $spamPatterns = ['buy now', 'click here', 'free money', 'earn fast', 'make money online'];
        foreach ($spamPatterns as $pattern) {
            if (str_contains($title, $pattern) || str_contains($body, $pattern)) {
                $score += 0.3;
            }
        }

        // Very short body may be low-quality but not spam
        if (strlen($data['body'] ?? '') < 10) {
            $score += 0.1;
        }

        return min(1.0, round($score, 3));
    }
}
