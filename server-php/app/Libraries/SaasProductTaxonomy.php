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
            'smart_books'         => 'Aicountly Smart Books',
            'contacts'            => 'Aicountly Contacts',
            'calendar'            => 'Aicountly Calendar',
            'financial_reporting' => 'Aicountly Financial Reporting',
            'secretarial'         => 'Aicountly Secretarial',
            'auditor'             => 'Aicountly Auditor',
            'vault'               => 'Aicountly Vault',
            'hrms'                => 'Aicountly HRMS',
            'docs'                => 'Aicountly Docs',
            'chat'                => 'Aicountly Chat',
            'flow'                => 'Aicountly Flow',
            'my_account'          => 'My Account',
            'our_people'          => 'Aicountly Our People',
            'manage_account'      => 'Aicountly Manage',
            'buddy'               => 'Aicountly AI Pulse',
            'drive'               => 'Aicountly Drive',
            'connect'             => 'Aicountly Connect',
            'notes'               => 'Aicountly Notes',
            'pos'                 => 'Aicountly POS',
            'billing'             => 'Aicountly Billing',
            'inventory'           => 'Aicountly Inventory',
            'sales'               => 'Aicountly Sales',
            'purchase'            => 'Aicountly Purchase',
            'insights'            => 'Aicountly Insights',
            'remote'              => 'Aicountly Remote',
            'advisor'             => 'Aicountly Advisor',
            'appointments'        => 'Aicountly Appointments',
            'contracts'           => 'Aicountly Contracts',
            'pay'                 => 'Aicountly Pay',
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
        unset($catalog['flow'], $catalog['docs'], $catalog['chat']);
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
            'notes',
            'pos',
        ], true);
    }

    public static function productDedicatedSiteUrl(string $slug): ?string
    {
        $urls = [
            'auditor'     => 'https://auditor.aicountly.com',
            'smart_books' => 'https://books.aicountly.com',
            'hrms'        => 'https://hrms.aicountly.com',
            'notes'       => 'https://notes.aicountly.com',
            'pos'         => 'https://pos.aicountly.com',
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
            'our_people'          => ['/our-people'],
            'manage_account'      => ['/manage'],
            'buddy'               => ['/buddy'],
            'drive'               => ['/drive'],
            'connect'             => ['/connect'],
            'notes'               => ['/notes'],
            'pos'                 => ['/pos'],
            'billing'             => ['/billing'],
            'inventory'           => ['/inventory'],
            'sales'               => ['/sales'],
            'purchase'            => ['/purchase'],
            'insights'            => ['/insights'],
            'remote'              => ['/remote'],
            'advisor'             => ['/advisor'],
            'appointments'        => ['/appointments'],
            'contracts'           => ['/contracts'],
            'pay'                 => ['/pay'],
        ];

        return $paths[$slug] ?? ['/' . str_replace('_', '-', $slug)];
    }

    private static function humanizeProductSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        return 'Aicountly ' . ucwords(str_replace(['_', '-'], ' ', $slug));
    }
}
