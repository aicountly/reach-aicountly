<?php

namespace App\Libraries\Community;

/**
 * Contract for community publishing clients.
 * All implementations must use HMAC-signed requests and never log secrets.
 */
interface CommunityPublisherInterface
{
    public function createQuestion(array $envelope): array;
    public function createAnswer(array $envelope): array;
    public function updateAnswer(string $answerUuid, array $envelope): array;
    public function publishAnswer(string $answerUuid, array $envelope): array;
    public function unpublishAnswer(string $answerUuid, array $envelope): array;
    public function withdrawAnswer(string $answerUuid, array $envelope): array;
    public function restoreAnswer(string $answerUuid, array $envelope): array;
    public function getAnswerStatus(string $answerUuid): array;
    public function getAnswerVerification(string $answerUuid): array;
    public function healthCheck(): bool;
}
