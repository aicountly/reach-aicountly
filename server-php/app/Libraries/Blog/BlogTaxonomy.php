<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use App\Libraries\Automation\Structured\ProductCatalog;

/**
 * The aicountly.com blog taxonomy — the one list Reach uses for a blog's
 * topic category and the app it is about (owner decision, 23 Oct 2026, shared
 * with aicountly-com; the receiver canonicalises again on its side).
 *
 *   category      ONE of the 15 topic slugs below, in three groups. Every old
 *                 value and every label the routine used to send maps to one
 *                 of them through ALIASES (case-insensitive; spaces, "&", "/",
 *                 "_" and "-" are interchangeable). Aliases are never dropped:
 *                 old ?category= URLs on the site 301 to the canonical slug.
 *   product_slug  optional: the aicountly.com catalogue slug of the app the
 *                 post is about (aicountly-com includes/saas-products.php).
 *                 The vocabulary is ProductCatalog — the copy Reach already
 *                 keeps of that catalogue — so there is no third product list.
 *                 Live = the launch apps (saas_launch_product_slugs()); every
 *                 other catalogued app is Beta and is always presented as
 *                 Beta. Aicountly Academy and its courses (academy, edge,
 *                 aspire) are status "course": Aicountly-run training, not
 *                 software, so never Beta and never asked to say "Beta".
 *
 * Product slug mismatches resolved here (PRODUCT_SYNONYMS):
 *   - LaunchApps / reach_products (the KB side) call Drive `drive`; the site
 *     catalogue calls it `docs`. KB keeps `drive` (its rows are keyed on it);
 *     a blog always carries `docs`.
 *   - SaasProductTaxonomy (Traffic Analytics, ported from Flow) uses
 *     smart_books, manage_account, financial_reporting, our_people, purchase,
 *     connect; the site uses books, manage, financial-reporting, our-people,
 *     purchases, chat (named "Connect").
 *
 * Mix target (owner): half compliance/finance topic articles, half app-led
 * (an article with a product_slug); of the app-led ones, live apps first and
 * about one in five about a Beta app.
 */
final class BlogTaxonomy
{
    public const GROUP_COMPLIANCE = 'compliance';
    public const GROUP_BUSINESS   = 'business';
    public const GROUP_AICOUNTLY  = 'aicountly';

    /** @var array<string, string> group => label, display order */
    public const GROUPS = [
        self::GROUP_COMPLIANCE => 'Compliance',
        self::GROUP_BUSINESS   => 'Business',
        self::GROUP_AICOUNTLY  => 'Aicountly',
    ];

    /**
     * slug => [label, group, when to use it]. Display order. Slugs and labels
     * are fixed by the contract — do not rename.
     *
     * @var array<string, array{0:string, 1:string, 2:string}>
     */
    public const TOPICS = [
        'accounting'                 => ['Accounting', self::GROUP_COMPLIANCE, 'Bookkeeping, ledgers, bank reconciliation, month/year-end close, financial statements (Schedule III, P&L, balance sheet) and financial reporting.'],
        'gst'                        => ['GST', self::GROUP_COMPLIANCE, 'GST returns (GSTR-1/3B/9), input tax credit, GSTR-2B reconciliation, e-invoicing, e-way bills, GST rates and registrations.'],
        'income-tax'                 => ['Income Tax', self::GROUP_COMPLIANCE, 'ITR filing, advance tax, presumptive taxation, deductions, new laws and provisions for businesses and their owners.'],
        'tds-tcs'                    => ['TDS/TCS', self::GROUP_COMPLIANCE, 'TDS/TCS sections and rates, deposits, returns (24Q/26Q/27EQ), certificates (Form 16/16A) and 194Q vs 206C(1H).'],
        'audit-assurance'            => ['Audit & Assurance', self::GROUP_COMPLIANCE, 'Statutory, tax and internal audit, audit trails, working papers and audit readiness.'],
        'company-law'                => ['Company Law', self::GROUP_COMPLIANCE, 'MCA/ROC filings, annual returns, board meetings, LLP and private company compliance, secretarial work.'],
        'payroll-hr'                 => ['Payroll & HR', self::GROUP_COMPLIANCE, 'Payroll, PF, ESI, professional tax, LWF, payslips, leave and HR compliance.'],
        'billing-invoicing'          => ['Billing & Invoicing', self::GROUP_BUSINESS, 'Creating and sending invoices, quotations, credit notes, payment follow-up and collections.'],
        'inventory-stock'            => ['Inventory & Stock', self::GROUP_BUSINESS, 'Stock tracking, items and warehouses, reorder levels, stock valuation and stock audits.'],
        'sales-crm'                  => ['Sales & CRM', self::GROUP_BUSINESS, 'Leads, customers, pipelines, follow-ups and sales processes.'],
        'productivity-collaboration' => ['Productivity & Collaboration', self::GROUP_BUSINESS, 'Notes, documents and file storage, sharing, team collaboration and getting organised (Notes, Drive).'],
        'business-operations'        => ['Business Operations', self::GROUP_BUSINESS, 'Running the business: cash flow, funding, subsidies and schemes, processes, controls and growth.'],
        'product-guides'             => ['Product Guides', self::GROUP_AICOUNTLY, 'How to do a specific job in an Aicountly app, step by step (product_slug required in practice).'],
        'product-updates'            => ['Product Updates', self::GROUP_AICOUNTLY, 'What shipped recently in an Aicountly app, from its git log (product_slug required in practice).'],
        'ai-for-finance'             => ['AI for Finance', self::GROUP_AICOUNTLY, 'How AI changes accounting, tax and compliance work — practical, no hype.'],
    ];

