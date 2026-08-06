<?php

namespace App\Libraries\Blog;

/**
 * Environment-backed feature flags for blog automation.
 */
class BlogFeatureFlags
{
    /** @var array<string, string> */
    private const FLAG_ENV_MAP = [
        'command_centre'         => 'BLOG_COMMAND_CENTRE_ENABLED',
        'legacy_create_disabled' => 'BLOG_LEGACY_CREATE_DISABLED',
        'legacy_redirect'        => 'BLOG_LEGACY_REDIRECT_ENABLED',
        'automation'             => 'BLOG_AUTOMATION_ENABLED',
        'roadmap_optimizer'      => 'BLOG_ROADMAP_OPTIMIZER_ENABLED',
        'ai_generation'          => 'BLOG_AI_GENERATION_ENABLED',
        'fact_verification'      => 'BLOG_FACT_VERIFICATION_ENABLED',
        'image_generation'       => 'BLOG_IMAGE_GENERATION_ENABLED',
        'require_cover_image'    => 'BLOG_REQUIRE_COVER_IMAGE',
        'auto_publish'           => 'BLOG_AUTO_PUBLISH_ENABLED',
        'file_rendering'         => 'BLOG_FILE_RENDERING_ENABLED',
        'db_body_fallback'       => 'BLOG_DB_BODY_FALLBACK_ENABLED',
        'public_publisher'       => 'BLOG_PUBLIC_PUBLISHER_ENABLED',
        'search_console'         => 'BLOG_SEARCH_CONSOLE_ENABLED',
        'ga4'                    => 'BLOG_GA4_ENABLED',
    ];

    /** @var array<string, bool> */
    private const DEFAULTS = [
        'BLOG_COMMAND_CENTRE_ENABLED'     => true,
        'BLOG_LEGACY_CREATE_DISABLED'     => true,
        'BLOG_LEGACY_REDIRECT_ENABLED'    => true,
        'BLOG_AUTOMATION_ENABLED'         => false,
        'BLOG_ROADMAP_OPTIMIZER_ENABLED'  => false,
        'BLOG_AI_GENERATION_ENABLED'      => false,
        'BLOG_FACT_VERIFICATION_ENABLED'  => false,
        // Gates the PAID AI image leg only. The curated gallery is assigned
        // regardless — see WorkBlockService::executeGenerateImage().
        'BLOG_IMAGE_GENERATION_ENABLED'   => false,
        // No blog publishes without a topically suitable cover. Off = the old
        // behaviour (missing cover is a warning on the listing card).
        'BLOG_REQUIRE_COVER_IMAGE'        => true,
        'BLOG_AUTO_PUBLISH_ENABLED'       => false,
        'BLOG_FILE_RENDERING_ENABLED'     => false,
        'BLOG_DB_BODY_FALLBACK_ENABLED'   => true,
        'BLOG_PUBLIC_PUBLISHER_ENABLED'   => false,
        'BLOG_SEARCH_CONSOLE_ENABLED'     => false,
        'BLOG_GA4_ENABLED'                => false,
    ];

    public function isEnabled(string $flag): bool
    {
        $envKey = self::FLAG_ENV_MAP[$flag] ?? strtoupper($flag);
        if (! str_starts_with($envKey, 'BLOG_')) {
            $envKey = 'BLOG_' . $envKey . '_ENABLED';
        }

        $default = self::DEFAULTS[$envKey] ?? false;
        return filter_var(env($envKey, $default), FILTER_VALIDATE_BOOL);
    }

    /**
     * High-risk auto-publish is permanently forbidden regardless of flags.
     *
     * @throws \RuntimeException when risk is HIGH or CRITICAL
     */
    public function assertHighRiskAutoPublishForbidden(string $riskClass): bool
    {
        $normalized = strtoupper(trim($riskClass));
        if (in_array($normalized, ['HIGH', 'CRITICAL'], true)) {
            throw new \RuntimeException('High-risk content cannot be auto-published.');
        }

        return false;
    }
}
