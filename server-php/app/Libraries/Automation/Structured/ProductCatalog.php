<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Structured;

/**
 * The aicountly.com product catalogue as the release-note and workflow-guide
 * routines need it: the catalogue slug the public site accepts
 * (aicountly-com includes/saas-products.php), the product's GitHub repository
 * under the aicountly organisation, its guide hub on /guides, and the latest
 * hand-written release on the site.
 *
 * The hand-written releases (aicountly-com includes/releases.php, snapshot
 * 2026-10-17) are the floor for the release-note version rule: a Reach note
 * whose version is not above them would take over a hand-written release's
 * slug and replace that page. Reach's own published notes raise the floor
 * from there.
 *
 * Launch apps come first, in launcher order (saas_launch_product_slugs());
 * the rest of the software is Beta, sorted by name as the site sorts them.
 *
 * Aicountly Academy and its two courses (ACADEMY) are a separate kind,
 * "course": Aicountly-run training for college students, not software. They
 * are valid product_slugs (a blog can be about them) but are never Beta,
 * never a launch app, and never get release notes or workflow guides —
 * isSoftware() is false for them. They come last in all().
 */
final class ProductCatalog
{
    public const LAUNCH = ['books', 'notes', 'billing', 'inventory', 'docs', 'manage'];

    /** Aicountly Academy first, then its courses (the order aicountly.com/academy shows them). */
    public const ACADEMY = ['academy', 'edge', 'aspire'];

    public const KIND_APP    = 'app';
    public const KIND_COURSE = 'course';

