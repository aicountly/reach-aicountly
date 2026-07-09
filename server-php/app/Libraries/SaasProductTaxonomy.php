<?php

namespace App\Libraries;

/**
 * SaaS product catalog + GA4 env helpers (ported from Flow DeskTaxonomy for Reach Traffic Analytics).
 */
final class SaasProductTaxonomy
{
    /** @return array<string, string> slug => label */
    public static function products(): array
    {
        return [
            'smart_books'         => 'AICountly Smart Books',
            'contacts'            => 'AICountly Contacts',
            'calendar'            => 'AICountly Calendar',
            'financial_reporting' => 'AICountly Financial Reporting',
            'secretarial'         => 'AICountly Secretarial',
            'auditor'             => 'AICountly Auditor',
            'vault'               => 'AICountly Vault',
            'hrms'                => 'AICountly HRMS',
            'docs'                => 'AICountly Docs',
            'chat'                => 'AICountly Chat',
            'flow'                => 'AICountly Flow',
            'my_account'          => 'My Account',
        ];
    }

    /** @return array<string, string> */
    public static function productAliases(): array
    {
        return [
            'books'   => 'smart_books',
            'account' => 'my_account',
            'manage'  => 'manage_account',
        ];
    }

    public static function normalizeProductSlug(string $slug): string
    {
        $slug = trim($slug);

        return $slug === '' ? '' : (self::productAliases()[$slug] ?? $slug);
    }

    public static function validateProduct(string $slug): bool
    {
        $canonical = self::normalizeProductSlug($slug);

        return $canonical !== '' && array_key_exists($canonical, self::products());
    }

    public static function labelProduct(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }
        $canonical = self::normalizeProductSlug($slug);

        return self::products()[$canonical] ?? self::humanizeProductSlug($slug);
    }

    /** @return array<string, string> */
    public static function trafficAnalyticsSaasProducts(): array
    {
        $catalog = self::products();
        unset($catalog['flow']);
        uasort($catalog, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return $catalog;
    }

    public static function saasGa4EnvToken(string $slug): string
    {
        return strtoupper(self::normalizeProductSlug($slug));
    }

    public static function ga4PropertyEnvKeyForSaasProduct(string $slug): string
    {
        return 'GA4_PROPERTY_ID_SAAS_' . self::saasGa4EnvToken($slug);
    }

    public static function ga4ServiceAccountEnvKeyForSaasProduct(string $slug): string
    {
        return 'GOOGLE_SERVICE_ACCOUNT_JSON_SAAS_' . self::saasGa4EnvToken($slug);
    }

    public static function requiresDedicatedGa4Property(string $slug): bool
    {
        return in_array(self::normalizeProductSlug($slug), [
            'auditor',
            'smart_books',
            'hrms',
        ], true);
    }

    public static function productDedicatedSiteUrl(string $slug): ?string
    {
        $urls = [
            'auditor'     => 'https://auditor.aicountly.com',
            'smart_books' => 'https://books.aicountly.com',
            'hrms'        => 'https://hrms.aicountly.com',
        ];

        return $urls[self::normalizeProductSlug($slug)] ?? null;
    }

    /** @return list<string> */
    public static function productPortalPathPrefixes(string $slug): array
    {
        $slug = self::normalizeProductSlug($slug);
        if ($slug === '') {
            return [];
        }

        $paths = [
            'smart_books'         => ['/smart-books', '/smart_books', '/books'],
            'contacts'            => ['/contacts'],
            'calendar'            => ['/calendar'],
            'financial_reporting' => ['/financial-reporting', '/financial_reporting'],
            'secretarial'         => ['/secretarial'],
            'auditor'             => ['/auditor'],
            'vault'               => ['/vault'],
            'hrms'                => ['/hrms'],
            'docs'                => ['/docs'],
            'chat'                => ['/chat'],
            'flow'                => ['/flow'],
            'my_account'          => ['/dashboard', '/my-account', '/account'],
        ];

        return $paths[$slug] ?? ['/' . str_replace('_', '-', $slug)];
    }

    private static function humanizeProductSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        return 'AICountly ' . ucwords(str_replace(['_', '-'], ' ', $slug));
    }
}
