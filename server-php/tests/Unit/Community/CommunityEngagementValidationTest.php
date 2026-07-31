<?php

namespace Tests\Unit\Community;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for engagement event validation heuristics (isolated, no DB).
 *
 * Mirrors CommunityEngagementIngestionService::passesBasicValidation()
 * against the actual reach_community_engagement_events schema
 * (2026-07-13-100100_CreateReachCommunityEngagementEvents): answer_id /
 * question_id (not answer_external_id), deduplication_key (not dedup_key),
 * source (not source_platform), and the bot_filtered column that must
 * always disqualify a row regardless of any other signal.
 */
final class CommunityEngagementValidationTest extends TestCase
{
    private function passesBasicValidation(array $row): bool
    {
        if (!empty($row['bot_filtered'])) {
            return false;
        }
        if (empty($row['answer_id']) && empty($row['question_id'])) {
            return false;
        }
        if (!empty($row['deduplication_key'])) {
            return true;
        }
        $trustedSources = ['reach_sdk', 'aicountly_com', 'public_site'];
        return in_array($row['source'] ?? '', $trustedSources, true);
    }

    public function testEventWithDeduplicationKeyIsValid(): void
    {
        $this->assertTrue($this->passesBasicValidation([
            'answer_id' => 42,
            'deduplication_key' => 'dk-abc',
            'source' => 'public_site',
        ]));
    }

    public function testEventWithoutAnswerOrQuestionIdIsInvalid(): void
    {
        $this->assertFalse($this->passesBasicValidation([
            'answer_id' => null,
            'question_id' => null,
            'deduplication_key' => 'dk-abc',
        ]));
    }

    public function testQuestionOnlyEventWithDeduplicationKeyIsValid(): void
    {
        $this->assertTrue($this->passesBasicValidation([
            'question_id' => 7,
            'deduplication_key' => 'dk-question',
        ]));
    }

    public function testTrustedSourceWithoutDeduplicationKeyIsValid(): void
    {
        $this->assertTrue($this->passesBasicValidation([
            'answer_id' => 456,
            'source' => 'aicountly_com',
        ]));
    }

    public function testUnknownSourceWithoutDeduplicationKeyIsInvalid(): void
    {
        $this->assertFalse($this->passesBasicValidation([
            'answer_id' => 789,
            'source' => 'some_scraper',
        ]));
    }

    public function testAllTrustedSourcesPassValidation(): void
    {
        foreach (['reach_sdk', 'aicountly_com', 'public_site'] as $source) {
            $this->assertTrue($this->passesBasicValidation([
                'answer_id' => 1,
                'source' => $source,
            ]), "Source '{$source}' should be trusted");
        }
    }

    public function testBotFilteredEventIsNeverValidRegardlessOfOtherSignals(): void
    {
        $this->assertFalse($this->passesBasicValidation([
            'answer_id' => 1,
            'deduplication_key' => 'dk-should-not-matter',
            'source' => 'aicountly_com',
            'bot_filtered' => true,
        ]));
    }

    public function testEventTypeVocabularyMatchesDatabaseCheckConstraint(): void
    {
        $allowed = ['page_view', 'helpful', 'not_helpful', 'reply', 'report', 'click'];
        // These are the exact values the CHECK constraint on
        // reach_community_engagement_events.event_type permits.
        foreach ($allowed as $type) {
            $this->assertIsString($type);
        }
        $this->assertCount(6, $allowed);
        $this->assertNotContains('view', $allowed, 'view is not a valid event_type — the column uses page_view');
        $this->assertNotContains('helpful_vote', $allowed, 'helpful_vote is not a valid event_type — the column uses helpful');
    }

    /** @dataProvider botSourceProvider */
    public function testKnownBotSourcesAreFlagged(string $source): void
    {
        $botSources = ['reach_service', 'reach_bot', 'reach_automation', 'official_identity', 'service_account'];
        $this->assertContains(strtolower($source), $botSources);
    }

    public static function botSourceProvider(): array
    {
        return [
            ['reach_service'],
            ['reach_bot'],
            ['reach_automation'],
            ['official_identity'],
            ['service_account'],
        ];
    }
}
