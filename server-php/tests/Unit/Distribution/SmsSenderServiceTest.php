<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SmsSenderServiceTest extends CIUnitTestCase
{
    public function testMockSmsSenderAcceptsMessage(): void
    {
        $sender  = ChannelProviderFactory::makeSmsSender();
        $message = new ChannelMessage(
            idempotencyKey:   'sms-cp8-test-001',
            recipientAddress: '+14155552671',
            content:          'Your OTP is 123456',
            metadata:         ['dlt_entity_id' => 'ENT001', 'dlt_template_id' => 'TMPL001'],
        );
        $receipt = $sender->send($message);
        $this->assertSame('accepted', $receipt->status);
        $this->assertStringStartsWith('mock-sms-', $receipt->providerMessageId);
        $this->assertTrue($receipt->isAccepted());
    }

    public function testMockSmsCapabilities(): void
    {
        $sender = ChannelProviderFactory::makeSmsSender();
        $caps   = $sender->getCapabilities();
        $this->assertArrayHasKey('max_body_chars', $caps);
    }
}
