<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use App\Libraries\HtmlSanitizer;
use App\Libraries\UrlPolicy;

/**
 * Validates official answer content before moderation/approval.
 *
 * Runs structural, factual, grounding, and source coverage checks.
 */
class OfficialAnswerValidationService
{
    private const MIN_CONTENT_LENGTH  = 50;
    private const MAX_CONTENT_LENGTH  = 65536;
    private const MIN_EXCERPT_LENGTH  = 10;

    public function __construct(
        private readonly OfficialAnswerRepository $repo = new OfficialAnswerRepository()
    ) {}

    /**
     * Validate a specific answer version.
     *
     * @return array{passed: bool, findings: array<array{type: string, severity: string, detail: string}>}
     */
    public function validateVersion(int $answerId, int $versionNumber): array
    {
        $version = $this->repo->getVersion($answerId, $versionNumber);
        if ($version === null) {
            throw new \RuntimeException("Version {$versionNumber} not found for answer #{$answerId}");
        }

        $findings = [];

        // Structural checks
        if (strlen($version['content']) < self::MIN_CONTENT_LENGTH) {
            $findings[] = ['type' => 'content_too_short', 'severity' => 'error',
                'detail' => 'Answer body is too short (minimum ' . self::MIN_CONTENT_LENGTH . ' chars)'];
        }
        if (strlen($version['content']) > self::MAX_CONTENT_LENGTH) {
            $findings[] = ['type' => 'content_too_long', 'severity' => 'error',
                'detail' => 'Answer body exceeds maximum size'];
        }
        if (strlen($version['excerpt']) < self::MIN_EXCERPT_LENGTH) {
            $findings[] = ['type' => 'excerpt_missing', 'severity' => 'warning',
                'detail' => 'Short answer excerpt is too short'];
        }

        // Checksum integrity
        $versionService = new OfficialAnswerVersionService();
        if (!$versionService->verifyIntegrity($version)) {
            $findings[] = ['type' => 'checksum_mismatch', 'severity' => 'critical',
                'detail' => 'Version checksum does not match stored content'];
        }

        // HTML safety check
        if (!empty($version['content'])) {
            $sanitiser = new HtmlSanitizer();
            $sanitised = $sanitiser->purify($version['content']);
            if ($sanitised !== $version['content']) {
                $findings[] = ['type' => 'unsafe_html', 'severity' => 'error',
                    'detail' => 'Content contains unsafe HTML that was stripped by sanitiser'];
            }
        }

        // URL validation in content
        $urlFindings = $this->validateUrls($version['content']);
        $findings    = array_merge($findings, $urlFindings);

        // Source coverage check
        $sourceCoverageFindings = $this->checkSourceCoverage((int) $version['id'], (array) ($version['sources'] ?? []));
        $findings = array_merge($findings, $sourceCoverageFindings);

        $passed = empty(array_filter($findings, fn($f) => $f['severity'] === 'error' || $f['severity'] === 'critical'));

        // Store validation results on the version
        db_connect()->table('reach_community_answer_versions')
            ->where('id', $version['id'])
            ->update(['validation_results' => json_encode(['findings' => $findings, 'passed' => $passed])]);

        $eventName = $passed
            ? AuditLogger::COMMUNITY_ANSWER_VALIDATION_PASSED
            : AuditLogger::COMMUNITY_ANSWER_VALIDATION_FAILED;

        AuditLogger::record($eventName, [
            'answer_id'      => $answerId,
            'version_number' => $versionNumber,
            'finding_count'  => count($findings),
        ]);

        return ['passed' => $passed, 'findings' => $findings];
    }

    private function validateUrls(string $content): array
    {
        $findings  = [];
        $urlPolicy = new UrlPolicy();
        preg_match_all('/href=["\']([^"\']+)["\']/', $content, $matches);
        foreach ($matches[1] ?? [] as $url) {
            $result = $urlPolicy->validate($url);
            if (!$result->allowed) {
                $findings[] = ['type' => 'unsafe_url', 'severity' => 'error',
                    'detail' => 'Unsafe or non-HTTP URL found in content: ' . substr($url, 0, 80)];
            }
        }
        return $findings;
    }

    private function checkSourceCoverage(int $versionId, array $sources): array
    {
        $findings = [];
        if (empty($sources)) {
            $findings[] = ['type' => 'no_sources', 'severity' => 'warning',
                'detail' => 'No source references provided. Claims may be ungrounded.'];
        }

        foreach ($sources as $source) {
            if (($source['coverage_status'] ?? '') === 'missing') {
                $findings[] = ['type' => 'missing_source', 'severity' => 'error',
                    'detail' => 'Claim lacks a grounded source: ' . ($source['claim_supported'] ?? 'unknown')];
            }
            if (($source['coverage_status'] ?? '') === 'conflicted') {
                $findings[] = ['type' => 'conflicted_source', 'severity' => 'error',
                    'detail' => 'Source conflict detected for: ' . ($source['source_title'] ?? 'unknown')];
            }
        }

        return $findings;
    }
}
