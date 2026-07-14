<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityModerationFindingType;
use App\Libraries\AuditLogger;
use App\Libraries\HtmlSanitizer;
use App\Models\CommunityModerationFindingModel;

/**
 * Moderates official answer versions for safety, compliance, and quality.
 *
 * Findings may auto-block publication, require review, or be overridable.
 * Override requires community_answer.override_validation permission + reason.
 */
class OfficialAnswerModerationService
{
    private const PROMPT_INJECTION_PATTERNS = [
        'ignore previous instructions',
        'disregard all prior',
        'you are now',
        'pretend you are',
        'act as if',
        'forget your instructions',
        'system: override',
        'assistant: ignore',
        '<!-- inject',
        '<script',
    ];

    public function __construct(
        private readonly OfficialAnswerRepository    $answerRepo  = new OfficialAnswerRepository(),
        private readonly CommunityModerationFindingModel $findingModel = new CommunityModerationFindingModel()
    ) {}

    /**
     * Run moderation on an answer version.
     *
     * @return array{blocked: bool, requires_review: bool, findings: array}
     */
    public function moderate(int $answerId, int $versionNumber, ?int $actorId = null): array
    {
        $version = $this->answerRepo->getVersion($answerId, $versionNumber);
        if ($version === null) {
            throw new \RuntimeException("Version {$versionNumber} not found for answer #{$answerId}");
        }

        $findings = [];
        $blocked  = false;
        $requiresReview = false;

        // Prompt injection detection
        $injectionFindings = $this->detectPromptInjection($version['content']);
        if (!empty($injectionFindings)) {
            $blocked = true;
            foreach ($injectionFindings as $f) {
                $findings[] = $f;
                $this->storeFinding((int) $version['id'], $f);
            }
        }

        // Malicious HTML / XSS detection
        $htmlFindings = $this->detectMaliciousHtml($version['content']);
        if (!empty($htmlFindings)) {
            $blocked = true;
            foreach ($htmlFindings as $f) {
                $findings[] = $f;
                $this->storeFinding((int) $version['id'], $f);
            }
        }

        // PII detection in content
        $piiFindings = $this->detectPii($version['content']);
        if (!empty($piiFindings)) {
            $blocked = true;
            foreach ($piiFindings as $f) {
                $findings[] = $f;
                $this->storeFinding((int) $version['id'], $f);
            }
        }

        // Legal/compliance risk keywords
        $legalFindings = $this->detectLegalRisk($version['content']);
        if (!empty($legalFindings)) {
            $requiresReview = true;
            foreach ($legalFindings as $f) {
                $findings[] = $f;
                $this->storeFinding((int) $version['id'], $f);
            }
        }

        // Unsafe external links
        $linkFindings = $this->detectUnsafeLinks($version['content']);
        if (!empty($linkFindings)) {
            $blocked = true;
            foreach ($linkFindings as $f) {
                $findings[] = $f;
                $this->storeFinding((int) $version['id'], $f);
            }
        }

        // Update moderation decision on version
        $decision = $blocked ? 'blocked' : ($requiresReview ? 'flagged' : 'clean');
        db_connect()->table('reach_community_answer_versions')
            ->where('id', $version['id'])
            ->update(['moderation_decision' => $decision]);

        if (!empty($findings)) {
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_MODERATION_FINDING, [
                'answer_id'      => $answerId,
                'version_number' => $versionNumber,
                'blocked'        => $blocked,
                'finding_count'  => count($findings),
            ], $actorId);
        }

        return [
            'blocked'        => $blocked,
            'requires_review' => $requiresReview,
            'decision'       => $decision,
            'findings'       => $findings,
        ];
    }

    /**
     * Override a moderation finding. Requires community_answer.override_validation permission.
     */
    public function overrideFinding(int $findingId, string $reason, int $actorId): void
    {
        if (empty(trim($reason))) {
            throw new \InvalidArgumentException('Override reason is required.');
        }

        $this->findingModel->update($findingId, [
            'status'          => 'overridden',
            'override_by'     => $actorId,
            'override_reason' => $reason,
            'override_at'     => date('Y-m-d H:i:s'),
        ]);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_MODERATION_OVERRIDE, [
            'finding_id' => $findingId,
            'actor_id'   => $actorId,
        ], $actorId);
    }

    private function detectPromptInjection(string $content): array
    {
        $lower    = strtolower($content);
        $findings = [];
        foreach (self::PROMPT_INJECTION_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                $findings[] = [
                    'type'     => CommunityModerationFindingType::PromptInjection->value,
                    'severity' => 'critical',
                    'detail'   => 'Potential prompt injection pattern detected in answer content',
                ];
                break;
            }
        }
        return $findings;
    }

    private function detectMaliciousHtml(string $content): array
    {
        $findings  = [];
        $sanitiser = new HtmlSanitizer();
        $sanitised = $sanitiser->purify($content);
        if ($sanitised !== $content) {
            $findings[] = [
                'type'     => CommunityModerationFindingType::MaliciousHtml->value,
                'severity' => 'critical',
                'detail'   => 'HTML sanitiser removed unsafe content from answer',
            ];
        }
        return $findings;
    }

    private function detectPii(string $content): array
    {
        $findings = [];
        $patterns = [
            '/\b[A-Z]{5}\d{4}[A-Z]\b/' => 'PAN card number pattern',
            '/\b\d{12}\b/'              => 'Potential 12-digit ID (Aadhaar-like)',
            '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/' => 'Potential credit card pattern',
        ];
        foreach ($patterns as $pattern => $desc) {
            if (preg_match($pattern, $content)) {
                $findings[] = [
                    'type'     => CommunityModerationFindingType::PersonalData->value,
                    'severity' => 'critical',
                    'detail'   => $desc . ' detected in content',
                ];
            }
        }
        return $findings;
    }

    private function detectLegalRisk(string $content): array
    {
        $lower    = strtolower($content);
        $findings = [];
        $legalKeywords = [
            'you are legally required',
            'the law states',
            'as per section',
            'this is illegal',
            'you must file',
        ];
        foreach ($legalKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                $findings[] = [
                    'type'     => CommunityModerationFindingType::LegalRisk->value,
                    'severity' => 'warning',
                    'detail'   => 'Legal assertion detected — review required: "' . $kw . '"',
                ];
            }
        }
        return $findings;
    }

    private function detectUnsafeLinks(string $content): array
    {
        $findings = [];
        preg_match_all('/href=["\']([^"\']+)["\']/', $content, $matches);
        foreach ($matches[1] ?? [] as $url) {
            $scheme = strtolower(explode(':', $url)[0]);
            if (!in_array($scheme, ['https', 'http', 'mailto'], true)) {
                $findings[] = [
                    'type'     => CommunityModerationFindingType::UnsafeLinks->value,
                    'severity' => 'critical',
                    'detail'   => 'Unsafe URL scheme detected: ' . substr($url, 0, 80),
                ];
            }
        }
        return $findings;
    }

    private function storeFinding(int $versionId, array $finding): void
    {
        $this->findingModel->insert([
            'answer_version_id' => $versionId,
            'finding_type'      => $finding['type'],
            'severity'          => $finding['severity'],
            'details'           => json_encode(['detail' => $finding['detail']]),
            'auto_action'       => $finding['severity'] === 'critical' ? 'blocked' : 'flagged',
            'status'            => 'open',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
    }
}
