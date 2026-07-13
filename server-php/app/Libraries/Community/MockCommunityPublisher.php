<?php

namespace App\Libraries\Community;

/**
 * Test/mock community publisher.
 *
 * Records all calls for assertion in tests.
 * Can be configured to return errors for specific operations.
 *
 * Usage in tests:
 *   $mock = new MockCommunityPublisher();
 *   $factory = new CommunityPublisherFactory($mock);
 */
class MockCommunityPublisher implements CommunityPublisherInterface
{
    /** @var array<array{method: string, args: array, response: array}> */
    private array $calls = [];
    private array $errorOverrides = [];
    private int $nextPublicAnswerId = 1000;

    public function createQuestion(array $envelope): array
    {
        return $this->record(__FUNCTION__, [$envelope], [
            'success'             => true,
            'operation'           => 'create_question',
            'public_question_id'  => $this->nextPublicAnswerId++,
            'public_question_slug' => 'mock-question-' . uniqid(),
            'idempotent_replay'   => false,
            'request_id'          => 'mock-' . uniqid(),
        ]);
    }

    public function createAnswer(array $envelope): array
    {
        return $this->record(__FUNCTION__, [$envelope], [
            'success'            => true,
            'operation'          => 'create_answer',
            'public_answer_id'   => $this->nextPublicAnswerId,
            'public_answer_uuid' => $envelope['reach_answer_uuid'] ?? ('mock-' . uniqid()),
            'public_status'      => 'draft',
            'idempotent_replay'  => false,
            'request_id'         => 'mock-' . uniqid(),
        ]);
    }

    public function updateAnswer(string $answerUuid, array $envelope): array
    {
        return $this->record(__FUNCTION__, [$answerUuid, $envelope], [
            'success'    => true,
            'operation'  => 'update_answer',
            'request_id' => 'mock-' . uniqid(),
        ]);
    }

    public function publishAnswer(string $answerUuid, array $envelope): array
    {
        return $this->record(__FUNCTION__, [$answerUuid, $envelope], [
            'success'        => true,
            'operation'      => 'publish',
            'public_status'  => 'published',
            'canonical_url'  => 'https://aicountly.com/community/question/mock#official-answer',
            'public_version' => 1,
            'published_at'   => date('c'),
            'sitemap_status' => 'included',
            'request_id'     => 'mock-' . uniqid(),
        ]);
    }

    public function unpublishAnswer(string $answerUuid, array $envelope): array
    {
        return $this->record(__FUNCTION__, [$answerUuid, $envelope], [
            'success'       => true,
            'operation'     => 'unpublish',
            'public_status' => 'unpublished',
            'request_id'    => 'mock-' . uniqid(),
        ]);
    }

    public function withdrawAnswer(string $answerUuid, array $envelope): array
    {
        return $this->record(__FUNCTION__, [$answerUuid, $envelope], [
            'success'       => true,
            'operation'     => 'withdraw',
            'public_status' => 'withdrawn',
            'withdrawn_at'  => date('c'),
            'request_id'    => 'mock-' . uniqid(),
        ]);
    }

    public function restoreAnswer(string $answerUuid, array $envelope): array
    {
        return $this->record(__FUNCTION__, [$answerUuid, $envelope], [
            'success'       => true,
            'operation'     => 'restore',
            'public_status' => 'published',
            'request_id'    => 'mock-' . uniqid(),
        ]);
    }

    public function getAnswerStatus(string $answerUuid): array
    {
        return $this->record(__FUNCTION__, [$answerUuid], [
            'success'           => true,
            'public_status'     => 'published',
            'public_version'    => 1,
            'payload_checksum'  => hash('sha256', 'mock-content'),
            'ai_assisted'       => true,
            'human_reviewed'    => true,
            'correction_note'   => null,
            'withdrawn_at'      => null,
            'request_id'        => 'mock-' . uniqid(),
        ]);
    }

    public function getAnswerVerification(string $answerUuid): array
    {
        return $this->record(__FUNCTION__, [$answerUuid], [
            'success'          => true,
            'operation'        => 'verify',
            'public_status'    => 'published',
            'public_version'   => 1,
            'payload_checksum' => hash('sha256', 'mock-content'),
            'sitemap_status'   => 'included',
            'robots_directive' => 'index,follow',
            'request_id'       => 'mock-' . uniqid(),
        ]);
    }

    public function healthCheck(): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------------

    /** @return array<array{method: string, args: array, response: array}> */
    public function getCalls(): array
    {
        return $this->calls;
    }

    public function getCallsFor(string $method): array
    {
        return array_values(array_filter($this->calls, fn($c) => $c['method'] === $method));
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function wasCalledWith(string $method): bool
    {
        return !empty($this->getCallsFor($method));
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->errorOverrides = [];
    }

    public function setErrorFor(string $method, string $errorCategory): void
    {
        $this->errorOverrides[$method] = $errorCategory;
    }

    private function record(string $method, array $args, array $defaultResponse): array
    {
        if (isset($this->errorOverrides[$method])) {
            $response = [
                'success'            => false,
                'error_category'     => $this->errorOverrides[$method],
                'safe_error_message' => 'Mock error: ' . $this->errorOverrides[$method],
            ];
        } else {
            $response = $defaultResponse;
        }

        $this->calls[] = ['method' => $method, 'args' => $args, 'response' => $response];
        return $response;
    }
}
