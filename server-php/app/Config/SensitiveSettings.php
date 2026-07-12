<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Settings keys whose values must never appear in audit logs, API responses,
 * or job payloads. SettingsController masks the value; AuditLogger/JobService
 * redact matching values via SecretRedactor::redact().
 *
 * When adding a new integration credential, register its settings key here
 * BEFORE wiring it into any controller/audit path.
 */
class SensitiveSettings extends BaseConfig
{
    /**
     * Exact-match sensitive keys (case-insensitive).
     */
    public array $keys = [
        'openai_api_key',
        'anthropic_api_key',
        'gemini_api_key',
        'grok_api_key',
        'engage_inbound_token',
        'console_inbound_token',
        'console_api_token',
        'worker_api_token',
        'aicountly_site_api_token',
        'linkedin_api_token',
        'twitter_api_token',
        'facebook_api_token',
        'instagram_api_token',
        'youtube_api_token',
        'whatsapp_channel_api_token',
        'gsc_service_account_json',
        'meta_access_token',
        'linkedin_analytics_token',
        'twitter_analytics_token',
        'youtube_analytics_token',
        'email_provider_api_key',
        'whatsapp_provider_api_key',
        'public_lead_capture_token',
    ];

    /**
     * Substring patterns — any settings key whose lowercase name contains
     * one of these tokens is treated as sensitive.
     */
    public array $substrings = [
        'token', 'secret', 'password', 'api_key', 'apikey',
        'authorization', 'private_key', 'service_account',
    ];

    public function isSensitive(string $key): bool
    {
        $normalised = strtolower(trim($key));
        if ($normalised === '') {
            return false;
        }
        if (in_array($normalised, array_map('strtolower', $this->keys), true)) {
            return true;
        }
        foreach ($this->substrings as $needle) {
            if (str_contains($normalised, $needle)) {
                return true;
            }
        }
        return false;
    }
}
