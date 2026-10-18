<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\MockSocialPublisher;
use App\Libraries\Distribution\Providers\ProviderGuard;
use App\Libraries\Distribution\Providers\SocialApiAiPublisher;
use App\Libraries\Gateways\Mailer;
use App\Libraries\Gateways\SmsSender;
use App\Libraries\Gateways\WhatsappSender;
use App\Libraries\Settings\ReachConfig;

/**
 * Live, honest readiness of every campaign channel (GET v1/distribution/readiness).
 *
 * States:
 *   ready           a real provider is configured and sends for real
 *   test_mode       provider configured but *_SKIP_SEND is on — nothing leaves Reach
 *   not_configured  credentials/settings missing; sends fail closed
 *   blocked         waiting on something outside this codebase (e.g. socialapi.ai docs)
 *   not_supported   no integration exists in Reach
 *
 * Never returns a secret value — only whether it is set.
 */
class CampaignReadinessService
{
    /** @return array<string,mixed> */
    public function report(): array
    {
        $channels = [
            $this->email(),
            $this->sms(),
            $this->whatsapp(),
        ];
        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'twitter' => 'X / Twitter', 'youtube' => 'YouTube'] as $key => $label) {
            $channels[] = $this->social($key, $label);
        }
        foreach (['google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads', 'linkedin_ads' => 'LinkedIn Ads'] as $key => $label) {
            $channels[] = $this->ads($key, $label);
        }

        return [
            'generated_at' => gmdate('c'),
            'environment'  => defined('ENVIRONMENT') ? ENVIRONMENT : 'production',
            'production'   => ProviderGuard::isProduction(),
            'queue'        => $this->queue(),
            'summary'      => array_count_values(array_column($channels, 'state')),
            'channels'     => $channels,
        ];
    }

    /**
     * Email goes through Console's email providers for reach (EmailDispatcher: primary, then
     * fallback; .env EMAIL_API_KEY as the last resort). Provider codes only — never a secret.
     *
     * @return array<string,mixed>
     */
    private function email(): array
    {
        $sender    = self::env('SENDER_EMAIL');
        $providers = Mailer::providerSummary();
        $codes     = array_column($providers['providers'], 'code');
        $checks    = [
            self::check('email providers', 'console', $codes !== [], 'Console → Email providers bound to reach (EMAIL_API_KEY in .env is only the last resort).'),
            self::check('SENDER_EMAIL', 'env', $sender !== '' && (bool) filter_var($sender, FILTER_VALIDATE_EMAIL), 'From address on an SPF/DKIM-authenticated domain (falls back to support@aicountly.com if unset).'),
            self::check('distribution.email.postal_address', 'setting', trim((string) self::setting('distribution.email.postal_address')) !== '', 'Postal address printed in every campaign email footer.'),
            self::check('unsubscribe links', 'setting', UnsubscribeLinks::isAvailable(), 'Needs REACH_APP_URL (or distribution.email.unsubscribe_base_url) and REACH_SETTINGS_KEY/JWT_SECRET.'),
        ];
        $manual = [
            'SPF, DKIM and DMARC records published for each provider\'s from address / SENDER_EMAIL domain (check with the provider console / a DNS lookup).',
            'Each email provider (e.g. Itwalk) out of sandbox with enough daily volume for distribution.send_cap.email.',
        ];

        $channel = $this->channel('email', 'Email', $codes === [] ? 'none' : implode(' → ', $codes), $checks, $manual, null);
        $channel['providers']       = $providers['providers'];
        $channel['provider_source'] = $providers['source'];
        $channel['provider_line']   = $providers['line'];
        if ($providers['source'] !== 'console' && $providers['note'] !== null) {
            $channel['reasons'][] = match ($providers['source']) {
                'console_stale' => 'Console could not be reached — using its cached provider list (' . $providers['note'] . ').',
                'env'           => 'Using the .env EMAIL_API_KEY last-resort provider: ' . $providers['note'] . '.',
                default         => $providers['note'] . '.',
            };
        }

        return $channel;
    }

    /** @return array<string,mixed> */
    private function sms(): array
    {
        $checks = [
            self::check('SMS_API_KEY', 'env', self::env('SMS_API_KEY') !== '', 'Digimiles / AOC Portal API key.'),
            self::check('SMS_SENDER_ID', 'env', self::env('SMS_SENDER_ID') !== '', 'DLT-registered 6-character header.'),
        ];
        $testMode = SmsSender::isConfigured() && SmsSender::isSendDisabled()
            ? 'SMS_SKIP_SEND is on (it defaults to true): sends are recorded as skipped, nothing is delivered. Set SMS_SKIP_SEND=false.'
            : null;
        $manual = [
            'TRAI DLT: principal entity registered (entity id), header registered, every campaign text registered as a content template (template id) — set on each SMS campaign.',
            'Only promotional/marketing SMS to numbers with a recorded opt-in (Reach requires granted SMS consent per lead).',
        ];

        return $this->channel('sms', 'SMS', 'aoc_portal_sms (' . SmsSender::provider() . ')', $checks, $manual, $testMode)
            + ['opted_in_leads' => $this->grantedConsents('sms')];
    }

    /** @return array<string,mixed> */
    private function whatsapp(): array
    {
        $checks = [
            self::check('WHATSAPP_API_KEY', 'env', self::env('WHATSAPP_API_KEY') !== '', 'Infobip API key.'),
            self::check('WHATSAPP_WABA_ID', 'env', self::env('WHATSAPP_WABA_ID') !== '', 'WhatsApp Business Account / sender number registered with Infobip.'),
        ];
        $testMode = WhatsappSender::isConfigured() && WhatsappSender::isSendDisabled()
            ? 'WHATSAPP_SKIP_SEND is on (it defaults to true): sends are recorded as skipped, nothing is delivered. Set WHATSAPP_SKIP_SEND=false.'
            : null;
        $manual = [
            'Meta Business verification done and the WhatsApp sender approved in Infobip.',
            'Each campaign template approved by Meta (category MARKETING) — the campaign names it in template_name.',
            'Template sending is built from Infobip\'s public API reference and has not yet been exercised against the live account — do one test send first.',
        ];

        return $this->channel('whatsapp', 'WhatsApp', 'infobip_whatsapp', $checks, $manual, $testMode)
            + ['opted_in_leads' => $this->grantedConsents('whatsapp')];
    }

    /** @return array<string,mixed> */
    private function social(string $key, string $label): array
    {
        $reasons = [];
        try {
            $publisher = ChannelProviderFactory::makeSocialPublisher();
            $provider  = $publisher->providerName();
        } catch (\Throwable $e) {
            $publisher = null;
            $provider  = 'invalid';
            $reasons[] = $e->getMessage();
        }
        $hasKey = false;
        try {
            $hasKey = ReachConfig::secret('social.socialapi.api_key') !== null;
        } catch (\Throwable) {
        }
        $checks = [self::check('social.socialapi.api_key', 'secret', $hasKey, 'socialapi.ai API key (Settings → AI & Automation → Social).')];

        if ($publisher instanceof SocialApiAiPublisher) {
            $state     = 'blocked';
            $reasons[] = SocialApiAiPublisher::PENDING_MESSAGE;
        } elseif ($publisher instanceof MockSocialPublisher) {
            $state     = 'not_configured';
            $reasons[] = ProviderGuard::isProduction()
                ? 'No real social publisher: posts fail closed (approved posts go to the manual queue).'
                : 'Development mock publisher in use — nothing is posted anywhere.';
        } else {
            $state = $publisher !== null && $publisher->isEnabled() ? 'ready' : 'not_configured';
        }
        if ($key === 'youtube') {
            $reasons[] = 'Video uploads use the separate video pipeline (VIDEO_* settings), not campaign social posts.';
        }

        return [
            'channel'  => $key,
            'label'    => $label,
            'group'    => 'social',
            'state'    => $state,
            'provider' => $provider,
            'reasons'  => $reasons,
            'checks'   => $checks,
            'manual'   => [
                "{$label} account/page connected inside socialapi.ai (once the adapter exists).",
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function ads(string $key, string $label): array
    {
        return [
            'channel'  => $key,
            'label'    => $label,
            'group'    => 'ads',
            'state'    => 'not_supported',
            'provider' => null,
            'reasons'  => [
                "No {$label} integration exists in Reach (no campaign, audience or spend API code).",
                'Unknown whether socialapi.ai covers paid ads — its docs were not available; confirm before planning paid launches through Reach.',
            ],
            'checks'   => [],
            'manual'   => ["Run {$label} campaigns directly in the {$label} manager for launch."],
        ];
    }

    /**
     * @param list<array<string,mixed>> $checks
     * @param list<string> $manual
     * @return array<string,mixed>
     */
    private function channel(string $key, string $label, string $provider, array $checks, array $manual, ?string $testMode): array
    {
        $reasons = [];
        foreach ($checks as $check) {
            if (! $check['ok']) {
                $reasons[] = "{$check['key']} is not set. {$check['note']}";
            }
        }
        if ($reasons !== []) {
            $state = 'not_configured';
            array_unshift($reasons, ProviderGuard::isProduction()
                ? 'Sends fail closed: nothing is marked sent.'
                : 'Development mock sender in use — nothing is delivered.');
        } elseif ($testMode !== null) {
            $state     = 'test_mode';
            $reasons[] = $testMode;
        } else {
            $state = 'ready';
        }

        return [
            'channel'  => $key,
            'label'    => $label,
            'group'    => 'messaging',
            'state'    => $state,
            'provider' => $provider,
            'reasons'  => $reasons,
            'checks'   => $checks,
            'manual'   => $manual,
        ];
    }

    /** @return array<string,mixed> */
    private function queue(): array
    {
        try {
            $db  = \Config\Database::connect();
            $row = $db->query(
                "SELECT COUNT(*) AS pending, MIN(available_at) AS oldest FROM reach_jobs
                  WHERE queue = ? AND status = 'pending' AND available_at <= NOW()",
                [ChannelDispatchService::QUEUE]
            )->getRowArray();
            $pending = (int) ($row['pending'] ?? 0);
            $age     = ! empty($row['oldest']) ? max(0, time() - (int) strtotime((string) $row['oldest'])) : 0;
            $stalled = $pending > 0 && $age > 900;

            return [
                'name'            => ChannelDispatchService::QUEUE,
                'pending'         => $pending,
                'oldest_age_secs' => $age,
                'stalled'         => $stalled,
                'note'            => $stalled
                    ? 'Distribution jobs are waiting >15 min: the worker cron must drain the `distribution` queue (reach:work --queue=…,distribution).'
                    : 'Campaign sends run on the `distribution` queue.',
            ];
        } catch (\Throwable) {
            return ['name' => ChannelDispatchService::QUEUE, 'pending' => null, 'stalled' => null, 'note' => 'Queue state unavailable.'];
        }
    }

    private function grantedConsents(string $channel): ?int
    {
        try {
            return (int) (\Config\Database::connect()->query(
                "SELECT COUNT(DISTINCT subject_id) AS n FROM reach_channel_consents
                  WHERE subject_type = 'lead' AND channel = ? AND purpose = 'marketing' AND status = 'granted'
                    AND (expires_at IS NULL OR expires_at > NOW())",
                [$channel]
            )->getRowArray()['n'] ?? 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{key:string, kind:string, ok:bool, note:string} */
    private static function check(string $key, string $kind, bool $ok, string $note): array
    {
        return ['key' => $key, 'kind' => $kind, 'ok' => $ok, 'note' => $note];
    }

    private static function setting(string $key): mixed
    {
        try {
            return ReachConfig::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function env(string $key): string
    {
        return trim((string) (getenv($key) ?: $_ENV[$key] ?? ''));
    }
}
