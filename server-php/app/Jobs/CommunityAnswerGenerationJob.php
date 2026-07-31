<?php

namespace App\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Phase 5 — Community Official Answer Generation Job.
 *
 * Job type key: reach.community_answer_generation
 *
 * Payload: { "answer_uuid": "string", "answer_type": "detailed" }
 *
 * Generates an AI-assisted draft, validates it, runs moderation and routes it
 * to the correct human review queue. It never approves and never publishes.
 *
 * The job fails loudly when validation or moderation blocks the draft, so a
 * blocked answer can never be reported as a successful generation.
 */
class CommunityAnswerGenerationJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $answerUuid = (string) ($payload['answer_uuid'] ?? '');
        if ($answerUuid === '') {
            throw new \InvalidArgumentException('CommunityAnswerGenerationJob: answer_uuid is required.');
        }

        $answerType = (string) ($payload['answer_type'] ?? 'detailed');
        $actorId    = $ctx->enqueuedByUserId;
        $lifecycle  = new OfficialAnswerLifecycleService();

        $generation = $lifecycle->requestGeneration($answerUuid, $answerType, $actorId);
        $versionNo  = (int) ($generation['version']['version_number'] ?? 0);

        $validation = $lifecycle->validate($answerUuid, $versionNo ?: null);
        $moderation = $lifecycle->moderate($answerUuid, $versionNo ?: null, $actorId);

        if (($moderation['blocked'] ?? false) === true) {
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_GENERATION_FAILED, [
                'answer_uuid' => $answerUuid,
                'reason'      => 'moderation_blocked',
                'findings'    => count($moderation['findings'] ?? []),
            ], $actorId);

            throw new \RuntimeException(
                "Generated answer {$answerUuid} was blocked by moderation and cannot proceed to review."
            );
        }

        if (($validation['passed'] ?? false) !== true) {
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_VALIDATION_FAILED, [
                'answer_uuid' => $answerUuid,
                'findings'    => count($validation['findings'] ?? []),
            ], $actorId);

            throw new \RuntimeException(
                "Generated answer {$answerUuid} failed validation and cannot proceed to review."
            );
        }

        $review = $lifecycle->submitForReview($answerUuid, $actorId);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_GENERATION_COMPLETED, [
            'answer_uuid'    => $answerUuid,
            'version_number' => $versionNo,
            'review_queue'   => $review['status'] ?? null,
            'risk_tier'      => $review['risk_tier'] ?? null,
        ], $actorId);

        return [
            'ok'             => true,
            'answer_uuid'    => $answerUuid,
            'version_number' => $versionNo,
            'review_queue'   => $review['status'] ?? null,
            'risk_tier'      => $review['risk_tier'] ?? null,
        ];
    }
}
