<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The blog mix as it stands — by category group, and app-led (a post with a
 * product_slug) vs compliance/finance topic — against the owner's 50/50
 * target with about one app-led post in five about a Beta app
 * (BlogTaxonomy::mix). The blog routine reads it in blog-plan to choose what
 * to write next; the bot plan reads it for its topic-mix steering.
 *
 * Counts every blog that is live or on its way (not rejected, archived or
 * deleted), dated by when it went live, else when it was created: a draft
 * still in vetting already counts, so six runs a day do not all pick the
 * same side.
 */
class BlogMixService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<array{category:?string, product_slug:?string}>
     */
    public function posts(string $sinceUtc, ?string $untilUtc = null): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_blog_publication_profiles')) {
            return [];
        }
        $product = SchemaGuard::hasColumn($this->db, 'reach_blog_publication_profiles', 'product_slug') ? 'p.product_slug' : 'NULL';
        $sql = "SELECT p.category, {$product} AS product_slug
                FROM reach_content_items i
                LEFT JOIN reach_blog_publication_profiles p ON p.content_item_id = i.id
                WHERE i.content_type = 'blog' AND i.deleted_at IS NULL
                  AND i.workflow_status NOT IN ('rejected', 'archived', 'unpublished')
                  AND COALESCE(i.published_at, i.created_at) >= ?";
        $binds = [$sinceUtc];
        if ($untilUtc !== null) {
            $sql    .= ' AND COALESCE(i.published_at, i.created_at) < ?';
            $binds[] = $untilUtc;
        }

        return array_map(static fn (array $r): array => [
            'category'     => $r['category'] !== null ? (string) $r['category'] : null,
            'product_slug' => $r['product_slug'] !== null ? (string) $r['product_slug'] : null,
        ], $this->db->query($sql, $binds)->getResultArray());
    }

    /**
     * Last 7 and 30 days, the target and what the next article should be
     * (decided on the 30-day window).
     *
     * @return array<string, mixed>
     */
    public function summary(?\DateTimeImmutable $now = null): array
    {
        $now  = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
        $d7   = BlogTaxonomy::mix($this->posts($now->modify('-7 days')->format('Y-m-d H:i:sP')));
        $d30  = BlogTaxonomy::mix($this->posts($now->modify('-30 days')->format('Y-m-d H:i:sP')));
        $next = $d30['next'];

        return [
            'target'       => $d30['target'],
            'last_7_days'  => array_diff_key($d7, ['target' => true, 'next' => true]),
            'last_30_days' => array_diff_key($d30, ['target' => true, 'next' => true]),
            'next'         => $next + ['why' => self::why($d30)],
        ];
    }

    /** One sentence the routine can act on. */
    public static function why(array $mix, string $window = 'Last 30 days'): string
    {
        $n = (int) $mix['posts'];
        $share = $mix['app_led_share'] === null ? 'no posts yet' : sprintf('%d of %d posts app-led (%d%%)', $mix['app_led'], $n, (int) round($mix['app_led_share'] * 100));
        $apps  = implode(', ', (array) ($mix['next']['least_covered_live_apps'] ?? []));

        return match ($mix['next']['kind']) {
            'app_led' => "{$window}: {$share}, below the 50% target — write an app-led article"
                . ($mix['next']['app_status'] === 'beta'
                    ? sprintf(' about a Beta app (%d of %d app-led posts were Beta; the target is about 1 in 5), presented as Beta.', $mix['app_led_beta'], $mix['app_led'])
                    : " about a live app, least covered first: {$apps}."),
            'topic'   => "{$window}: {$share}, above the 50% target — write a compliance/finance topic article (no product_slug).",
            default   => "{$window}: {$share}, on the 50/50 target — either kind; an app-led one should be about a "
                . ($mix['next']['app_status'] === 'beta' ? 'Beta app, presented as Beta.' : "live app (least covered: {$apps})."),
        };
    }
}
