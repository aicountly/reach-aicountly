<?php

namespace App\Libraries\Publishing\Seo;

use App\Libraries\AuditLogger;

/**
 * Phase 4 â€” Indexing readiness: evaluates whether content is ready
 * to be included in the sitemap and indexed by search engines.
 *
 * Fires the indexing_ready audit event when all criteria pass.
 * Does NOT submit to any search engine (per Phase 4 prohibitions).
 */
class IndexingReadinessService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Evaluate indexing readiness for a deployment.
     *
     * @return array{ready: bool, checks: array<string,array>}
     */
    public function evaluate(int $deploymentId): array
    {
        $deployment = $this->db->table('reach_publication_deployments d')
            ->select('d.*, ci.title, sp.robots_directive, sp.slug, sp.canonical_preference')
            ->join('reach_content_items ci', 'ci.id = d.content_item_id', 'left')
            ->join('reach_content_seo_profiles sp', 'sp.content_item_id = d.content_item_id', 'left')
            ->where('d.id', $deploymentId)
            ->get()->getRowArray();

        if (!$deployment) {
            return ['ready' => false, 'checks' => ['deployment' => ['passed' => false, 'message' => 'Deployment not found']]];
        }

        $checks = [];

        // Must be published/verified
        $checks['published'] = [
            'passed'  => in_array($deployment['status'], ['published', 'verified'], true),
            'message' => 'Deployment status: ' . $deployment['status'],
        ];

        // Must have canonical URL
        $checks['canonical_url'] = [
            'passed'  => !empty($deployment['canonical_url']),
            'message' => $deployment['canonical_url'] ? 'Has canonical URL' : 'Missing canonical URL',
        ];

        // robots_directive must not be noindex
        $robots = $deployment['robots_directive'] ?? 'index,follow';
        $checks['robots_indexable'] = [
            'passed'  => !str_contains($robots, 'noindex'),
            'message' => "Robots: {$robots}",
        ];

        // Canonical preference must not be noindex or redirect
        $canonicalPref = $deployment['canonical_preference'] ?? 'self_canonical';
        $checks['canonical_preference'] = [
            'passed'  => !in_array($canonicalPref, ['noindex', 'redirect_to_existing'], true),
            'message' => "Canonical preference: {$canonicalPref}",
        ];

        // Sitemap verification
        if (!empty($deployment['canonical_url'])) {
            $sitemap = (new SitemapVerificationService())->verify($deployment['canonical_url']);
            $checks['sitemap_included'] = [
                'passed'  => $sitemap['included'],
                'message' => $sitemap['included'] ? 'URL found in sitemap' : 'URL not in sitemap',
            ];
        }

        $ready = !in_array(false, array_column($checks, 'passed'), true);

        if ($ready) {
            AuditLogger::record('publishing.indexing_ready', [
                'deployment_id' => $deploymentId,
                'canonical_url' => $deployment['canonical_url'],
            ]);
        }

        return ['ready' => $ready, 'checks' => $checks];
    }
}

