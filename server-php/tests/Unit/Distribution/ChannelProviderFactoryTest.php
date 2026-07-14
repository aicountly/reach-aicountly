<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\MockEmailSender;
use App\Libraries\Distribution\Providers\MockSocialPublisher;
use App\Libraries\Distribution\Providers\MockSmsSender;
use App\Libraries\Distribution\Providers\MockWhatsAppSender;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ChannelProviderFactoryTest extends CIUnitTestCase
{
    public function testFactoryReturnsMockSocialPublisherByDefault(): void
    {
        $this->assertInstanceOf(MockSocialPublisher::class, ChannelProviderFactory::makeSocialPublisher());
    }

    public function testFactoryReturnsMockEmailSenderByDefault(): void
    {
        $this->assertInstanceOf(MockEmailSender::class, ChannelProviderFactory::makeEmailSender());
    }

    public function testFactoryReturnsMockWhatsAppSenderByDefault(): void
    {
        $this->assertInstanceOf(MockWhatsAppSender::class, ChannelProviderFactory::makeWhatsAppSender());
    }

    public function testFactoryReturnsMockSmsSenderByDefault(): void
    {
        $this->assertInstanceOf(MockSmsSender::class, ChannelProviderFactory::makeSmsSender());
    }

    public function testMockSocialPublisherReturnsAcceptedReceipt(): void
    {
        $publisher = ChannelProviderFactory::makeSocialPublisher();
        $message   = new \App\Libraries\Distribution\Providers\ChannelMessage(
            idempotencyKey:   'test-key-001',
            recipientAddress: 'linkedin-page-123',
            content:          'Test post content',
        );
        $receipt = $publisher->publish($message, 'linkedin');
        $this->assertSame('accepted', $receipt->status);
        $this->assertNotEmpty($receipt->providerMessageId);
    }

    public function testMockEmailSenderReturnsAcceptedReceipt(): void
    {
        $sender  = ChannelProviderFactory::makeEmailSender();
        $message = new \App\Libraries\Distribution\Providers\ChannelMessage(
            idempotencyKey:   'test-email-001',
            recipientAddress: 'user@example.com',
            content:          'Hello World',
        );
        $receipt = $sender->send($message);
        $this->assertSame('accepted', $receipt->status);
    }

    public function testMockWhatsAppSenderReturnsAcceptedReceipt(): void
    {
        $sender  = ChannelProviderFactory::makeWhatsAppSender();
        $message = new \App\Libraries\Distribution\Providers\ChannelMessage(
            idempotencyKey:   'test-wa-001',
            recipientAddress: '+919876543210',
            content:          '',
            templateId:       'hello_world',
        );
        $receipt = $sender->send($message);
        $this->assertSame('accepted', $receipt->status);
    }

    public function testMockSmsSenderReturnsAcceptedReceipt(): void
    {
        $sender  = ChannelProviderFactory::makeSmsSender();
        $message = new \App\Libraries\Distribution\Providers\ChannelMessage(
            idempotencyKey:   'test-sms-001',
            recipientAddress: '+919876543210',
            content:          'Your OTP is 1234',
        );
        $receipt = $sender->send($message);
        $this->assertSame('accepted', $receipt->status);
    }

    public function testMockSocialPublisherThrowsOnRateLimitKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $publisher = ChannelProviderFactory::makeSocialPublisher();
        $message   = new \App\Libraries\Distribution\Providers\ChannelMessage(
            idempotencyKey:   'rate_limit-key',
            recipientAddress: 'page-123',
            content:          'test',
        );
        $publisher->publish($message, 'linkedin');
    }

    public function testMockEmailSenderThrowsOnPermFail(): void
    {
        $this->expectException(\RuntimeException::class);
        $sender  = ChannelProviderFactory::makeEmailSender();
        $message = new \App\Libraries\Distribution\Providers\ChannelMessage(
            idempotencyKey:   'perm_fail-key',
            recipientAddress: 'user@example.com',
            content:          'test',
        );
        $sender->send($message);
    }

    public function testAllMockProvidersAreEnabled(): void
    {
        $this->assertTrue(ChannelProviderFactory::makeSocialPublisher()->isEnabled());
        $this->assertTrue(ChannelProviderFactory::makeEmailSender()->isEnabled());
        $this->assertTrue(ChannelProviderFactory::makeWhatsAppSender()->isEnabled());
        $this->assertTrue(ChannelProviderFactory::makeSmsSender()->isEnabled());
    }

    public function testAllMockProviderNamesAreNonEmpty(): void
    {
        $this->assertNotEmpty(ChannelProviderFactory::makeSocialPublisher()->providerName());
        $this->assertNotEmpty(ChannelProviderFactory::makeEmailSender()->providerName());
        $this->assertNotEmpty(ChannelProviderFactory::makeWhatsAppSender()->providerName());
        $this->assertNotEmpty(ChannelProviderFactory::makeSmsSender()->providerName());
    }
}
