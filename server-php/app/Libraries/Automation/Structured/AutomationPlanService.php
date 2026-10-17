<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Structured;

use App\Libraries\Blog\ContentBaseService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the release-note and workflow-guide routines should work on next.
 *
 *   GET v1/automation/release-plan   per product: repository, last published
 *                                    version and the git ref it covered (the
 *                                    routine diffs from there), notes in flight,
 *                                    the versions Reach will accept next.
 *   GET v1/automation/workflow-plan  per launch app: the guides published now
 *                                    (hand-written on the site, or by Reach)
 *                                    with their last-updated dates.
 */
final class AutomationPlanService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function releasePlan(): array
    {
        $rows = $this->db->table('reach_structured_content_submissions')
            ->where('content_type', 'release_note')
            ->whereIn('status', ['pending_vetting', 'approved', 'failed', 'published'])
            ->orderBy('version_sort', 'DESC')
            ->get()->getResultArray();

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[$row['product_slug']][] = $row;
        }

        $submissions = new StructuredSubmissionService(null, null, $this->db);
        $products    = [];
        foreach (ProductCatalog::all() as $p) {
            $mine      = $byProduct[$p['slug']] ?? [];
            $published = null;
            $inFlight  = [];
            foreach ($mine as $row) {
                if ($row['status'] === 'published') {
                    $published ??= $row;
                } else {
                    $inFlight[] = [
                        'id'        => (int) $row['id'],
                        'version'   => $row['version'],
                        'status'    => $row['status'],
                        'last_error'=> $row['last_error'],
                    ];
                }
            }

            $last = null;
            if ($published !== null) {
                $source = json_decode((string) ($published['source_json'] ?? ''), true) ?: [];
                $last   = [
                    'version'       => $published['version'],
                    'slug'          => $published['public_slug'],
                    'date_iso'      => $this->payloadField($published, 'date_iso'),
                    'public_url'    => $published['public_url'],
                    'published_at'  => $published['published_at'],
                    'source'        => 'reach',
                    'from_ref'      => $source['from_ref'] ?? null,
                    'to_ref'        => $source['to_ref'] ?? null,
                    'commits_count' => $source['commits_count'] ?? null,
                ];
            }
            $hand = $p['hand_release'];
            if ($hand !== null) {
                $handVersion = ReleaseVersion::parse($hand['version']);
                $lastVersion = $last !== null ? ReleaseVersion::parse((string) $last['version']) : null;
                if ($handVersion !== null && ($lastVersion === null || $handVersion->compare($lastVersion) > 0)) {
                    $last = [
                        'version'      => $hand['version'],
                        'slug'         => $hand['slug'],
                        'date_iso'     => $hand['date_iso'],
                        'public_url'   => null,
                        'published_at' => null,
                        'source'       => 'site',
                        'from_ref'     => null,
                        'to_ref'       => null,
                        'commits_count'=> null,
                    ];
                }
            }

            $floor = $submissions->releaseFloor($p['slug']);
            $products[] = [
                'product_slug'        => $p['slug'],
                'name'                => $p['name'],
                'launch'              => $p['launch'],
                'repo'                => $p['repo'],
                'repo_full_name'      => $p['repo_full_name'],
                'last_published'      => $last,
                // Diff from to_ref when Reach published the last note; otherwise
                // from the hand-written release's date (no ref is recorded for those).
                'diff_from'           => [
                    'ref'   => $last['to_ref'] ?? null,
                    'since' => ($last !== null && ($last['to_ref'] ?? null) === null) ? $last['date_iso'] : null,
                ],
                'in_flight'           => $inFlight,
                'next_versions'       => $floor !== null ? $floor['version']->nextSteps() : ['first' => '1.0'],
                'release_slug_prefix' => ProductCatalog::releaseSlugPrefix($p['slug']),
            ];
        }

        return [
            'generated_at'   => gmdate('c'),
            'versioning'     => 'MAJOR.MINOR[.PATCH] per product. New features → next minor (3.4 → 3.5); fixes or polish only → next patch (3.4 → 3.4.1); a redesign → next major (3.4 → 4.0). Reach accepts only one of next_versions. Skip a product that has notes in_flight.',
            'enabled'        => StructuredSubmissionService::enabled('release_note'),
            'products'       => $products,
        ];
    }

    /** @return array<string, mixed> */
    public function workflowPlan(): array
    {
        $site = $this->siteGuides();

        $rows = $this->db->table('reach_structured_content_submissions')
            ->where('content_type', 'workflow_guide')
            ->whereIn('status', ['pending_vetting', 'approved', 'failed', 'published'])
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();
        $published = [];
        $inFlight  = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'published') {
                $published[$row['product_slug']][$row['public_slug']] ??= $row;
            } else {
                $inFlight[$row['product_slug']][] = ['id' => (int) $row['id'], 'slug' => $row['public_slug'], 'status' => $row['status'], 'last_error' => $row['last_error']];
            }
        }

        $products = [];
        foreach (ProductCatalog::launch() as $p) {
            $guides = [];
            foreach ((array) ($site['products'][$p['slug']]['guides'] ?? []) as $g) {
                $slug = (string) ($g['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $guides[$slug] = [
                    'slug'         => $slug,
                    'title'        => (string) ($g['title'] ?? $slug),
                    'source'       => 'site',
                    'last_updated' => $site['snapshot_date'] ?? null,
                    'public_url'   => ProductCatalog::guideUrl($p['slug'], $slug),
                ];
            }
            foreach ($published[$p['slug']] ?? [] as $slug => $row) {
                $guides[$slug] = [
                    'slug'         => $slug,
                    'title'        => $row['title'],
                    'source'       => 'reach',
                    'last_updated' => $row['published_at'] ? substr((string) $row['published_at'], 0, 10) : null,
                    'public_url'   => $row['public_url'] ?: ProductCatalog::guideUrl($p['slug'], $slug),
                    // The published text, so the routine can compare its steps with the product today.
                    'content'      => json_decode((string) ($row['final_json'] ?? $row['payload_json']), true),
                ];
            }

            $products[] = [
                'product_slug'   => $p['slug'],
                'name'           => $p['name'],
                'repo'           => $p['repo'],
                'repo_full_name' => $p['repo_full_name'],
                'guide_hub_url'  => ProductCatalog::siteBase() . '/guides/' . $p['hub'],
                'guides'         => array_values($guides),
                'in_flight'      => $inFlight[$p['slug']] ?? [],
            ];
        }

        return [
            'generated_at'       => gmdate('c'),
            'site_snapshot_date' => $site['snapshot_date'] ?? null,
            'note'               => 'source "site" = written by hand in aicountly-com includes/guides-content (last_updated is the snapshot date); posting a guide with the same product_slug + slug replaces it on the site. source "reach" = published by this routine.',
            'enabled'            => StructuredSubmissionService::enabled('workflow_guide'),
            'products'           => $products,
        ];
    }

    /** @return array<string, mixed> */
    private function siteGuides(): array
    {
        $path = (new ContentBaseService($this->db))->basePath() . '/workflow-guides/site-guides.json';
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function payloadField(array $row, string $field): ?string
    {
        $payload = json_decode((string) ($row['final_json'] ?? $row['payload_json'] ?? ''), true);

        return is_array($payload) && isset($payload[$field]) ? (string) $payload[$field] : null;
    }
}
