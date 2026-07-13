<?php

namespace App\Libraries\Publishing\Blog;

use App\Libraries\AuditLogger;

/**
 * Phase 4 — Blog Publication Readiness.
 *
 * Enforces all prerequisite gates before a blog content item may be
 * published to the public website. AI can never approve content or
 * bypass this service.
 */
class BlogReadinessService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Evaluate all readiness gates for the given content item.
     *
     * @return array{ready: bool, status: string, blocking: array<int,string>, warnings: array<int,string>}
     */
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

        // Gate 1: content type
        if ($item['content_type'] !== 'blog') {
            $blocking[] = 'Content type must be blog (found: ' . $item['content_type'] . ')';
        }

        // Gate 2: human approval — AI may never approve
        if ($item['approval_status'] !== 'approved') {
            $blocking[] = 'Content must be human-approved (current: ' . $item['approval_status'] . ')';
        }

        // Gate 3: current version exists
        $version = $this->db->table('reach_content_versions')
            ->where('content_item_id', $contentItemId)
            ->orderBy('version_number', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        if (!$version) {
            $blocking[] = 'No content version found';
        }

        // Gate 4: version not superseded
        if ($version && $version['status'] === 'superseded') {
            $blocking[] = 'Current version is superseded; a new version must be approved';
        }

        // Gate 5: validation status
        if ($item['validation_status'] === 'blocking' || $item['validation_status'] === 'failed') {
            $blocking[] = 'Unresolved critical validation findings';
        }

        // Gate 6: critical findings check
        $criticalCount = $this->db->table('reach_content_validations')
            ->where('content_item_id', $contentItemId)
            ->where('severity', 'critical')
            ->whereIn('status', ['open', 'acknowledged'])
            ->countAllResults();

        if ($criticalCount > 0) {
            $blocking[] = "Has {$criticalCount} unresolved critical validation finding(s)";
        }

        // Gate 7: SEO profile
        $seoProfile = $this->db->table('reach_content_seo_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        if (!$seoProfile) {
            $blocking[] = 'SEO profile is missing';
        } elseif ($seoProfile['seo_status'] === 'blocked') {
            $blocking[] = 'SEO profile is blocked';
        } elseif ($seoProfile['seo_status'] !== 'ready') {
            $blocking[] = 'SEO profile is not ready (current: ' . $seoProfile['seo_status'] . ')';
        }

        // Gate 8: slug
        if ($seoProfile && empty($seoProfile['slug'])) {
            $blocking[] = 'SEO slug is not defined';
        }

        // Gate 9: canonical preference defined
        if ($seoProfile && empty($seoProfile['canonical_preference'])) {
            $blocking[] = 'Canonical preference is not defined';
        }

        // Gate 10: blog publication profile
        $profile = $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        if (!$profile) {
            $blocking[] = 'Blog publication profile is missing';
        }

        // Gate 11: author defined
        if ($profile && empty($profile['author_reference'])) {
            $blocking[] = 'Author reference is not defined';
        }

        // Gate 12: featured image alt text
        $missingAlt = $this->db->table('reach_content_media_requirements')
            ->where('content_item_id', $contentItemId)
            ->where('media_type', 'featured_image')
            ->whereIn('status', ['required', 'provided'])
            ->where('alt_text IS NULL OR alt_text = \'\'')
            ->countAllResults();

        if ($missingAlt > 0) {
            $blocking[] = 'Featured image alt text is missing';
        }

        // Gate 13: structured data validates
        $invalidSd = $this->db->table('reach_content_structured_data')
            ->where('content_item_id', $contentItemId)
            ->where('validation_status', 'invalid')
            ->countAllResults();

        if ($invalidSd > 0) {
            $warnings[] = 'Structured data has validation errors';
        }

        $blockedSd = $this->db->table('reach_content_structured_data')
            ->where('content_item_id', $contentItemId)
            ->where('validation_status', 'blocked')
            ->countAllResults();

        if ($blockedSd > 0) {
            $blocking[] = 'Structured data is blocked';
        }

        // Gate 14: AEO profile (warning only if missing)
        $aeoProfile = $this->db->table('reach_content_aeo_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        if ($aeoProfile && $aeoProfile['aeo_status'] === 'blocked') {
            $blocking[] = 'AEO profile is blocked';
        } elseif (!$aeoProfile) {
            $warnings[] = 'AEO profile is missing (recommended)';
        }

        // Gate 15: workflow status
        if (!in_array($item['workflow_status'], ['approved', 'published', 'scheduled'], true)) {
            $blocking[] = 'Content workflow status must be approved, scheduled, or published (current: ' . $item['workflow_status'] . ')';
        }

        $ready = empty($blocking);
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