    /**
     * Old values / labels → canonical slug, keyed by their loose form (see
     * looseKey()). Labels that already loosen to a canonical slug ("Income
     * Tax", "TDS/TCS", "Payroll & HR", "Product guides") need no entry.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        // Old aicountly.com slugs / labels.
        'financial-reporting'   => 'accounting',
        'audit'                 => 'audit-assurance',
        'ai-finance-compliance' => 'ai-for-finance',
        'ai'                    => 'ai-for-finance',
        'ai-promotions'         => 'ai-for-finance',
        'funding-promotions'    => 'business-operations',
        'subsidies-promotions'  => 'business-operations',
        'new-laws-provisions'   => 'income-tax',
        'erp'                   => 'product-guides',
        'platform'              => 'product-updates',
        // Short labels the routine sent before 23 Oct 2026.
        'tds'                   => 'tds-tcs',
        'tcs'                   => 'tds-tcs',
        'payroll'               => 'payroll-hr',
        'billing'               => 'billing-invoicing',
        'invoicing'             => 'billing-invoicing',
        'inventory'             => 'inventory-stock',
        'stock'                 => 'inventory-stock',
        'crm'                   => 'sales-crm',
        'sales'                 => 'sales-crm',
        'productivity'          => 'productivity-collaboration',
        'collaboration'         => 'productivity-collaboration',
        'product-guide'         => 'product-guides',
        'product-update'        => 'product-updates',
    ];

    /**
     * Reach-side product slugs → the site catalogue slug (ProductCatalog).
     *
     * @var array<string, string>
     */
    public const PRODUCT_SYNONYMS = [
        'smart_books'         => 'books',
        'smart-books'         => 'books',
        'smartbooks'          => 'books',
        'drive'               => 'docs',
        'manage_account'      => 'manage',
        'manage-account'      => 'manage',
        'financial_reporting' => 'financial-reporting',
        'our_people'          => 'our-people',
        'purchase'            => 'purchases',
        'connect'             => 'chat',
        'pulse'               => 'buddy',
        'ai-pulse'            => 'buddy',
        'lobby'               => 'receptionist',
        'aicountly-academy'   => 'academy',
        'aicountly-edge'      => 'edge',
        'aicountly-aspire'    => 'aspire',
    ];

    /** Owner's mix: share of app-led articles, and of those, the Beta share. */
    public const TARGET_APP_LED_SHARE = 0.5;
    public const TARGET_BETA_SHARE_OF_APP_LED = 0.2;

    /** @return list<string> the 15 canonical slugs, display order */
    public static function slugs(): array
    {
        return array_keys(self::TOPICS);
    }

    public static function isCanonical(?string $slug): bool
    {
        return $slug !== null && isset(self::TOPICS[$slug]);
    }

