<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\ChannelMessage;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SocialPublisherServiceTest extends CIUnitTestCase
{
    public function testMockPublisherAcceptsValidMessage(): void
    {
        $publisher = ChannelProviderFactory::makeSocialPublisher();
        $message   = new ChannelMessage(
            idempotencyKey:   'social-cp5-test-001',
            recipientAddress: 'linkedin-company-page',
            content:          'Phase 7 distribution is live. #aicountly',
        );
        $receipt = $publisher->publish($message, 'linkedin');
        $this->assertSame('accepted', $receipt->status);
        $this->assertNotEmpty($receipt->providerMessageId);
        $this->assertTrue($receipt->isAccepted());
    }

    public function testMockPublisherGetCapabilitiesReturnsPlatforms(): void
    {
        $publisher = ChannelProviderFactory::makeSocialPublisher();
        $caps      = $publisher->getCapabilities();
        $this->assertArrayHasKey('platforms', $caps);
        $this->assertContains('linkedin', $caps['platforms']);
    }

    public function testMockPublisherWithdrawReturnsTrue(): void
    {
        $publisher = ChannelProviderFactory::makeSocialPublisher();
        $this->assertTrue($publisher->withdraw('mock-post-id'));
    }
}
