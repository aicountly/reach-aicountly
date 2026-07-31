<?php

namespace App\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Community\CommunityDuplicateDetectionService;
use App\Libraries\Community\CommunityQuestionClassificationService;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\CommunityTriageService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Phase 5 — Community Question Intake Job.
 *
 * Job type key: reach.community_question_intake
 *
 * Payload: { source_type, title, body?, space_id?, language?, product?,
 *            category?, tags?, jurisdiction?, source_url?,
 *            external_question_id?, author_reference? }
 *
 * Ingests a question, classifies it, scores triage priority and records
 * duplicate candidates. Never generates an answer and never auto-approves.
 */
class CommunityQuestionIntakeJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        if (empty($payload['title'])) {
            throw new \InvalidArgumentException('CommunityQuestionIntakeJob: title is required.');
        }

        $actorId = $ctx->enqueuedByUserId;

        // Intake runs classification/triage/duplicates inline only for manual
        // source types, so the job drives them explicitly by numeric ID.
        $question   = (new CommunityQuestionIntakeService())->intake($payload, $actorId);
        $questionId = (int) $question['id'];

        $classification = (new CommunityQuestionClassificationService())->classifyById($questionId);
        $triageScore    = (new CommunityTriageService())->scoreById($questionId);
        $duplicates     = (new CommunityDuplicateDetectionService())->checkInline($question);

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_INGESTED, [
            'question_id'      => $questionId,
            'question_uuid'    => $question['uuid'] ?? null,
            'source_type'      => $payload['source_type'] ?? 'job',
            'risk'             => $classification['risk_classification'] ?? null,
            'triage_score'     => $triageScore,
            'duplicate_count'  => count($duplicates['duplicate_candidates'] ?? []),
        ], $actorId);

        return [
            'ok'              => true,
            'question_id'     => $questionId,
            'question_uuid'   => $question['uuid'] ?? null,
            'triage_score'    => $triageScore,
            'duplicate_count' => count($duplicates['duplicate_candidates'] ?? []),
        ];
    }
}
