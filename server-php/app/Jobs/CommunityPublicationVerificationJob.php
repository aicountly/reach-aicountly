<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Community\CommunityPublicationVerificationService;
use App\Libraries\AuditLogger;

/**
 * Phase 5 — Community Publication Verification Job.
 *
 * Job type key: reach.community_publication_verification
 *
 * Payload: { "answer_id": <int> }
 *
 * Verifies that the published answer on the public site matches
 * the Reach records (checksum, URL, robots directive).
 */
class CommunityPublicationVerificationJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $answerId = isset($payload['answer_id']) ? (int) $payload['answer_id'] : 0;
        if ($answerId <= 0) {
            throw new \InvalidArgumentException('CommunityPublicationVerificationJob: answer_id is required.');
        }

        $verifySvc = new CommunityPublicationVerificationService();
        $result    = $verifySvc->verify($answerId);

        AuditLogger::log(AuditLogger::COMMUNITY_ANSWER_VERIFICATION_RUN, [
            'answer_id' => $answerId,
            'outcome'   => $result['outcome'] ?? 'unknown',
        ]);

        return ['ok' => true, 'answer_id' => $answerId, 'result' => $result];
    }
}
