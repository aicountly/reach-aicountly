<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

use App\Libraries\Gateways\Mailer;
use App\Libraries\Gateways\SmsSender;
use App\Libraries\Gateways\WhatsappSender;
use App\Libraries\Settings\ReachConfig;

class ChannelProviderFactory
{
    /**
     * Social publisher selection:
     *   - SOCIAL_PROVIDER=socialapi, or the `social.socialapi.api_key` secret is
     *     set (and SOCIAL_PROVIDER is unset) → SocialApiAiPublisher, which is a
     *     fail-closed placeholder until the socialapi.ai API is implemented;
     *   - unset / mock → MockSocialPublisher (refuses in production);
     *   - anything else → LogicException.
     */
    public static function makeSocialPublisher(): SocialPublisherInterface
    {
        $provider = self::env('SOCIAL_PROVIDER');
        $apiKey   = self::socialApiKey();

        if ($provider === 'socialapi' || ($provider === '' && $apiKey !== null)) {
            return new SocialApiAiPublisher((string) $apiKey, self::socialApiBaseUrl());
        }

        if ($provider !== '' && $provider !== 'mock') {
            throw new \LogicException(
                "Social provider '{$provider}' is not supported. Use SOCIAL_PROVIDER=socialapi (with social.socialapi.api_key) or mock for development."
            );
        }

        return new MockSocialPublisher();
    }

    /**
     * Whether a post on $platform would really be published right now. False in
     * production until a real social adapter exists — callers route such posts
     * to the manual queue instead of pretending they will go out.
     */
    public static function socialPublisherReadyFor(string $platform): bool
    {
        try {
            $publisher = self::makeSocialPublisher();
        } catch (\Throwable) {
            return false;
        }
        if (!$publisher->isEnabled()) {
            return false;
        }
        $platforms = (array) ($publisher->getCapabilities()['platforms'] ?? []);

        return in_array($platform, $platforms, true);
    }

    private static function socialApiKey(): ?string
    {
        try {
            $key = ReachConfig::secret('social.socialapi.api_key');
        } catch (\Throwable) {
            $key = null;
        }
        $key = $key !== null ? trim($key) : '';

        return $key !== '' ? $key : null;
    }

    private static function socialApiBaseUrl(): ?string
    {
        try {
            $url = trim((string) ReachConfig::get('social.socialapi.base_url', ''));
        } catch (\Throwable) {
            $url = '';
        }

        return $url !== '' ? $url : null;
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
