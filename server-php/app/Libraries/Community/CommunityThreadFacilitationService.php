<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use App\Models\CommunityAgentRunModel;

/**
 * thread_facilitator operational role.
 *
 * Posts value-add comments (clarifications, exceptions, comparisons) via the
 * public receiver's comment endpoint. Three guarantees this class exists
 * specifically to enforce, on top of the dispatcher's daily-cap/window gates:
 *
 *   - No self-reply: a facilitator identity can never reply to its own prior
 *     comment (checked against this identity's own run history).
 *   - Depth limit: never more than MAX_DEPTH levels into a thread.
 *   - Cooldown: a minimum gap between two comments from the same identity,
 *     so a burst of "helpful" replies never reads as spam.
 */
class CommunityThreadFacilitationService
{
    private const MAX_DEPTH        = 3;
    private const COOLDOWN_SECONDS = 600; // 10 minutes

    public function __construct(
        private CommunityPublisherInterface       $publisher = new CommunityPublicSitePublisher(),
        private readonly CommunityAgentRunModel    $runModel  = new CommunityAgentRunModel(),
    ) {
        $this->publisher = CommunityPublisherFactory::create();
    }

    /**
     * @param array{
     *   post_external_id: string,
     *   body: string,
     *   parent_comment_external_id?: string,
     *   depth?: int,
     *   facilitation_reason?: string
     * } $context
     */
    public function postComment(array $identity, array $context, ?int $actorId): array
    {
        $postExternalId = (string) ($context['post_external_id'] ?? '');
        $body           = trim((string) ($context['body'] ?? ''));
        $parentRef      = $context['parent_comment_external_id'] ?? null;
        $depth          = (int) ($context['depth'] ?? 0);

        if ($postExternalId === '' || $body === '') {
            throw new \InvalidArgumentException('CommunityThreadFacilitationService: post_external_id and body are required.');
        }

        if ($depth >= self::MAX_DEPTH) {
            throw new \RuntimeException(
                "Thread depth {$depth} would exceed the maximum of " . self::MAX_DEPTH . " — facilitator comments must stay shallow."
            );
        }

        $identityId = (int) $identity['id'];

        if (is_string($parentRef) && $parentRef !== '' && $this->runModel->wasAuthoredBy($identityId, $parentRef)) {
            throw new \RuntimeException(
                "Self-reply blocked: identity '{$identity['slug']}' authored comment {$parentRef} and cannot reply to itself."
            );
        }

        $last = $this->runModel->lastSuccessful($identityId, 'post_comment');
        if ($last !== null) {
            $elapsed = time() - strtotime((string) $last['created_at']);
            if ($elapsed < self::COOLDOWN_SECONDS) {
                throw new \RuntimeException(
                    "Cooldown active: identity '{$identity['slug']}' last commented " . $elapsed . "s ago " .
                    '(minimum ' . self::COOLDOWN_SECONDS . 's between comments).'
                );
            }
        }

        $idempotencyKey = bin2hex(random_bytes(16));
        $envelope = [
            'reach_external_id'   => bin2hex(random_bytes(8)) . '-' . $identityId,
            'post_external_id'    => $postExternalId,
            'parent_external_id'  => $parentRef,
            'body'                => $body,
            'official_identity_slug' => $identity['slug'],
            'ai_assisted'         => true,
            'facilitation_reason' => $context['facilitation_reason'] ?? 'value_add_comment',
            'idempotency_key'     => $idempotencyKey,
        ];

        $result = $this->publisher->createComment($envelope);
        if (! ($result['success'] ?? false)) {
            AuditLogger::record(AuditLogger::COMMUNITY_COMMENT_FAILED, [
                'facilitator_identity' => $identity['slug'],
                'post_external_id'     => $postExternalId,
                'error'                => $result['safe_error_message'] ?? 'unknown',
            ], $actorId);
            throw new \RuntimeException('Comment publication failed: ' . ($result['safe_error_message'] ?? 'unknown'));
        }

        AuditLogger::record(AuditLogger::COMMUNITY_COMMENT_POSTED, [
            'facilitator_identity' => $identity['slug'],
            'post_external_id'     => $postExternalId,
            'depth'                => $depth,
        ], $actorId);

        return [
            'comment_external_id' => $result['public_comment_id'] ?? $envelope['reach_external_id'],
            'post_external_id'    => $postExternalId,
            'depth'               => $depth,
        ];
    }
}
