<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Publishing\Seo\CanonicalUrlPolicy;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:blog-url-drift` — find posts whose live URL no longer
 * matches their slug.
 *
 * reach:blog-repair-slugs corrects reach_content_items.slug immediately, but a
 * post only moves once it is re-published. Between those two moments the slug
 * column and the public site disagree, and nothing reported that: the repair
 * command says "Repaired 0 slug(s)" on a second run because the column is
 * already right, which reads as "nothing to do" while every URL is still wrong.
 *
 * Read-only by default. --probe additionally asks the public site which URL
 * actually resolves, because that is the only authoritative answer.
 */
class ReachBlogUrlDrift extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:blog-url-drift';
    protected $description = 'Report published blogs whose live URL differs from their current slug.';
    protected $usage       = 'reach:blog-url-drift [--probe] [--record-redirects] [--id=]';
    protected $options     = [
        '--probe'            => 'HTTP-check both URLs. Makes outbound requests; changes nothing.',
        '--record-redirects' => 'Insert pending old→new redirect rows for drifted posts. WRITES.',
        '--id'               => 'Restrict to one content item id.',
    ];

    /** Deployment states whose canonical_url describes a live page. */
    private const LIVE_STATES = ['published', 'verification_pending', 'verified'];

    public function run(array $params): int
    {
        $probe  = array_key_exists('probe', $params) || CLI::getOption('probe');
        $record = array_key_exists('record-redirects', $params) || CLI::getOption('record-redirects');
        $onlyId = (int) ($params['id'] ?? CLI::getOption('id') ?? 0);

        try {
            $drifted = $this->drifted($onlyId);

            if ($probe) {
                foreach ($drifted as &$row) {
                    $row['live_url_status'] = $this->status($row['live_url']);
                    $row['slug_url_status'] = $this->status($row['slug_url']);
                    $row['verdict']         = $this->verdict($row);
                }
                unset($row);
            }

            $recorded = $record ? $this->recordRedirects($drifted) : 0;

            CLI::write(json_encode([
                'action' => 'blog-url-drift',
                'ts'     => gmdate('c'),
                'result' => [
                    'drifted'           => count($drifted),
                    'items'             => $drifted,
                    'redirects_recorded' => $recorded,
                    'note' => $drifted === []
                        ? 'Every published blog is live at the URL its current slug produces.'
                        : 'These posts are live at a URL their slug no longer matches. Re-publishing '
                            . 'moves them — but nothing in this repo serves reach_publication_redirects, '
                            . 'so the old URL will 404 unless the public site is told to redirect it. '
                            . 'Re-publish one post first and probe the old URL before doing the rest.',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('reach:blog-url-drift failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drifted(int $onlyId): array
    {
        $db = Database::connect();

        if (! SchemaGuard::hasTable($db, 'reach_publication_deployments')
            || ! SchemaGuard::hasTable($db, 'reach_content_items')) {
            return [];
        }

        $builder = $db->table('reach_content_items i')
            ->select('i.id, i.title, i.slug, i.workflow_status')
            ->where('i.content_type', 'blog')
            ->where('i.deleted_at IS NULL', null, false);

        if ($onlyId > 0) {
            $builder->where('i.id', $onlyId);
        }

        $policy  = new CanonicalUrlPolicy();
        $drifted = [];

        foreach ($builder->get()->getResultArray() as $item) {
            $deployment = $db->table('reach_publication_deployments')
                ->select('canonical_url, completed_at')
                ->where('content_item_id', (int) $item['id'])
                ->whereIn('status', self::LIVE_STATES)
                ->where('canonical_url IS NOT NULL', null, false)
                ->orderBy('completed_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();

            $liveUrl = trim((string) ($deployment['canonical_url'] ?? ''));
            $slug    = trim((string) ($item['slug'] ?? ''));
            if ($liveUrl === '' || $slug === '') {
                continue;
            }

            $slugUrl  = $policy->buildUrl('blog', $slug);
            $liveSlug = self::lastSegment($liveUrl);

            if ($liveSlug === strtolower($slug)) {
                continue;
            }

            $drifted[] = [
                'content_item_id' => (int) $item['id'],
                'title'           => $item['title'],
                'workflow_status' => $item['workflow_status'],
                'live_url'        => $liveUrl,
                'live_slug'       => $liveSlug,
                'slug_url'        => $slugUrl,
                'current_slug'    => $slug,
                'published_at'    => $deployment['completed_at'] ?? null,
            ];
        }

        return $drifted;
    }

    /**
     * Insert a pending redirect for each drifted post. Idempotent: an existing
     * unapplied row for the same pair is left alone rather than duplicated.
     *
     * @param list<array<string, mixed>> $drifted
     */
    private function recordRedirects(array $drifted): int
    {
        $db = Database::connect();
        if (! SchemaGuard::hasTable($db, 'reach_publication_redirects')) {
            CLI::error('reach_publication_redirects is not migrated; nothing recorded.');

            return 0;
        }

        $recorded = 0;
        foreach ($drifted as $row) {
            $exists = $db->table('reach_publication_redirects')
                ->where('content_item_id', $row['content_item_id'])
                ->where('from_slug', $row['live_slug'])
                ->where('to_slug', $row['current_slug'])
                ->whereIn('status', ['pending', 'active'])
                ->countAllResults();

            if ($exists > 0) {
                continue;
            }

            $db->table('reach_publication_redirects')->insert([
                'content_item_id' => $row['content_item_id'],
                'from_slug'       => $row['live_slug'],
                'to_slug'         => $row['current_slug'],
                'to_url'          => $row['slug_url'],
                'redirect_type'   => 301,
                'reason'          => 'slug repaired after publication',
                'status'          => 'pending',
            ]);
            $recorded++;
        }

        return $recorded;
    }

    /**
     * Final HTTP status after following redirects, or null when unreachable.
     */
    private function status(string $url): ?int
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status > 0 ? $status : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function verdict(array $row): string
    {
        $live = $row['live_url_status'];
        $slug = $row['slug_url_status'];

        return match (true) {
            $live === 200 && $slug === 200 => 'both resolve — the post is reachable at two URLs; '
                . 'confirm which one is canonical before re-publishing',
            $live === 200 && $slug !== 200 => 're-publishing moves a live URL to one that does not exist yet; '
                . 'the old URL will 404 without a redirect',
            $live !== 200 && $slug === 200 => 'already moved — the deployment record is stale, not the site',
            default                        => 'neither URL resolves; this post is not reachable at all',
        };
    }

    private static function lastSegment(string $url): string
    {
        $path     = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $path     = rtrim($path, '/');
        $position = strrpos($path, '/');

        return $position === false ? '' : strtolower(substr($path, $position + 1));
    }
}