    /**
     * slug => [name, repo, guide hub, hand-written release floor [version, date, slug] | null]
     *
     * @return array<string, array{name:string, repo:string, hub:string, hand:?array{0:string,1:string,2:string}}>
     */
    private static function products(): array
    {
        return [
            'books'               => ['name' => 'Smart Books',          'repo' => 'books-react-app',        'hub' => 'smart-books',         'hand' => ['2.0', '2026-09-14', 'smart-books-v2-0']],
            'notes'               => ['name' => 'Notes',                'repo' => 'notes-aicountly',        'hub' => 'notes',               'hand' => ['1.0', '2026-09-12', 'notes-v1-0']],
            'billing'             => ['name' => 'Billing',              'repo' => 'billing-aicountly',      'hub' => 'billing',             'hand' => null],
            'inventory'           => ['name' => 'Inventory',            'repo' => 'Inventory-aicountly',    'hub' => 'inventory',           'hand' => ['1.0', '2026-09-14', 'inventory-v1-0']],
            'docs'                => ['name' => 'Drive',                'repo' => 'drive-react-app',        'hub' => 'docs',                'hand' => ['1.2', '2026-09-12', 'docs-v1-2']],
            'manage'              => ['name' => 'Manage',               'repo' => 'manage-aicountly',       'hub' => 'manage',              'hand' => ['1.2', '2026-09-13', 'manage-v1-2']],
            'financial-reporting' => ['name' => 'Financial Reporting',  'repo' => 'fr-react-app',           'hub' => 'financial-reporting', 'hand' => ['1.1', '2026-09-08', 'financial-reporting-v1-1']],
            'auditor'             => ['name' => 'Aicountly Auditor',    'repo' => 'auditor-react-app',      'hub' => 'auditor',             'hand' => ['1.1', '2026-09-08', 'auditor-v1-1']],
            'secretarial'         => ['name' => 'Secretarial',          'repo' => 'secretarial-react-app',  'hub' => 'secretarial',         'hand' => ['1.1', '2026-09-09', 'secretarial-v1-1']],
            'hrms'                => ['name' => 'HRMS',                 'repo' => 'hrms-react-app',         'hub' => 'hrms',                'hand' => ['2.0', '2026-09-08', 'hrms-v2-0']],
            'our-people'          => ['name' => 'Our People',           'repo' => 'our-people-react-app',   'hub' => 'our-people',          'hand' => ['1.2', '2026-09-06', 'our-people-v1-2']],
            'contacts'            => ['name' => 'Contacts',             'repo' => 'contacts-react-app',     'hub' => 'contacts',            'hand' => ['1.2', '2026-09-08', 'contacts-v1-2']],
            'calendar'            => ['name' => 'Calendar',             'repo' => 'calendar-react-app',     'hub' => 'calendar',            'hand' => ['1.2', '2026-09-08', 'calendar-v1-2']],
            'chat'                => ['name' => 'Connect',              'repo' => 'connect-aicountly',      'hub' => 'chat',                'hand' => ['2.0', '2026-09-12', 'chat-v2-0']],
            'vault'               => ['name' => 'Vault',                'repo' => 'vault-react-app',        'hub' => 'vault',               'hand' => ['1.2', '2026-09-08', 'vault-v1-2']],
            'buddy'               => ['name' => 'AI Pulse',             'repo' => 'pulse-aicountly',        'hub' => 'buddy',               'hand' => ['2.0', '2026-09-08', 'buddy-v2-0']],
            'remote'              => ['name' => 'Remote',               'repo' => 'remote-aicountly',       'hub' => 'remote',              'hand' => ['1.0', '2026-09-14', 'remote-v1-0']],
            'contracts'           => ['name' => 'Contracts',            'repo' => 'contracts-aicountly',    'hub' => 'contracts',           'hand' => ['1.0', '2026-09-12', 'contracts-v1-0']],
            'pay'                 => ['name' => 'Pay',                  'repo' => 'pay-aicountly',          'hub' => 'pay',                 'hand' => null],
            'capital'             => ['name' => 'Capital',              'repo' => 'capital-aicountly',      'hub' => 'capital',             'hand' => null],
            'valuations'          => ['name' => 'Valuations',           'repo' => 'valuations-aicountly',   'hub' => 'valuations',          'hand' => null],
            'pos'                 => ['name' => 'POS',                  'repo' => 'pos-aicountly',          'hub' => 'pos',                 'hand' => null],
            'sales'               => ['name' => 'Sales',                'repo' => 'sales-aicountly',        'hub' => 'sales',               'hand' => null],
            'purchases'           => ['name' => 'Purchase',             'repo' => 'purchases-aicountly',    'hub' => 'purchases',           'hand' => null],
            'insights'            => ['name' => 'Insights',             'repo' => 'Insights-aicountly',     'hub' => 'insights',            'hand' => null],
            'crm'                 => ['name' => 'CRM',                  'repo' => 'crm-aicountly',          'hub' => 'crm',                 'hand' => null],
            'helpdesk'            => ['name' => 'Helpdesk',             'repo' => 'helpdesk-aicountly',     'hub' => 'helpdesk',            'hand' => null],
            // Renamed Receptionist → Lobby on the site; the slug stays. The repository name is spelt this way on GitHub.
            'receptionist'        => ['name' => 'Lobby',                'repo' => 'lobby-aicouintly',       'hub' => 'receptionist',        'hand' => null],
            'advisor'             => ['name' => 'Advisor',              'repo' => 'advisor-aicountly',      'hub' => 'advisor',             'hand' => null],
            'integrate'           => ['name' => 'Integrate',            'repo' => 'integrate-aicountly',    'hub' => 'integrate',           'hand' => null],
            'apis'                => ['name' => 'APIs',                 'repo' => 'apis-aicountly',         'hub' => 'apis',                'hand' => null],
            'appointments'        => ['name' => 'Appointments',         'repo' => 'appointments-aicountly', 'hub' => 'appointments',        'hand' => null],
            'email'               => ['name' => 'Email',                'repo' => 'email-aicountly',        'hub' => 'email',               'hand' => null],
            'messaging'           => ['name' => 'Messaging',            'repo' => 'messaging-aicountly',    'hub' => 'messaging',           'hand' => null],
            'voice'               => ['name' => 'Voice',                'repo' => 'voice-aicountly',        'hub' => 'voice',               'hand' => null],
            'benefits'            => ['name' => 'Benefits',             'repo' => 'benefits-aicountly',     'hub' => 'benefits',            'hand' => null],
        ];
    }

    /**
     * Aicountly Academy and its courses. Training that Aicountly runs itself —
     * admissions, tests, project evaluation and certificates — for college
     * students. `repo` is the student-facing delivery app (null for the
     * Academy itself, whose repository is the internal staff app); `url` is
     * the public page on aicountly.com.
     *
     * @return array<string, array{name:string, repo:?string, hub:string, url:string, summary:string}>
     */
    private static function courses(): array
    {
        return [
            'academy' => ['name' => 'Aicountly Academy', 'repo' => null,               'hub' => 'academy', 'url' => 'https://www.aicountly.com/academy',
                'summary' => 'Industry training for college students, run by Aicountly: Aicountly Edge (long-term programme) and Aicountly Aspire (short-term courses). Aicountly runs admissions, tests, project evaluation and certificates.'],
            'edge'    => ['name' => 'Aicountly Edge',    'repo' => 'edge-aicountly',   'hub' => 'edge',    'url' => 'https://www.aicountly.com/edge',
                'summary' => 'Long-term (6–12 month) hands-on accounting training programme for college students, run by Aicountly Academy: admission, practical and project work evaluated by Aicountly, tests, and a certificate.'],
            'aspire'  => ['name' => 'Aicountly Aspire',  'repo' => 'aspire-aicountly', 'hub' => 'aspire',  'url' => 'https://www.aicountly.com/aspire',
                'summary' => 'Short-term workshops and courses for college students, at colleges or open, run by Aicountly Academy, with assessments and a certificate.'],
        ];
    }

