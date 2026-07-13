<?php

namespace App\Libraries\Publishing\Seo;

use App\Libraries\Publishing\Blog\BlogReadinessService;
use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseReadinessService;

/**
 * Phase 4 — Aggregates all readiness signals for a content item.
 *
 * Runs SEO, AEO, and type-specific readiness checks.
 * Returns a single unified result used by the publication controller.
 */
class PublicationReadinessAggregator
{
    private SeoReadinessService $seo;
    private AeoReadinessService $aeo;

    public function __construct()
    {
        $this->seo = new SeoReadinessService();
        $this->aeo = new AeoReadinessService();
    }

    /**
     * Evaluate all readiness checks and return a unified result.
     *
     * @return array{
     *   ready: bool,
     *   status: string,
     *   content_type: string,
     *   domain_check: array,
     *   seo_check: array,
     *   aeo_check: array,
     *   blocking: array<int,string>,
     *   warnings: array<int,string>
     * }
     */
    public function evaluate(int $contentItemId, string $contentType): array
    {
        $domainCheck = $this->evaluateDomain($contentItemId, $contentType);
        $seoCheck    = $this->seo->evaluate($contentItemId);
        $aeoCheck    = $this->aeo->evaluate($contentItemId);

        $blocking = $domainCheck['blocking'] ?? [];
        $warnings = $domainCheck['warnings'] ?? [];

        // Promote SEO findings
        if ($seoCheck['status'] === 'blocked') {
            foreach ($seoCheck['findings'] as $f) {
                if (in_array($f['level'] ?? '', ['error'], true)) {
                    $blocking[] = '[SEO] ' . $f['message'];
                } else {
                    $warnings[] = '[SEO] ' . $f['message'];
                }
            }
        } elseif ($seoCheck['status'] === 'warning') {
            foreach ($seoCheck['findings'] as $f) {
                $warnings[] = '[SEO] ' . $f['message'];
            }
        }

        // AEO warnings (never blocking by themselves)
        if (!empty($aeoCheck['findings'])) {
            foreach ($aeoCheck['findings'] as $f) {
                if (($f['level'] ?? '') === 'error') {
                    $blocking[] = '[AEO] ' . $f['message'];
                } else {
                    $warnings[] = '[AEO] ' . $f['message'];
                }
            }
        }

        $ready  = empty($blocking);
        $status = $ready ? (empty($warnings) ? 'ready' : 'warning') : 'blocked';

        return [
            'ready'        => $ready,
            'status'       => $status,
            'content_type' => $contentType,
            'domain_check' => $domainCheck,
            'seo_check'    => $seoCheck,
            'aeo_check'    => $aeoCheck,
            'blocking'     => $blocking,
            'warnings'     => $warnings,
        ];
    }

    private function evaluateDomain(int $contentItemId, string $contentType): array
    {
        return match ($contentType) {
            'blog'          => (new BlogReadinessService())->evaluate($contentItemId),
            'knowledge_base'=> (new KnowledgeBaseReadinessService())->evaluate($contentItemId),
            default         => ['ready' => false, 'status' => 'blocked', 'blocking' => ["Unknown content type: {$contentType}"], 'warnings' => []],
        };
    }
}
