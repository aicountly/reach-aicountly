<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class EmailSenderServiceTest extends CIUnitTestCase
{
    public function testMockEmailSenderAcceptsMessage(): void
    {
        $sender  = ChannelProviderFactory::makeEmailSender();
        $message = new ChannelMessage(
            idempotencyKey:   'email-cp6-test-001',
            recipientAddress: 'subscriber@example.com',
            content:          '<p>Phase 7 email dispatch</p>',
            metadata:         ['subject' => 'Phase 7 is live', 'from_email' => 'noreply@aicountly.com'],
        );
        $receipt = $sender->send($message);
        $this->assertSame('accepted', $receipt->status);
        $this->assertNotEmpty($receipt->providerMessageId);
        $this->assertTrue($receipt->isAccepted());
    }

    public function testMockEmailSenderGetCapabilitiesReturnsSupportedFeatures(): void
    {
        $sender = ChannelProviderFactory::makeEmailSender();
        $caps   = $sender->getCapabilities();
        $this->assertArrayHasKey('batch_size', $caps);
    }
}