    public static function has(string $slug): bool
    {
        return isset(self::products()[$slug]) || isset(self::courses()[$slug]);
    }

    public static function isLaunch(string $slug): bool
    {
        return in_array($slug, self::LAUNCH, true);
    }

    /** Aicountly Academy or one of its courses (not software, never Beta). */
    public static function isAcademy(string $slug): bool
    {
        return isset(self::courses()[$slug]);
    }

    /** An Aicountly app (launch or Beta) — what release notes and workflow guides are about. */
    public static function isSoftware(string $slug): bool
    {
        return isset(self::products()[$slug]);
    }

    /** Software that is not a launch app. Academy courses are never Beta. */
    public static function isBeta(string $slug): bool
    {
        return self::isSoftware($slug) && ! self::isLaunch($slug);
    }

    /** "live" | "beta" | "course" | null */
    public static function status(string $slug): ?string
    {
        return match (true) {
            self::isAcademy($slug) => 'course',
            self::isLaunch($slug)  => 'live',
            self::isBeta($slug)    => 'beta',
            default                => null,
        };
    }

    /**
     * @return array{slug:string, name:string, kind:string, status:string, repo:?string, repo_full_name:?string, hub:string, launch:bool, academy:bool, public_url:?string, summary:?string, hand_release:?array{version:string, date_iso:string, slug:string}}|null
     */
    public static function get(string $slug): ?array
    {
        $c = self::courses()[$slug] ?? null;
        if ($c !== null) {
            return [
                'slug'           => $slug,
                'name'           => $c['name'],
                'kind'           => self::KIND_COURSE,
                'status'         => 'course',
                'repo'           => $c['repo'],
                'repo_full_name' => $c['repo'] === null ? null : 'aicountly/' . $c['repo'],
                'hub'            => $c['hub'],
                'launch'         => false,
                'academy'        => true,
                'public_url'     => $c['url'],
                'summary'        => $c['summary'],
                'hand_release'   => null,
            ];
        }

        $p = self::products()[$slug] ?? null;
        if ($p === null) {
            return null;
        }

        return [
            'slug'           => $slug,
            'name'           => $p['name'],
            'kind'           => self::KIND_APP,
            'status'         => self::isLaunch($slug) ? 'live' : 'beta',
            'repo'           => $p['repo'],
            'repo_full_name' => 'aicountly/' . $p['repo'],
            'hub'            => $p['hub'],
            'launch'         => self::isLaunch($slug),
            'academy'        => false,
            'public_url'     => null,
            'summary'        => null,
            'hand_release'   => $p['hand'] === null ? null : ['version' => $p['hand'][0], 'date_iso' => $p['hand'][1], 'slug' => $p['hand'][2]],
        ];
    }

    /**
     * Every catalogued product: launch apps in launcher order, then Beta by
     * name, then Aicountly Academy and its courses.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_merge(self::software(), self::academy());
    }

    /**
     * The Aicountly apps only: launch apps in launcher order, then Beta by
     * name. Release notes are planned over this list.
     *
     * @return list<array<string, mixed>>
     */
    public static function software(): array
    {
        $launch = array_map(static fn (string $s) => self::get($s), self::LAUNCH);
        $beta   = [];
        foreach (array_keys(self::products()) as $slug) {
            if (! self::isLaunch($slug)) {
                $beta[] = self::get($slug);
            }
        }
        usort($beta, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return array_merge($launch, $beta);
    }

    /** @return list<array<string, mixed>> */
    public static function launch(): array
    {
        return array_map(static fn (string $s) => self::get($s), self::LAUNCH);
    }

    /** @return list<array<string, mixed>> Aicountly Academy, then its courses */
    public static function academy(): array
    {
        return array_map(static fn (string $s) => self::get($s), self::ACADEMY);
    }

    /**
     * The prefix of a product's release slugs. Smart Books' hand-written
     * releases are smart-books-vX-Y; every other product uses its slug.
     */
    public static function releaseSlugPrefix(string $slug): string
    {
        return $slug === 'books' ? 'smart-books' : $slug;
    }

    public static function siteBase(): string
    {
        $base = (string) (env('AICOUNTLY_PUBLIC_SITE_BASE_URL') ?: 'https://aicountly.com');

        return rtrim($base, '/');
    }

    public static function guideUrl(string $productSlug, string $guideSlug): string
    {
        $hub = self::get($productSlug)['hub'] ?? $productSlug;

        return self::siteBase() . '/guides/' . $hub . '/' . rawurlencode($guideSlug);
    }
}
