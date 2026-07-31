<?php

namespace App\Libraries\Community;

/**
 * Contract for community publishing clients.
 * All implementations must use HMAC-signed requests and never log secrets.
 */
interface CommunityPublisherInterface
{
    public function createIdentity(array $envelope): array;
    public function updateIdentity(string $externalId, array $envelope): array;
    public function createQuestion(array $envelope): array;
    public function createAnswer(array $envelope): array;
    public function updateAnswer(string $answerUuid, array $envelope): array;
    public function publishAnswer(string $answerUuid, array $envelope): array;
    public function unpublishAnswer(string $answerUuid, array $envelope): array;
    public function withdrawAnswer(string $answerUuid, array $envelope): array;
    public function restoreAnswer(string $answerUuid, array $envelope): array;
    public function getAnswerStatus(string $answerUuid): array;
    public function getAnswerVerification(string $answerUuid): array;
    public function createComment(array $envelope): array;
    public function applyCorrection(array $envelope): array;
    public function applyModerationAction(array $envelope): array;
    public function getRecord(string $externalId): array;
    public function reconcile(array $externalIds): array;

    /**
     * Pull questions/answers/comments changed on the public site since a
     * given ISO-8601 timestamp — the "sync-public"/"sync-moderation" pull
     * path, since moderation and status changes originate on the public
     * site (real users reporting content, public moderators acting) and
     * must be mirrored back into Reach's own records.
     */
    public function getChanges(string $sinceIso8601, int $limit = 500): array;

    public function healthCheck(): bool;
}
