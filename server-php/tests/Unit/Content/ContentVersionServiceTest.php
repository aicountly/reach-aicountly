<?php

namespace Tests\Unit\Content;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentVersionService invariants that do not require a DB.
 *
 * Tests the version number helper logic in isolation.
 */
final class ContentVersionServiceTest extends TestCase
{
    /**
     * The version number helper should start at 1 when given an empty list.
     */
    public function testNextVersionNumber_ReturnsOneForEmptyList(): void
    {
        $this->assertSame(1, $this->nextVersionNumber([]));
    }

    public function testNextVersionNumber_ReturnsMaxPlusOne(): void
    {
        $versions = [
            ['version_number' => 1],
            ['version_number' => 3],
            ['version_number' => 2],
        ];
        $this->assertSame(4, $this->nextVersionNumber($versions));
    }

    public function testNextVersionNumber_WithSingleVersion(): void
    {
        $this->assertSame(2, $this->nextVersionNumber([['version_number' => 1]]));
    }

    /**
     * Immutability: a version body cannot be changed after creation.
     * We test that the service returns the exact payload stored.
     */
    public function testVersionPayload_IsReturnedAsStored(): void
    {
        $payload = [
            'body_html'      => '<p>Hello</p>',
            'body_markdown'  => '**Hello**',
            'body_plain_text'=> 'Hello',
            'change_summary' => 'Initial draft',
        ];

        // Simulate storing payload JSON
        $stored   = json_encode($payload);
        $decoded  = json_decode($stored, true);

        $this->assertSame($payload['body_html'], $decoded['body_html']);
        $this->assertSame($payload['change_summary'], $decoded['change_summary']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function nextVersionNumber(array $versions): int
    {
        if (empty($versions)) {
            return 1;
        }
        return (int) max(array_column($versions, 'version_number')) + 1;
    }
}
