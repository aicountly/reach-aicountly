<?php

namespace App\Libraries\Publishing\KnowledgeBase;

/**
 * Phase 4 — Knowledge-Base Publication Readiness.
 *
 * Enforces all prerequisite gates before a KB article may be published.
 * AI cannot approve content or bypass this service.
 */
class KnowledgeBaseReadinessService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /** @return array{ready: bool, status: string, blocking: array<int,string>, warnings: array<int,string>} */
    public function evaluate(int $contentItemId): array
    {
        $blocking = [];
        $warnings = [];

        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();

        if (!$item) {
            return $this->result(['Content item not found'], []);
        }

        if ($item['content_type'] !== 'knowledge_base') {
            $blocking[] = 'Content type must be knowledge_base (found: ' . $item['content_type'] . ')';
        }

        if ($item['approval_status'] !== 'approved') {
            $blocking[] = 'Content must be human-approved (current: ' . $item['approval_status'] . ')';
        }

        $version = $this->db->table('reach_content_versions')
            ->where('content_item_id', $contentItemId)
            ->orderBy('version_number', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        if (!$version) {
            $blocking[] = 'No content version found';
        }

        if ($version && $version['status'] === 'superseded') {
            $blocking[] = 'Current version is superseded';
        }

        if (in_array($item['validation_status'], ['blocking', 'failed'], true)) {
            $blocking[] = 'Unresolved critical validation findings';
        }

        $criticalCount = $this->db->table('reach_content_validations')
            ->where('content_item_id', $contentItemId)
            ->where('severity', 'critical')
            ->whereIn('status', ['open', 'acknowledged'])
            ->countAllResults();

        if ($criticalCount > 0) {
            $blocking[] = "Has {$criticalCount} unresolved critical validation finding(s)";
        }

        $kbProfile = $this->db->table('reach_kb_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        if (!$kbProfile) {
            $blocking[] = 'Knowledge-base publication profile is missing';
        }

        // Product mapping required
        if ($kbProfile && empty($kbProfile['product_id'])) {
            $blocking[] = 'Product mapping is required for knowledge-base articles';
        }

        // Article type must be valid
        if ($kbProfile) {
            $validTypes = ['concept','how_to','troubleshooting','faq','release_guide','configuration','integration','reference','best_practice'];
            if (!in_array($kbProfile['article_type'], $validTypes, true)) {
                $blocking[] = 'Invalid article type: ' . $kbProfile['article_type'];
            }
        }

        // How-to must have steps
        if ($kbProfile && $kbProfile['article_type'] === 'how_to') {
            $steps = json_decode($kbProfile['steps_json'] ?? '[]', true) ?? [];
            if (empty($steps)) {
                $blocking[] = 'How-to articles must have steps defined';
            } else {
                $validator = new KnowledgeBaseStructureValidator();
                $stepErrors = $validator->validateSteps($steps);
                foreach ($stepErrors as $err) {
                    $blocking[] = $err;
                }
            }
        }

        // Version applicability must be declared
        if ($kbProfile) {
            $versions = json_decode($kbProfile['applicable_versions_json'] ?? '{}', true) ?? [];
            if (empty($versions) || empty($versions['type'])) {
                $blocking[] = 'Product version applicability must be declared';
            }
        }

        // SEO profile
        $seoProfile = $this->db->table('reach_content_seo_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        if (!$seoProfile) {
            $blocking[] = 'SEO profile is missing';
        } elseif ($seoProfile['seo_status'] === 'blocked') {
            $blocking[] = 'SEO profile is blocked';
        } elseif ($seoProfile['seo_status'] !== 'ready') {
            $warnings[] = 'SEO profile is not ready (current: ' . $seoProfile['seo_status'] . ')';
        }

        // Workflow
        if (!in_array($item['workflow_status'], ['approved', 'published', 'scheduled'], true)) {
            $blocking[] = 'Content workflow status must be approved, scheduled, or published';
        }

        // Related articles validity check
        if ($kbProfile) {
            $related = json_decode($kbProfile['related_articles_json'] ?? '[]', true) ?? [];
            foreach ($related as $relArticle) {
                if (empty($relArticle['slug'])) {
                    $warnings[] = 'A related article is missing its slug';
                    break;
                }
            }
        }

        $ready  = empty($blocking);
        $status = $ready ? (empty($warnings) ? 'ready' : 'warning') : 'blocked';

        return $this->result($blocking, $warnings, $status);
    }

    /** @return array{ready: bool, status: string, blocking: array<int,string>, warnings: array<int,string>} */
    private function result(array $blocking, array $warnings, string $status = 'blocked'): array
    {
        return [
            'ready'    => empty($blocking),
            'status'   => $status,
            'blocking' => $blocking,
            'warnings' => $warnings,
        ];
    }
}
