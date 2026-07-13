<?php

namespace App\Libraries\Publishing\Seo;

use App\Libraries\AuditLogger;

/**
 * Phase 4 — Verifies that a published URL appears in the public-site sitemap.
 *
 * Fetches the public sitemap XML and checks for the canonical URL.
 * Result is stored in reach_publication_verifications.
 * Never makes authenticated requests (sitemap is public).
 */
class SitemapVerificationService
{
    private const SITEMAP_PATH = '/sitemap.xml';
    private const HTTP_TIMEOUT = 10;

    /**
     * Check whether a canonical URL is included in the public sitemap.
     *
     * @return array{included: bool, sitemap_url: string, error: ?string}
     */
    public function verify(string $canonicalUrl): array
    {
        if (empty($canonicalUrl)) {
            return ['included' => false, 'sitemap_url' => '', 'error' => 'No canonical URL provided'];
        }

        $parsed     = parse_url($canonicalUrl);
        $sitemapUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . self::SITEMAP_PATH;

        $ch = curl_init($sitemapUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Reach-SitemapVerifier/1.0',
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch) ?: null;
        curl_close($ch);

        if ($error || $body === false || $httpCode !== 200) {
            return [
                'included'    => false,
                'sitemap_url' => $sitemapUrl,
                'error'       => $error ?? "HTTP {$httpCode}",
            ];
        }

        $included = str_contains($body, htmlspecialchars($canonicalUrl, ENT_XML1))
                 || str_contains($body, $canonicalUrl);

        return [
            'included'    => $included,
            'sitemap_url' => $sitemapUrl,
            'error'       => null,
        ];
    }

    /**
     * Run sitemap verification for a deployment and persist results.
     */
    public function verifyDeployment(int $deploymentId, string $canonicalUrl): array
    {
        $result = $this->verify($canonicalUrl);

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->table('reach_publication_verifications')->insert([
            'deployment_id'     => $deploymentId,
            'verification_type' => 'sitemap',
            'expected_value'    => 'included',
            'actual_value'      => $result['included'] ? 'included' : 'excluded',
            'status'            => $result['included'] ? 'passed' : 'failed',
            'checked_at'        => $now,
            'details_json'      => json_encode(['sitemap_url' => $result['sitemap_url'], 'error' => $result['error']]),
            'created_at'        => $now,
        ]);

        AuditLogger::log('publishing.sitemap_verified', [
            'deployment_id' => $deploymentId,
            'canonical_url' => $canonicalUrl,
            'included'      => $result['included'],
        ]);

        return $result;
    }
}
