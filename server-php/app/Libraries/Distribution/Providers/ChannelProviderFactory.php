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
     *   - SOCIAL_PROVIDER=socialapi (or social_api), or the `social.socialapi.api_key`
     *     secret is set (and SOCIAL_PROVIDER is unset) → SocialApiAiPublisher
     *     (social-api.ai: posts and YouTube videos);
     *   - unset / mock → MockSocialPublisher (refuses in production);
     *   - anything else → LogicException.
     */
    public static function makeSocialPublisher(): SocialPublisherInterface
    {
        $provider = self::env('SOCIAL_PROVIDER');
        $apiKey   = self::socialApiKey();

        if (in_array($provider, ['socialapi', 'social_api', 'social-api'], true) || ($provider === '' && $apiKey !== null)) {
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
     * Whether a post on $platform would really be published right now. For
     * social-api.ai: the key is set and an account for that platform resolves
     * (setting override, or the single active account). Callers route other
     * posts to the manual queue instead of pretending they will go out.
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
        if ($publisher instanceof SocialApiAiPublisher) {
            return $publisher->readiness($platform)['ok'];
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

        // itwalk / gateway / infobip are historical names: every real send now goes through
        // Console's email providers (EmailDispatcher), whichever transports they use.
        if (in_array($provider, ['itwalk', 'gateway', 'infobip', 'console', 'smtp', ''], true)) {
            return new GatewayEmailSender();
        }

        throw new \LogicException(
            "Email provider '{$provider}' is not configured. Leave EMAIL_PROVIDER empty (or itwalk/gateway) to use Console's email providers for reach."
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
