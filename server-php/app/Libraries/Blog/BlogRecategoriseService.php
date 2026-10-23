<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use App\Libraries\AuditLogger;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Files every existing blog under the new aicountly.com taxonomy
 * (`php spark reach:blog-recategorise`).
 *
 * Per blog (reach_blog_publication_profiles):
 *   category      the stored value canonicalised (old labels and slugs →
 *                 BlogTaxonomy). Only when it maps to nothing: the content-base
 *                 entry's topic category, else one unambiguous title keyword.
 *   product_slug  kept (canonicalised) when set. Else, with evidence:
 *                   - the title names a live app ("Smart Books", "Aicountly
 *                     Billing", "Notes app") → that app;
 *                   - the topic candidate's "[product]" note or the content-base
 *                     entry's product_slug → that app, but only when the post is
 *                     app-led (stream product / problem_to_product, or an
 *                     Aicountly category). A marketing-stream post only has a
 *                     product affinity and stays a topic article.
 *                 Anything less certain stays NULL.
 *
 * Article text is never touched. apply() writes the profiles; republish()
 * queues the normal publication deployment for changed live posts so the
 * aicountly.com packages pick up the new category / product_slug.
 */
class BlogRecategoriseService
{
    /** Item states whose post is on aicountly.com now (a refresh in progress republishes on its own). */
    public const LIVE_STATES = [BlogStateMachine::PUBLISHED, BlogStateMachine::VERIFICATION_PENDING, BlogStateMachine::LIVE, BlogStateMachine::REFRESH_PENDING];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, before: array<string, mixed>, after: array<string, mixed>, changed: int, unmapped: list<array<string, mixed>>}
     */
    public function plan(?int $onlyId = null): array
    {
        $hasProduct = SchemaGuard::hasColumn($this->db, 'reach_blog_publication_profiles', 'product_slug');
        $b = $this->db->table('reach_content_items i')
            ->select('i.id, i.title, i.slug, i.content_base_key, i.workflow_status, i.approval_status, i.current_version_id, p.id AS profile_id, p.category, '
                . ($hasProduct ? 'p.product_slug' : 'NULL AS product_slug') . ', d.portfolio_stream', false)
            ->join('reach_blog_publication_profiles p', 'p.content_item_id = i.id', 'inner')
            ->join('reach_content_blog_details d', 'd.content_item_id = i.id', 'left')
            ->where('i.content_type', 'blog')
            ->where('i.deleted_at IS NULL', null, false)
            ->orderBy('i.id', 'ASC');
        if ($onlyId !== null) {
            $b->where('i.id', $onlyId);
        }
        $items = $b->get()->getResultArray();

        $rows = [];
        $before = $after = [];
        foreach ($items as $item) {
            $row = $this->decide($item);
            $rows[] = $row;
            $before[] = ['category' => $item['category'], 'product_slug' => $item['product_slug']];
            $after[]  = ['category' => $row['category_after'] ?? $item['category'], 'product_slug' => $row['product_after']];
        }

        return [
            'rows'     => $rows,
            'before'   => self::counts($before, false),
            'after'    => self::counts($after, true),
            'changed'  => count(array_filter($rows, static fn ($r) => $r['changed'])),
            'unmapped' => array_values(array_filter($rows, static fn ($r) => $r['category_after'] === null)),
        ];
    }

    /**
     * The owner's own choice for the post(s) in a plan built for one --id:
     * category and/or product_slug replace what was inferred. $category and
     * $product must already be canonical (or null for "no app"); $setProduct
     * says whether the product was given at all.
     *
     * @param array<string, mixed> $plan from plan()
     * @return array<string, mixed>
     */
    public function withOverride(array $plan, ?string $category, bool $setProduct, ?string $product): array
    {
        $after = [];
        foreach ($plan['rows'] as $i => $row) {
            if ($category !== null) {
                $row['category_after'] = $category;
                $row['evidence'][]     = 'category ' . $category . ' set by the owner (--category)';
            }
            if ($setProduct) {
                $row['product_after'] = $product;
                $row['evidence'][]    = 'product_slug ' . ($product ?? '(none)') . ' set by the owner (--product)';
            }
            $row['changed'] = ($row['category_after'] !== null && $row['category_after'] !== $row['category_before'])
                || $row['product_after'] !== $row['product_before'];
            $plan['rows'][$i] = $row;
            $after[] = ['category' => $row['category_after'] ?? $row['category_before'], 'product_slug' => $row['product_after']];
        }
        $plan['after']    = self::counts($after, true);
        $plan['changed']  = count(array_filter($plan['rows'], static fn ($r) => $r['changed']));
        $plan['unmapped'] = array_values(array_filter($plan['rows'], static fn ($r) => $r['category_after'] === null));

        return $plan;
    }

    /**
     * Write the planned profile changes. Text and versions are not touched.
     *
     * @param array<string, mixed> $plan from plan()
     * @return int profiles updated
     */
    public function apply(array $plan): int
    {
        $hasProduct = SchemaGuard::hasColumn($this->db, 'reach_blog_publication_profiles', 'product_slug');
        $n = 0;
        foreach ($plan['rows'] as $row) {
            if (! $row['changed']) {
                continue;
            }
            $update = ['updated_at' => date('Y-m-d H:i:s')];
            if ($row['category_after'] !== null && $row['category_after'] !== $row['category_before']) {
                $update['category'] = $row['category_after'];
            }
            if ($hasProduct && $row['product_after'] !== $row['product_before']) {
                $update['product_slug'] = $row['product_after'];
            }
            if (count($update) === 1) {
                continue;
            }
            $this->db->table('reach_blog_publication_profiles')->where('id', $row['profile_id'])->update($update);
            $n++;
        }
        if ($n > 0) {
            AuditLogger::record('blog.recategorised', [
                'profiles_updated' => $n,
                'items'            => array_values(array_map(static fn ($r) => $r['content_item_id'], array_filter($plan['rows'], static fn ($r) => $r['changed']))),
            ]);
        }

        return $n;
    }

    /**
     * Queue the normal publication deployment for every changed post that is
     * live, so aicountly.com receives the canonical category / product_slug.
     * Queued is not published: the publishing worker sends them.
     *
     * @param array<string, mixed> $plan
     * @return list<array{content_item_id:int, title:string, queued:bool, deployment_id:?int, reason:?string}>
     */
    public function republish(array $plan, ?PublicationDeploymentService $deployments = null): array
    {
        $deployments ??= new PublicationDeploymentService();
        $out = [];
        foreach ($plan['rows'] as $row) {
            if (! $row['changed'] || ! $row['live']) {
                continue;
            }
            $res = ['content_item_id' => $row['content_item_id'], 'title' => $row['title'], 'queued' => false, 'deployment_id' => null, 'reason' => null];
            if (($row['approval_status'] ?? '') !== 'approved' || (int) $row['current_version_id'] <= 0) {
                $res['reason'] = 'not approved or no current version; publishing would be refused';
                $out[] = $res;
                continue;
            }
            try {
                $res['deployment_id'] = $deployments->enqueuePublication((int) $row['content_item_id'], (int) $row['current_version_id'], 'aicountly_com', 'publish');
                $res['queued'] = true;
            } catch (\Throwable $e) {
                $res['reason'] = mb_substr($e->getMessage(), 0, 300);
            }
            $out[] = $res;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function decide(array $item): array
    {
        $title    = (string) $item['title'];
        $evidence = [];

        // Category.
        $catBefore = $item['category'] !== null ? (string) $item['category'] : null;
        $cat       = BlogTaxonomy::canonical($catBefore);
        $entry     = $this->contentBaseEntry((string) ($item['content_base_key'] ?? ''));
        if ($cat !== null) {
            if ($cat !== $catBefore) {
                $evidence[] = sprintf('category "%s" → %s (alias)', $catBefore, $cat);
            }
        } else {
            $payload = $entry !== null ? (json_decode((string) ($entry['payload_json'] ?? '{}'), true) ?: []) : [];
            $fromEntry = BlogTaxonomy::canonical(is_string($payload['topic_category'] ?? null) ? $payload['topic_category'] : null)
                ?? (in_array($entry['category'] ?? null, BlogTaxonomy::STREAMS, true) ? null : BlogTaxonomy::canonical($entry['category'] ?? null));
            if ($fromEntry !== null) {
                $cat = $fromEntry;
                $evidence[] = sprintf('category %s from content-base entry %s (stored "%s" maps to nothing)', $cat, $entry['entry_key'], (string) $catBefore);
            } elseif (($t = BlogTaxonomy::categoryFromTitle($title)) !== null) {
                $cat = $t['slug'];
                $evidence[] = sprintf('category %s from title word "%s" (stored "%s" maps to nothing)', $cat, $t['match'], (string) $catBefore);
            }
        }

        // Product.
        $prodBefore = $item['product_slug'] !== null ? (string) $item['product_slug'] : null;
        $prod       = BlogTaxonomy::canonicalProduct($prodBefore);
        $notes      = [];
        if ($prod !== null) {
            if ($prod !== $prodBefore) {
                $evidence[] = sprintf('product_slug "%s" → %s (synonym)', $prodBefore, $prod);
            }
        } else {
            $stream = (string) ($item['portfolio_stream'] ?? '');
            $appLed = in_array($stream, ['product', 'problem_to_product'], true)
                || ($cat !== null && BlogTaxonomy::group($cat) === BlogTaxonomy::GROUP_AICOUNTLY);
            $affinity = [];
            if (($n = $this->candidateProduct((int) $item['id'])) !== null && ($c = BlogTaxonomy::canonicalProduct($n)) !== null) {
                $affinity[] = [$c, sprintf('topic candidate note "[product] %s"', $n)];
            }
            if ($entry !== null && ($c = BlogTaxonomy::canonicalProduct($entry['product_slug'] ?? null)) !== null) {
                $affinity[] = [$c, sprintf('content-base entry %s product_slug "%s"', $entry['entry_key'], $entry['product_slug'])];
            }
            $inTitle = BlogTaxonomy::productInTitle($title);
            if ($inTitle !== null) {
                $prod = $inTitle['slug'];
                $evidence[] = sprintf('product_slug %s: title names the app ("%s")', $prod, $inTitle['match']);
            } elseif ($affinity !== [] && $appLed && count(array_unique(array_column($affinity, 0))) === 1) {
                $prod = $affinity[0][0];
                $evidence[] = sprintf('product_slug %s from %s (app-led: %s)', $prod, implode(' and ', array_column($affinity, 1)),
                    $stream !== '' && in_array($stream, ['product', 'problem_to_product'], true) ? "stream {$stream}" : "category {$cat}");
            } elseif ($affinity !== []) {
                $notes[] = sprintf('product affinity %s (%s) not applied: %s', implode('/', array_unique(array_column($affinity, 0))), implode('; ', array_column($affinity, 1)),
                    $appLed ? 'sources disagree' : 'a ' . ($stream !== '' ? $stream : 'topic') . '-stream post is a topic article, not app-led');
            }
        }

        $catAfter = $cat;
        $changed  = ($catAfter !== null && $catAfter !== $catBefore) || $prod !== $prodBefore;

        return [
            'content_item_id'    => (int) $item['id'],
            'profile_id'         => (int) $item['profile_id'],
            'title'              => $title,
            'slug'               => (string) $item['slug'],
            'workflow_status'    => (string) $item['workflow_status'],
            'approval_status'    => (string) ($item['approval_status'] ?? ''),
            'current_version_id' => (int) ($item['current_version_id'] ?? 0),
            'live'               => in_array((string) $item['workflow_status'], self::LIVE_STATES, true),
            'category_before'    => $catBefore,
            'category_after'     => $catAfter,
            'product_before'     => $prodBefore,
            'product_after'      => $prod,
            'changed'            => $changed,
            'evidence'           => $evidence,
            'notes'              => $notes,
        ];
    }

    /** @return array<string, mixed>|null */
    private function contentBaseEntry(string $key): ?array
    {
        if ($key === '' || ! SchemaGuard::hasTable($this->db, 'reach_content_base_entries')) {
            return null;
        }

        return $this->db->table('reach_content_base_entries')
            ->where('content_type', 'blog')
            ->groupStart()->where('entry_key', strtolower($key))->orWhere('content_key', strtolower($key))->groupEnd()
            ->orderBy('id', 'ASC')->limit(1)
            ->get()->getRowArray() ?: null;
    }

    private function candidateProduct(int $itemId): ?string
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_topic_candidates')) {
            return null;
        }
        $notes = (string) ($this->db->table('reach_topic_candidates')->select('notes')
            ->where('content_item_id', $itemId)->orderBy('id', 'ASC')->limit(1)
            ->get()->getRowArray()['notes'] ?? '');

        return preg_match('/^\[product\]\s*(\S+)/m', $notes, $m) === 1 ? $m[1] : null;
    }

    /**
     * @param list<array{category:?string, product_slug:?string}> $posts
     * @return array<string, mixed>
     */
    private static function counts(array $posts, bool $canonical): array
    {
        $cats = $prods = [];
        foreach ($posts as $p) {
            $c = $p['category'] === null || $p['category'] === '' ? '(none)' : (string) $p['category'];
            $cats[$c] = ($cats[$c] ?? 0) + 1;
            $pr = $p['product_slug'] === null || $p['product_slug'] === '' ? '(none)' : (string) $p['product_slug'];
            $prods[$pr] = ($prods[$pr] ?? 0) + 1;
        }
        ksort($cats);
        ksort($prods);
        $out = ['by_category' => $cats, 'by_product' => $prods];
        if ($canonical) {
            $mix = BlogTaxonomy::mix($posts);
            $out += ['by_group' => $mix['by_group'], 'app_led' => $mix['app_led'], 'app_led_beta' => $mix['app_led_beta'], 'topic' => $mix['topic']];
        }

        return $out;
    }
}
