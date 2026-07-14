<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

class ChannelProviderFactory
{
    public static function makeSocialPublisher(): SocialPublisherInterface
    {
        $provider = (string) (getenv('SOCIAL_PROVIDER') ?: $_ENV['SOCIAL_PROVIDER'] ?? '');
        if ($provider !== '' && $provider !== 'mock') {
            throw new \LogicException(
                "Social provider '{$provider}' is not configured. Set SOCIAL_PROVIDER=mock for testing."
            );
        }
        return new MockSocialPublisher();
    }

    public static function makeEmailSender(): EmailSenderInterface
    {
        $provider = (string) (getenv('EMAIL_PROVIDER') ?: $_ENV['EMAIL_PROVIDER'] ?? '');
        if ($provider !== '' && $provider !== 'mock') {
            throw new \LogicException(
                "Email provider '{$provider}' is not configured. Set EMAIL_PROVIDER=mock for testing."
            );
        }
        return new MockEmailSender();
    }

    public static function makeWhatsAppSender(): WhatsAppSenderInterface
    {
        $provider = (string) (getenv('WHATSAPP_PROVIDER') ?: $_ENV['WHATSAPP_PROVIDER'] ?? '');
        if ($provider !== '' && $provider !== 'mock') {
            throw new \LogicException(
                "WhatsApp provider '{$provider}' is not configured. Set WHATSAPP_PROVIDER=mock for testing."
            );
        }
        return new MockWhatsAppSender();
    }

    public static function makeSmsSender(): SmsSenderInterface
    {
        $provider = (string) (getenv('SMS_PROVIDER') ?: $_ENV['SMS_PROVIDER'] ?? '');
        if ($provider !== '' && $provider !== 'mock') {
            throw new \LogicException(
                "SMS provider '{$provider}' is not configured. Set SMS_PROVIDER=mock for testing."
            );
        }
        return new MockSmsSender();
    }
}
