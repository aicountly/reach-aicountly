<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Security;

use App\Libraries\Ai\Security\PiiScrubber;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Security\PiiScrubber
 */
class PiiScrubberTest extends CIUnitTestCase
{
    private PiiScrubber $scrubber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scrubber = new PiiScrubber();
    }

    public function testCleanTextPassesThrough(): void
    {
        $text = 'Our product helps teams collaborate on financial reporting.';
        $this->assertSame($text, $this->scrubber->scrub($text));
        $this->assertFalse($this->scrubber->contains($text));
    }

    public function testEmailScrubbed(): void
    {
        $result = $this->scrubber->scrub('Contact us at john.doe@example.com for more info.');
        $this->assertStringContainsString('[EMAIL]', $result);
        $this->assertStringNotContainsString('john.doe@example.com', $result);
    }

    public function testEmailDetected(): void
    {
        $this->assertTrue($this->scrubber->contains('Send to admin@company.io'));
    }

    public function testCreditCardScrubbed(): void
    {
        $result = $this->scrubber->scrub('Card: 4532015112830366');
        $this->assertStringContainsString('[CARD_NUMBER]', $result);
    }

    public function testIpAddressScrubbed(): void
    {
        $result = $this->scrubber->scrub('Server IP is 192.168.1.100');
        $this->assertStringContainsString('[IP_ADDRESS]', $result);
    }

    public function testScrubArrayRecursive(): void
    {
        $data = [
            'user' => 'Alice',
            'contact' => ['email' => 'alice@test.com', 'note' => 'no pii here'],
            'count' => 42,
        ];
        $result = $this->scrubber->scrubArray($data);
        $this->assertStringContainsString('[EMAIL]', $result['contact']['email']);
        $this->assertSame('no pii here', $result['contact']['note']);
        $this->assertSame(42, $result['count']);
    }
}
