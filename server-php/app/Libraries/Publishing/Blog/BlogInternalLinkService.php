<?php

namespace App\Libraries\Publishing\Blog;

/**
 * Phase 4 — Manages and validates internal links for blog content.
 */
class BlogInternalLinkService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Add an internal link to a content item.
     */
    public function addLink(
        int $sourceContentItemId,
        ?int $sourceVersionId,
        string $anchorText,
        string $targetPublicUrl,
        ?int $targetContentItemId = null,
        string $linkReason = ''
    ): int {
        $this->db->table('reach_content_internal_links')->insert([
            'source_content_item_id'    => $sourceContentItemId,
            'source_content_version_id' => $sourceVersionId,
            'target_type'               => $targetContentItemId ? 'internal' : 'external',
            'target_content_item_id'    => $targetContentItemId,
            'target_public_url'         => $targetPublicUrl,
            'anchor_text'               => $anchorText,
            'link_reason'               => $linkReason,
            'status'                    => 'active',
            'validation_status'         => 'pending',
            'created_at'                => date('Y-m-d H:i:s'),
            'updated_at'                => date('Y-m-d H:i:s'),
        ]);

        return $this->db->insertID();
    }

    /**
     * Validate all internal links for a content item.
     * Returns count of broken links found.
     */
    public function validateLinks(int $contentItemId): int
    {
        $links = $this->db->table('reach_content_internal_links')
            ->where('source_content_item_id', $contentItemId)
            ->where('status', 'active')
            ->get()->getResultArray();

        $broken = 0;

        foreach ($links as $link) {
            $url = $link['target_public_url'];
            $isValid = $this->isUrlReachable($url);
            $status = $isValid ? 'valid' : 'invalid';

            if (!$isValid) {
                $broken++;
            }

            $this->db->table('reach_content_internal_links')
                ->where('id', $link['id'])
                ->update([
                    'validation_status' => $status,
                    'last_checked_at'   => date('Y-m-d H:i:s'),
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]);
        }

        return $broken;
    }

    /**
     * Check URL reachability using SSRF-safe HEAD request.
     * Uses cURL with strict timeout and no redirect chasing.
     */
    private function isUrlReachable(string $url): bool
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 400;
    }
}
