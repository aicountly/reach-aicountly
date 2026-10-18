<?php

declare(strict_types=1);

namespace App\Libraries\Ads;

/**
 * Reach's conventions for paid campaigns, and validation of a campaign record.
 *
 * UTM set (one per platform, so every sign-up and lead can be traced back):
 *   google_search → utm_source=google    utm_medium=cpc
 *   youtube       → utm_source=youtube   utm_medium=video
 *   meta          → utm_source=meta      utm_medium=paid_social   (Facebook + Instagram)
 *   linkedin      → utm_source=linkedin  utm_medium=paid_social
 *   utm_campaign  = soft-launch-2026-10 (launch default)
 *   utm_content   = the creative id (e.g. gs-rsa-01, yt-reel-01, fb-reel-01, li-single-01)
 * The platforms add their own click id on top (gclid, fbclid, li_fat_id);
 * aicountly.com carries those and the UTM set to my.aicountly.com sign-up.
 *
 * The web client mirrors this in web/src/pages/social/ads/adsUtm.js; tests on
 * both sides pin the same examples.
 *
 * Budgets: LAUNCH_PLAN is the owner's planned split for the soft launch
 * (≈ ₹2,000/day). It is only ever a suggestion for a planned campaign's daily
 * budget — never spend. Spend comes only from entered or imported results.
 */
final class AdsConventions
{
    public const DEFAULT_CAMPAIGN = 'soft-launch-2026-10';
    public const DEFAULT_LANDING  = 'https://aicountly.com/free-accounting-software';

    public const PLATFORMS = [
        'google_search' => ['label' => 'Google Ads — Search',          'utm_source' => 'google',   'utm_medium' => 'cpc',         'csv' => 'google_ads', 'manager' => 'Google Ads',             'creative_prefix' => 'gs'],
        'youtube'       => ['label' => 'Google Ads — YouTube',         'utm_source' => 'youtube',  'utm_medium' => 'video',       'csv' => 'google_ads', 'manager' => 'Google Ads',             'creative_prefix' => 'yt'],
        'meta'          => ['label' => 'Meta — Facebook & Instagram',  'utm_source' => 'meta',     'utm_medium' => 'paid_social', 'csv' => 'meta',       'manager' => 'Meta Ads Manager',       'creative_prefix' => 'fb'],
        'linkedin'      => ['label' => 'LinkedIn Ads',                 'utm_source' => 'linkedin', 'utm_medium' => 'paid_social', 'csv' => 'linkedin',   'manager' => 'LinkedIn Campaign Manager', 'creative_prefix' => 'li'],
    ];

    /** Owner's launch split, ₹ per day (an example for planning, not spend). */
    public const LAUNCH_PLAN = [
        'google_search' => 600,
        'youtube'       => 300,
        'meta'          => 300,
        'linkedin'      => 800,
    ];

    public const OBJECTIVES = ['signups', 'leads', 'traffic', 'video_views', 'awareness'];
    public const STATUSES   = ['planned', 'live', 'paused', 'ended'];

