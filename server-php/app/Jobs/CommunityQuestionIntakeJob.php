<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\CommunityQuestionClassificationService;
use App\Libraries\Community\CommunityTriageService;
use App\Libraries\Community\CommunityDuplicateDetectionService;
use App\Libraries\AuditLogger;

/**
 * Phase 5 — Community Question Intake Job.
 *
 * Job type key: reach.community_question_intake
 *
 * Payload: { "source": "string", "source_question_id": "string", "title": "string",
 *            "body": "string", "author_external_ref": "string", "space_slug": "string" }
 *
 * Ingests a raw community question, classifies it, scores triage priority,
 * and detects duplicates. Never auto-generates an answer or auto-approves.
 */
class CommunityQuestionIntakeJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        if (empty($payload['title'])) {
            throw new \InvalidArgumentException('CommunityQuestionIntakeJob: title is required.');
        }

        $intakeSvc         = new CommunityQuestionIntakeService();
        $classificationSvc = new CommunityQuestionClassificationService();
        $triageSvc         = new CommunityTriageService();
        $dupSvc            = new CommunityDuplicateDetectionService();

        // 1. Ingest
        $question = $intakeSvc->ingest($payload);
        $uuid     = $question['external_id'] ?? '';

        // 2. Classify
        $classificationSvc->classify($uuid);

        // 3. Triage score
        $triageSvc->score($uuid);

        // 4. Duplicate detection
        $dupSvc->detectAndCluster($uuid);

        AuditLogger::log(AuditLogger::COMMUNITY_QUESTION_INGESTED, [
            'question_uuid' => $uuid,
            'source'        => $payload['source'] ?? 'job',
        ]);

        return ['ok' => true, 'question_uuid' => $uuid];
    }
}