    /**
     * The canonical slug for any slug, old value or label; null when it is
     * empty or matches nothing.
     */
    public static function canonical(?string $value): ?string
    {
        $key = self::looseKey((string) $value);
        if ($key === '') {
            return null;
        }
        foreach ([$key, str_replace('-and-', '-', $key)] as $k) {
            if (isset(self::TOPICS[$k])) {
                return $k;
            }
            if (isset(self::ALIASES[$k])) {
                return self::ALIASES[$k];
            }
        }

        return null;
    }

    /** "Payroll & HR" → "payroll-hr", "ai_promotions" → "ai-promotions". */
    public static function looseKey(string $value): string
    {
        $v = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim($value))) ?? '';

        return trim($v, '-');
    }

    public static function label(?string $value): ?string
    {
        $slug = self::canonical($value);

        return $slug !== null ? self::TOPICS[$slug][0] : null;
    }

    /** The label when the value is known, the value as given otherwise. */
    public static function displayLabel(?string $value): string
    {
        return self::label($value) ?? trim((string) $value);
    }

    public static function group(?string $value): ?string
    {
        $slug = self::canonical($value);

        return $slug !== null ? self::TOPICS[$slug][1] : null;
    }

    /**
     * Every spelling that means the same category — the canonical slug, its
     * label and its aliases — lower-cased. Cover tags written under the old
     * labels ("income tax", "tds") still match a post filed under the new
     * slug ("income-tax", "tds-tcs") and vice versa.
     *
     * @return list<string>
     */
    public static function spellings(?string $value): array
    {
        $slug = self::canonical($value);
        if ($slug === null) {
            $v = mb_strtolower(trim((string) $value));

            return $v === '' ? [] : [$v];
        }
        $out = [$slug, mb_strtolower(self::TOPICS[$slug][0]), str_replace('-', ' ', $slug)];
        foreach (self::ALIASES as $alias => $to) {
            if ($to === $slug) {
                $out[] = $alias;
                $out[] = str_replace('-', ' ', $alias);
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<array{slug:string, label:string, group:string, group_label:string, use_for:string}> */
    public static function topics(): array
    {
        $out = [];
        foreach (self::TOPICS as $slug => [$label, $group, $use]) {
            $out[] = ['slug' => $slug, 'label' => $label, 'group' => $group, 'group_label' => self::GROUPS[$group], 'use_for' => $use];
        }

        return $out;
    }

    /** "accounting, gst, …" — for error messages the routine reads. */
    public static function allowedList(): string
    {
        return implode(', ', self::slugs());
    }

    // ── products ────────────────────────────────────────────────────────────

    /**
     * The site catalogue slug for a product slug (synonyms and "_" → "-"
     * folded); null when it is empty or not a catalogued product. Never
     * invents one.
     */
    public static function canonicalProduct(?string $value): ?string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }
        foreach ([$v, str_replace([' ', '_'], '-', $v)] as $k) {
            $k = self::PRODUCT_SYNONYMS[$k] ?? $k;
            if (ProductCatalog::has($k)) {
                return $k;
            }
        }

        return null;
    }

    public static function isLiveProduct(?string $slug): bool
    {
        $slug = self::canonicalProduct($slug);

        return $slug !== null && ProductCatalog::isLaunch($slug);
    }

    public static function isBetaProduct(?string $slug): bool
    {
        $slug = self::canonicalProduct($slug);

        return $slug !== null && ProductCatalog::isBeta($slug);
    }

    /** Aicountly Academy or one of its courses (Edge, Aspire). */
    public static function isAcademyProduct(?string $slug): bool
    {
        $slug = self::canonicalProduct($slug);

        return $slug !== null && ProductCatalog::isAcademy($slug);
    }

    /** "live" | "beta" | "course" | null */
    public static function productStatus(?string $slug): ?string
    {
        $slug = self::canonicalProduct($slug);

        return $slug === null ? null : ProductCatalog::status($slug);
    }

    /** @return list<string> live app slugs, launcher order */
    public static function liveProductSlugs(): array
    {
        return ProductCatalog::LAUNCH;
    }

    /** @return list<string> every valid product_slug, live first */
    public static function productSlugs(): array
    {
        return array_column(ProductCatalog::all(), 'slug');
    }

    /** @return array{slug:string, name:string, status:string, repo:string}|null */
    public static function product(?string $slug): ?array
    {
        $slug = self::canonicalProduct($slug);
        $p    = $slug !== null ? ProductCatalog::get($slug) : null;
        if ($p === null) {
            return null;
        }

        return ['slug' => $slug, 'name' => $p['name'], 'status' => $p['status'], 'repo' => $p['repo_full_name']];
    }

    /** How an article names the product: "HRMS (Beta)", "Smart Books", "Aicountly Edge". */
    public static function presentAs(?string $slug): ?string
    {
        $p = self::product($slug);

        return $p === null ? null : ($p['status'] === 'beta' ? $p['name'] . ' (Beta)' : $p['name']);
    }

    /**
     * Live apps, Beta apps and the Academy courses as the blog routine needs
     * them.
     *
     * @return array{live:list<array<string,?string>>, beta:list<array<string,?string>>, academy:list<array<string,?string>>}
     */
    public static function productsForRoutine(): array
    {
        $out = ['live' => [], 'beta' => [], 'academy' => []];
        foreach (ProductCatalog::all() as $p) {
            $row = ['product_slug' => $p['slug'], 'name' => $p['name'], 'repo' => $p['repo_full_name']];
            if ($p['academy']) {
                $out['academy'][] = $row + ['present_as' => $p['name'], 'public_url' => $p['public_url'], 'summary' => $p['summary']];
            } elseif ($p['launch']) {
                $out['live'][] = $row;
            } else {
                $out['beta'][] = $row + ['present_as' => $p['name'] . ' (Beta)'];
            }
        }

        return $out;
    }

    /**
     * Product named in a title — Smart Books by name, the other live apps
     * only as "Aicountly <Name>" or "<Name> app" (billing, notes, drive are
     * ordinary words). Live apps only.
     *
     * @return array{slug:string, match:string}|null
     */
    public static function productInTitle(string $title): ?array
    {
        if (preg_match('/\bsmart\s*books\b/i', $title, $m)) {
            return ['slug' => 'books', 'match' => $m[0]];
        }
        $names = ['billing' => 'Billing', 'inventory' => 'Inventory', 'notes' => 'Notes', 'docs' => 'Drive', 'manage' => 'Manage'];
        foreach ($names as $slug => $name) {
            if (preg_match('/\bAICOUNTLY\s+' . $name . '\b|\b' . $name . '\s+app\b/i', $title, $m)) {
                return ['slug' => $slug, 'match' => $m[0]];
            }
        }

        return null;
    }

    /**
     * Category from unambiguous title words; null when none or more than one
     * category matches. Used only to file posts that have no usable category.
     *
     * @return array{slug:string, match:string}|null
     */
    public static function categoryFromTitle(string $title): ?array
    {
        $rules = [
            'gst'             => '/\b(GST|GSTR-?\d\w*|GSTIN|e-?invoic\w*|e-?way bills?|input tax credit)\b/i',
            'tds-tcs'         => '/\b(TDS|TCS|Form 16A?|26Q|24Q|27EQ)\b/i',
            'income-tax'      => '/\b(income[- ]tax|ITR(-\d)?|advance tax)\b/i',
            'payroll-hr'      => '/\b(payroll|payslips?|EPF|ESIC?|provident fund|professional tax)\b/i',
            'company-law'     => '/\b(MCA|ROC|company law|LLP|annual return|AOC-4|MGT-7)\b/i',
            'audit-assurance' => '/\b(audit|auditors?)\b/i',
            'inventory-stock' => '/\b(inventory|stock (?:valuation|audit|levels?))\b/i',
        ];
        $hits = [];
        foreach ($rules as $slug => $re) {
            if (preg_match($re, $title, $m)) {
                $hits[$slug] = $m[0];
            }
        }
        if (count($hits) !== 1) {
            return null;
        }

        return ['slug' => (string) array_key_first($hits), 'match' => (string) reset($hits)];
    }

    /** Portfolio streams (the roadmap's 45/35/20 split), not categories. */
    public const STREAMS = ['marketing', 'product', 'problem_to_product'];

    /**
     * What a next-topic queue item means for the taxonomy: the category to
     * file it under (when the topic says), whether it is app-led, and the app.
     * A topic only has a product *affinity* unless its stream is product /
     * problem_to_product or its category is an Aicountly one — a GST topic
     * that ends on a Smart Books mention stays a topic article.
     *
     * @param array<string, mixed> $details the entry's payload
     * @return array{category:?string, portfolio_stream:?string, app_led:bool, product_slug:?string, product_affinity:?string, product_status:?string, present_product_as:?string}
     */
    public static function queueHints(?string $category, ?string $productSlug, array $details = []): array
    {
        $stream = in_array($category, self::STREAMS, true) ? $category : (in_array($details['portfolio_stream'] ?? null, self::STREAMS, true) ? $details['portfolio_stream'] : null);
        $slug   = self::canonical(is_string($details['topic_category'] ?? null) ? $details['topic_category'] : null)
            ?? (in_array($category, self::STREAMS, true) ? null : self::canonical($category));
        $product = self::canonicalProduct($productSlug);
        $appLed  = $product !== null && (in_array($stream, ['product', 'problem_to_product'], true) || ($slug !== null && self::TOPICS[$slug][1] === self::GROUP_AICOUNTLY));
        $p       = self::product($product);

        return [
            'category'           => $slug,
            'portfolio_stream'   => $stream,
            'app_led'            => $appLed,
            'product_slug'       => $appLed ? $product : null,
            'product_affinity'   => $product,
            'product_status'     => $p['status'] ?? null,
            'present_product_as' => self::presentAs($product),
        ];
    }

    // ── mix ─────────────────────────────────────────────────────────────────

    /**
     * The mix of a set of posts against the owner's target, and what the next
     * article should be.
     *
     * @param list<array{category?:?string, product_slug?:?string}> $posts
     * @return array<string, mixed>
     */
    public static function mix(array $posts): array
    {
        $byGroup    = array_fill_keys(array_keys(self::GROUPS), 0) + ['uncategorised' => 0];
        $byCategory = [];
        $byProduct  = [];
        $appLed = $beta = $academy = 0;
        foreach ($posts as $p) {
            $cat   = self::canonical($p['category'] ?? null);
            $group = $cat !== null ? self::TOPICS[$cat][1] : 'uncategorised';
            $byGroup[$group]++;
            $byCategory[$cat ?? 'uncategorised'] = ($byCategory[$cat ?? 'uncategorised'] ?? 0) + 1;
            $prod = self::canonicalProduct($p['product_slug'] ?? null);
            if ($prod !== null) {
                $appLed++;
                $byProduct[$prod] = ($byProduct[$prod] ?? 0) + 1;
                if (ProductCatalog::isBeta($prod)) {
                    $beta++;
                } elseif (ProductCatalog::isAcademy($prod)) {
                    $academy++;
                }
            }
        }
        $total = count($posts);
        $topic = $total - $appLed;
        arsort($byCategory);

        // Next: whichever side is behind; equal → either. App-led: the next
        // one is Beta when Beta is below one in five of the app-led posts
        // including it, live otherwise — the least-covered live app first.
        $next = $appLed < $topic ? 'app_led' : ($topic < $appLed ? 'topic' : 'either');
        $nextBeta = $beta < (int) floor(($appLed + 1) * self::TARGET_BETA_SHARE_OF_APP_LED);
        $live = ProductCatalog::LAUNCH;
        usort($live, static fn (string $a, string $b): int => [($byProduct[$a] ?? 0), array_search($a, ProductCatalog::LAUNCH, true)]
            <=> [($byProduct[$b] ?? 0), array_search($b, ProductCatalog::LAUNCH, true)]);

        return [
            'posts'          => $total,
            'topic'          => $topic,
            'app_led'        => $appLed,
            'app_led_live'   => $appLed - $beta - $academy,
            'app_led_beta'   => $beta,
            'app_led_academy' => $academy,
            'app_led_share'  => $total > 0 ? round($appLed / $total, 2) : null,
            'beta_share_of_app_led' => $appLed > 0 ? round($beta / $appLed, 2) : null,
            'by_group'       => $byGroup,
            'by_category'    => $byCategory,
            'by_product'     => $byProduct,
            'target'         => ['app_led_share' => self::TARGET_APP_LED_SHARE, 'topic_share' => 1 - self::TARGET_APP_LED_SHARE, 'beta_share_of_app_led' => self::TARGET_BETA_SHARE_OF_APP_LED],
            'next'           => [
                'kind'                  => $next,
                'app_status'            => $next === 'topic' ? null : ($nextBeta ? 'beta' : 'live'),
                'least_covered_live_apps' => array_slice($live, 0, 3),
            ],
        ];
    }
}
