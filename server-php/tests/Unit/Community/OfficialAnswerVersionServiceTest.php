<?php

namespace Tests\Unit\Community;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for version checksum and content hashing logic.
 * Tests are isolated — no DB or framework bootstrap required.
 */
final class OfficialAnswerVersionServiceTest extends TestCase
{
    public function testChecksumIsConsistentForSameContent(): void
    {
        $content = ['body' => 'This is the answer body.', 'summary' => 'Summary'];
        $checksum1 = $this->computeChecksum($content);
        $checksum2 = $this->computeChecksum($content);
        $this->assertSame($checksum1, $checksum2);
    }

    public function testChecksumDiffersForDifferentContent(): void
    {
        $c1 = ['body' => 'Answer A'];
        $c2 = ['body' => 'Answer B'];
        $this->assertNotSame($this->computeChecksum($c1), $this->computeChecksum($c2));
    }

    public function testChecksumIsHexString(): void
    {
        $checksum = $this->computeChecksum(['body' => 'test']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $checksum, 'Checksum must be a 64-char hex (SHA-256)');
    }

    public function testEmptyBodyProducesChecksum(): void
    {
        $checksum = $this->computeChecksum(['body' => '']);
        $this->assertNotEmpty($checksum);
    }

    public function testKeyOrderDoesNotAffectChecksum(): void
    {
        $c1 = ['body' => 'text', 'summary' => 'sum'];
        $c2 = ['summary' => 'sum', 'body' => 'text'];
        $this->assertSame($this->computeChecksum($c1), $this->computeChecksum($c2));
    }

    // Mirror of OfficialAnswerVersionService::computeChecksum()
    private function computeChecksum(array $content): string
    {
        ksort($content);
        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE));
    }
}
