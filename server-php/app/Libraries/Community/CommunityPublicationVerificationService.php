<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;

/**
 * Verifies that a published answer's public site state matches Reach records.
 */
class CommunityPublicationVerificationService
{
    public function __construct(
        private readonly OfficialAnswerRepository $answerRepo = new OfficialAnswerRepository(),
        private CommunityPublisherInterface $publisher = new CommunityPublicSitePublisher()
    ) {
        $this->publisher = CommunityPublisherFactory::create();
    }

    public function verify(int $answerId, ?int $actorId = null): array
    {
        $answer = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new \RuntimeException("Answer #{$answerId} not found");
        }

        $remoteStatus = $this->publisher->getAnswerVerification($answer['uuid']);

        $checksumMatch = hash_equals(
            $answer['approved_version_checksum'] ?? '',
            $remoteStatus['payload_checksum'] ?? ''
        );

        $outcome = match (true) {
            !($remoteStatus['success'] ?? false)  => 'not_found',
            !$checksumMatch                        => 'mismatch',
            ($remoteStatus['public_status'] ?? '') === 'published' => 'passed',
            default                                => 'failed',
        };

        // Store verification result
        db_connect()->table('reach_community_answer_verifications')->insert([
            'answer_id'            => $answerId,
            'verified_at'          => date('Y-m-d H:i:s'),
            'public_status'        => $remoteStatus['public_status'] ?? null,
            'checksum_match'       => $checksumMatch,
            'expected_checksum'    => $answer['approved_version_checksum'] ?? null,
            'actual_checksum'      => $remoteStatus['payload_checksum'] ?? null,
            'canonical_url_ok'     => !empty($remoteStatus['canonical_url']),
            'robots_ok'            => ($remoteStatus['robots_directive'] ?? '') === 'index,follow',
            'sitemap_ok'           => ($remoteStatus['sitemap_status'] ?? '') === 'included',
            'verification_outcome' => $outcome,
            'details'              => json_encode($remoteStatus),
            'created_at'           => date('Y-m-d H:i:s'),
        ]);

        $event = $outcome === 'passed'
            ? AuditLogger::COMMUNITY_ANSWER_VERIFICATION_PASSED
            : AuditLogger::COMMUNITY_ANSWER_VERIFICATION_FAILED;

        AuditLogger::record($event, [
            'answer_id' => $answerId,
            'outcome'   => $outcome,
        ], $actorId);

        return ['outcome' => $outcome, 'checksum_match' => $checksumMatch, 'details' => $remoteStatus];
    }
}
