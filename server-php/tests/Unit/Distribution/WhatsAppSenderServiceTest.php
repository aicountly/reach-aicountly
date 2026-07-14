<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class WhatsAppSenderServiceTest extends CIUnitTestCase
{
    public function testMockWhatsAppSenderAcceptsTemplatedMessage(): void
    {
        $sender  = ChannelProviderFactory::makeWhatsAppSender();
        $message = new ChannelMessage(
            idempotencyKey:   'wa-cp7-test-001',
            recipientAddress: '+14155552671',
            content:          '',
            metadata:         ['template_id' => 'hello_world'],
        );
        $receipt = $sender->send($message);
        $this->assertSame('accepted', $receipt->status);
        $this->assertStringStartsWith('mock-wa-', $receipt->providerMessageId);
        $this->assertTrue($receipt->isAccepted());
    }

    public function testMockWhatsAppSenderReturnsTemplates(): void
    {
        $sender    = ChannelProviderFactory::makeWhatsAppSender();
        $templates = $sender->getTemplates();
        $this->assertNotEmpty($templates);
        $this->assertArrayHasKey('id', $templates[0]);
        $this->assertArrayHasKey('status', $templates[0]);
    }

    public function testMockWhatsAppRequiresTemplate(): void
    {
        $caps = ChannelProviderFactory::makeWhatsAppSender()->getCapabilities();
        $this->assertTrue($caps['template_required']);
    }
}
