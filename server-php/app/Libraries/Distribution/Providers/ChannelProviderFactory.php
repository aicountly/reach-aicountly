<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

use App\Libraries\Gateways\Mailer;
use App\Libraries\Gateways\SmsSender;
use App\Libraries\Gateways\WhatsappSender;

class ChannelProviderFactory
{
    public static function makeSocialPublisher(): SocialPublisherInterface
    {
        $provider = self::env('SOCIAL_PROVIDER');
        if ($provider !== '' && $provider !== 'mock') {
            throw new \LogicException(
                "Social provider '{$provider}' is not configured. Set SOCIAL_PROVIDER=mock for testing."
            );
        }

        return new MockSocialPublisher();
    }

    public static function makeEmailSender(): EmailSenderInterface
    {
        $provider = self::env('EMAIL_PROVIDER');

        if ($provider === 'mock' || !Mailer::isConfigured()) {
            return new MockEmailSender();
        }

        if (in_array($provider, ['itwalk', 'gateway', 'infobip', ''], true)) {
            return new GatewayEmailSender();
        }

        throw new \LogicException(
            "Email provider '{$provider}' is not configured. Set EMAIL_PROVIDER=itwalk and EMAIL_API_KEY."
        );
    }

    public static function makeWhatsAppSender(): WhatsAppSenderInterface
    {
        $provider = self::env('WHATSAPP_PROVIDER');

        if ($provider === 'mock' || !WhatsappSender::isConfigured()) {
            return new MockWhatsAppSender();
        }

        if (in_array($provider, ['infobip', 'gateway', 'cloudapi', ''], true)) {
            return new GatewayWhatsAppSender();
        }

        throw new \LogicException(
            "WhatsApp provider '{$provider}' is not configured. Set WHATSAPP_PROVIDER=infobip and WHATSAPP_API_KEY."
        );
    }

    public static function makeSmsSender(): SmsSenderInterface
    {
        $provider = self::env('SMS_PROVIDER');

        if ($provider === 'mock' || !SmsSender::isConfigured()) {
            return new MockSmsSender();
        }

        if (in_array($provider, ['aoc-portal', 'digimiles', 'aoc', 'smsgatewayhub', ''], true)) {
            return new AocPortalSmsSender();
        }

        throw new \LogicException(
            "SMS provider '{$provider}' is not configured. Set SMS_PROVIDER=aoc-portal and SMS_API_KEY."
        );
    }

    private static function env(string $key): string
    {
        return strtolower(trim((string) (getenv($key) ?: $_ENV[$key] ?? '')));
    }
}
