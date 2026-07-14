<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Community\OfficialAnswerGenerationService;
use App\Libraries\Community\OfficialAnswerValidationService;
use App\Libraries\Community\OfficialAnswerModerationService;
use App\Libraries\AuditLogger;

/**
 * Phase 5 — Community Official Answer Generation Job.
 *
 * Job type key: reach.community_answer_generation
 *
 * Payload: { "answer_uuid": "string", "options": {} }
 *
 * Generates an AI-assisted draft for an official answer, validates it,
 * and runs moderation. Does NOT approve or publish — human approval required.
 */
class CommunityAnswerGenerationJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $answerUuid = $payload['answer_uuid'] ?? '';
        if (empty($answerUuid)) {
            throw new \InvalidArgumentException('CommunityAnswerGenerationJob: answer_uuid is required.');
        }

        $options = $payload['options'] ?? [];

        // 1. Generate
        $genSvc  = new OfficialAnswerGenerationService();
        $version = $genSvc->generate($answerUuid, $options);

        AuditLogger::log(AuditLogger::COMMUNITY_ANSWER_GENERATION_STARTED, [
            'answer_uuid' => $answerUuid,
        ]);

        // 2. Validate
        $validationSvc = new OfficialAnswerValidationService();
        $validationSvc->validate($answerUuid);

        // 3. Moderation
        $modSvc = new OfficialAnswerModerationService();
        $modSvc->moderate($answerUuid);

        AuditLogger::log(AuditLogger::COMMUNITY_ANSWER_GENERATION_COMPLETED, [
            'answer_uuid'    => $answerUuid,
            'version_number' => $version['version_number'] ?? null,
        ]);

        return ['ok' => true, 'answer_uuid' => $answerUuid];
    }
}