    /** Click ids and UTM keys a landing URL must not already carry. */
    private const TRACKING_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'li_fat_id'];

    public static function isPlatform(string $platform): bool
    {
        return isset(self::PLATFORMS[$platform]);
    }

    /** A UTM value under Reach's conventions: lower-case slug. */
    public static function isUtmValue(string $value, int $max = 120): bool
    {
        return strlen($value) <= $max && preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $value) === 1;
    }

    /** A creative id (becomes utm_content). */
    public static function isCreativeId(string $id): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', $id) === 1;
    }

    /**
     * Why a landing URL is refused, or null. Ads must land on aicountly.com over
     * HTTPS so that its sign-up links can carry the attribution.
     */
    public static function landingProblem(string $url): ?string
    {
        if ($url === '' || strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Landing URL must be a full https:// address.';
        }
        $parts = parse_url($url);
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return 'Landing URL must use https.';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'aicountly.com' && ! str_ends_with($host, '.aicountly.com')) {
            return 'Ads must land on aicountly.com (or a *.aicountly.com page) so sign-up keeps the campaign.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Landing URL must not carry credentials.';
        }

        return null;
    }

    /**
     * The tracking link for one platform/creative under the conventions.
     *
     * @param array{utm_campaign?:string, utm_content?:?string, utm_term?:?string, utm_source?:string, utm_medium?:string} $utm
     * @throws \InvalidArgumentException when an input breaks the conventions
     */
    public static function buildLink(string $landingUrl, string $platform, array $utm = []): string
    {
        if (! self::isPlatform($platform)) {
            throw new \InvalidArgumentException('Unknown platform.');
        }
        $problem = self::landingProblem($landingUrl);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        $set = [
            'utm_source'   => $utm['utm_source'] ?? self::PLATFORMS[$platform]['utm_source'],
            'utm_medium'   => $utm['utm_medium'] ?? self::PLATFORMS[$platform]['utm_medium'],
            'utm_campaign' => ($utm['utm_campaign'] ?? '') !== '' ? (string) $utm['utm_campaign'] : self::DEFAULT_CAMPAIGN,
        ];
        foreach (['utm_source', 'utm_medium'] as $k) {
            if (! self::isUtmValue((string) $set[$k])) {
                throw new \InvalidArgumentException("{$k} must be a lower-case slug.");
            }
        }
        if (! self::isUtmValue($set['utm_campaign'], 240)) {
            throw new \InvalidArgumentException('utm_campaign must be a lower-case slug (e.g. soft-launch-2026-10).');
        }
        $content = (string) ($utm['utm_content'] ?? '');
        if ($content !== '') {
            if (! self::isCreativeId($content)) {
                throw new \InvalidArgumentException('utm_content is the creative id: lower-case letters, digits and dashes.');
            }
            $set['utm_content'] = $content;
        }
        $term = (string) ($utm['utm_term'] ?? '');
        if ($term !== '') {
            if (! self::isUtmValue($term, 240)) {
                throw new \InvalidArgumentException('utm_term must be a lower-case slug.');
            }
            $set['utm_term'] = $term;
        }

        $parts = parse_url($landingUrl);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (self::TRACKING_KEYS as $k) {
            unset($query[$k]);
        }
        $query = $query + $set;

        return 'https://' . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . ($parts['path'] ?? '/')
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /**
     * Validate and normalise a campaign record from the API.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a message fit for the user
     */
    public static function normalizeCampaign(array $in, ?array $existing = null): array
    {
        $merged = array_merge($existing ?? [], array_intersect_key($in, array_flip([
            'platform', 'name', 'external_ref', 'objective', 'daily_budget_inr', 'start_date', 'end_date',
            'landing_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'creatives', 'status', 'notes',
        ])));

        $platform = (string) ($merged['platform'] ?? '');
        if (! self::isPlatform($platform)) {
            throw new \InvalidArgumentException('Choose a platform: ' . implode(', ', array_keys(self::PLATFORMS)) . '.');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($merged['name'] ?? '')) ?? '');
        if ($name === '' || mb_strlen($name) > 150) {
            throw new \InvalidArgumentException('Name is required (up to 150 characters).');
        }
        $externalRef = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($merged['external_ref'] ?? '')) ?? '');
        if (mb_strlen($externalRef) > 200) {
            throw new \InvalidArgumentException('Ads manager campaign name is up to 200 characters.');
        }
        $objective = (string) ($merged['objective'] ?? 'signups');
        if (! in_array($objective, self::OBJECTIVES, true)) {
            throw new \InvalidArgumentException('Objective must be one of: ' . implode(', ', self::OBJECTIVES) . '.');
        }
        $budget = $merged['daily_budget_inr'] ?? null;
        if ($budget === '' || $budget === null) {
            $budget = null;
        } elseif (! is_numeric($budget) || (float) $budget < 0 || (float) $budget > 10000000) {
            throw new \InvalidArgumentException('Daily budget must be a number of rupees (0 or more).');
        } else {
            $budget = number_format((float) $budget, 2, '.', '');
        }
        $start = self::date($merged['start_date'] ?? null);
        if ($start === null) {
            throw new \InvalidArgumentException('Start date is required (YYYY-MM-DD).');
        }
        $endRaw = $merged['end_date'] ?? null;
        $end = null;
        if ($endRaw !== null && $endRaw !== '') {
            $end = self::date($endRaw);
            if ($end === null) {
                throw new \InvalidArgumentException('End date must be YYYY-MM-DD.');
            }
        }
        if ($end !== null && $end < $start) {
            throw new \InvalidArgumentException('End date cannot be before the start date.');
        }
        $landing = trim((string) ($merged['landing_url'] ?? ''));
        $problem = self::landingProblem($landing);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        $utmSource = strtolower(trim((string) ($merged['utm_source'] ?? ''))) ?: self::PLATFORMS[$platform]['utm_source'];
        $utmMedium = strtolower(trim((string) ($merged['utm_medium'] ?? ''))) ?: self::PLATFORMS[$platform]['utm_medium'];
        $utmCampaign = strtolower(trim((string) ($merged['utm_campaign'] ?? ''))) ?: self::DEFAULT_CAMPAIGN;
        foreach (['utm_source' => [$utmSource, 120], 'utm_medium' => [$utmMedium, 120], 'utm_campaign' => [$utmCampaign, 240]] as $k => [$v, $max]) {
            if (! self::isUtmValue($v, $max)) {
                throw new \InvalidArgumentException("{$k} must be a lower-case slug.");
            }
        }
        $utmContent = strtolower(trim((string) ($merged['utm_content'] ?? '')));
        if ($utmContent !== '' && ! self::isCreativeId($utmContent)) {
            throw new \InvalidArgumentException('utm_content is a creative id: lower-case letters, digits and dashes.');
        }
        $utmTerm = strtolower(trim((string) ($merged['utm_term'] ?? '')));
        if ($utmTerm !== '' && ! self::isUtmValue($utmTerm, 240)) {
            throw new \InvalidArgumentException('utm_term must be a lower-case slug.');
        }

        $creatives = [];
        $raw = $merged['creatives'] ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?? [];
        }
        if (! is_array($raw) || count($raw) > 50) {
            throw new \InvalidArgumentException('Creatives must be a list (up to 50).');
        }
        $seen = [];
        foreach ($raw as $c) {
            $c = is_string($c) ? ['id' => $c] : (array) $c;
            $id = strtolower(trim((string) ($c['id'] ?? '')));
            if (! self::isCreativeId($id)) {
                throw new \InvalidArgumentException('Each creative needs an id of lower-case letters, digits and dashes (e.g. ' . self::PLATFORMS[$platform]['creative_prefix'] . '-reel-01).');
            }
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException("Creative id {$id} is listed twice.");
            }
            $seen[$id] = true;
            $label = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($c['label'] ?? '')) ?? ''), 0, 150);
            $format = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($c['format'] ?? '')) ?? ''), 0, 40);
            $assetUrl = trim((string) ($c['asset_url'] ?? ''));
            if ($assetUrl !== '' && (filter_var($assetUrl, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($assetUrl, PHP_URL_SCHEME)), ['https', 'http'], true))) {
                throw new \InvalidArgumentException("Creative {$id}: asset URL must be an http(s) address.");
            }
            $creatives[] = ['id' => $id, 'label' => $label, 'format' => $format, 'asset_url' => $assetUrl];
        }

        $status = (string) ($merged['status'] ?? 'planned');
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Status must be one of: ' . implode(', ', self::STATUSES) . '.');
        }
        $notes = trim((string) ($merged['notes'] ?? ''));
        if (mb_strlen($notes) > 2000) {
            throw new \InvalidArgumentException('Notes are up to 2,000 characters.');
        }

        return [
            'platform'         => $platform,
            'name'             => $name,
            'external_ref'     => $externalRef !== '' ? $externalRef : $name,
            'objective'        => $objective,
            'daily_budget_inr' => $budget,
            'start_date'       => $start,
            'end_date'         => $end,
            'landing_url'      => $landing,
            'utm_source'       => $utmSource,
            'utm_medium'       => $utmMedium,
            'utm_campaign'     => $utmCampaign,
            'utm_content'      => $utmContent !== '' ? $utmContent : null,
            'utm_term'         => $utmTerm !== '' ? $utmTerm : null,
            'creatives'        => $creatives,
            'status'           => $status,
            'notes'            => $notes !== '' ? $notes : null,
        ];
    }

    /**
     * The tracking links a campaign's ads should use: one per creative (or one
     * campaign-level link when no creative is listed).
     *
     * @return list<array{creative_id: ?string, url: string}>
     */
    public static function trackingLinks(array $campaign): array
    {
        $utm = [
            'utm_source'   => (string) $campaign['utm_source'],
            'utm_medium'   => (string) $campaign['utm_medium'],
            'utm_campaign' => (string) $campaign['utm_campaign'],
            'utm_term'     => (string) ($campaign['utm_term'] ?? ''),
        ];
        $creatives = is_array($campaign['creatives'] ?? null) ? $campaign['creatives'] : [];
        $links = [];
        try {
            if ($creatives === []) {
                $links[] = ['creative_id' => $campaign['utm_content'] ?? null, 'url' => self::buildLink((string) $campaign['landing_url'], (string) $campaign['platform'], $utm + ['utm_content' => (string) ($campaign['utm_content'] ?? '')])];
            }
            foreach ($creatives as $c) {
                $links[] = ['creative_id' => (string) $c['id'], 'url' => self::buildLink((string) $campaign['landing_url'], (string) $campaign['platform'], $utm + ['utm_content' => (string) $c['id']])];
            }
        } catch (\InvalidArgumentException) {
            return [];
        }

        return $links;
    }

    /** Y-m-d, or null when not a real date. */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /** The planning options the web client offers. */
    public static function meta(): array
    {
        $platforms = [];
        foreach (self::PLATFORMS as $key => $p) {
            $platforms[] = [
                'key'                        => $key,
                'label'                      => $p['label'],
                'utm_source'                 => $p['utm_source'],
                'utm_medium'                 => $p['utm_medium'],
                'csv_format'                 => $p['csv'],
                'ads_manager'                => $p['manager'],
                'creative_prefix'            => $p['creative_prefix'],
                'suggested_daily_budget_inr' => self::LAUNCH_PLAN[$key],
            ];
        }

        return [
            'platforms'        => $platforms,
            'objectives'       => self::OBJECTIVES,
            'statuses'         => self::STATUSES,
            'default_campaign' => self::DEFAULT_CAMPAIGN,
            'default_landing'  => self::DEFAULT_LANDING,
            'launch_plan'      => [
                'daily_total_inr' => array_sum(self::LAUNCH_PLAN),
                'split'           => self::LAUNCH_PLAN,
                'note'            => 'Planned daily budgets for the soft launch (owner, October 2026). A plan, not spend: spend comes only from entered or imported results.',
            ],
            'csv_formats'      => AdsCsvImporter::FORMATS,
            'connection'       => 'Reach is not connected to the Google Ads, Meta or LinkedIn ads APIs. Campaigns are placed by hand in each ads manager; results are typed in or imported from the ads manager\'s CSV export.',
        ];
    }
}
