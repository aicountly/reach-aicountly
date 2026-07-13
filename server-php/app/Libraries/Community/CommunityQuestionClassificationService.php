<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use App\Models\CommunityQuestionClassificationModel;

/**
 * Classifies a community question for product, category, risk, and jurisdiction.
 *
 * Phase 5 ships with a heuristic classifier. AI-based classification is wired
 * through the job queue (CP11) via CommunityClassificationJob.
 */
class CommunityQuestionClassificationService
{
    private const RISK_KEYWORDS_HIGH = [
        'gst', 'income tax', 'tax', 'compliance', 'regulation', 'penalty',
        'tds', 'itr', 'audit', 'legal', 'liability', 'lawsuit', 'court',
    ];

    private const RISK_KEYWORDS_MEDIUM = [
        'invoice', 'payment', 'refund', 'cancellation', 'subscription',
        'billing', 'account', 'data', 'privacy',
    ];

    public function __construct(
        private readonly CommunityQuestionRepository $repo = new CommunityQuestionRepository()
    ) {}

    /**
     * Classify synchronously (used for manual intake). Updates DB.
     */
    public function classifyInline(array $question): array
    {
        $classification = $this->buildClassification($question);
        $this->storeClassification((int) $question['id'], $classification);

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_CLASSIFIED, [
            'question_id'       => $question['id'],
            'risk'              => $classification['risk_classification'],
            'classified_by'     => 'heuristic',
        ]);

        return array_merge($question, $classification);
    }

    /**
     * Classify by question ID. Used from jobs.
     */
    public function classifyById(int $questionId, ?string $modelSlug = null): array
    {
        $question = $this->repo->findById($questionId);
        if ($question === null) {
            throw new \RuntimeException("Question #{$questionId} not found for classification");
        }

        $classification = $this->buildClassification($question, $modelSlug);
        $this->storeClassification($questionId, $classification);

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_CLASSIFIED, [
            'question_id' => $questionId,
            'risk'        => $classification['risk_classification'],
            'classified_by' => $modelSlug ? 'ai' : 'heuristic',
        ]);

        return $classification;
    }

    private function buildClassification(array $question, ?string $modelSlug = null): array
    {
        $combined = strtolower(($question['title'] ?? '') . ' ' . ($question['body'] ?? ''));
        $riskLevel = $this->detectRiskLevel($combined);

        $personalDataPatterns = [
            'pan card', 'aadhar', 'passport', 'date of birth', 'mobile number',
            'bank account', 'ifsc', 'credit card',
        ];
        $personalDataDetected = false;
        foreach ($personalDataPatterns as $p) {
            if (str_contains($combined, $p)) {
                $personalDataDetected = true;
                break;
            }
        }

        if ($personalDataDetected) {
            // Update the question record
            $this->repo->findById((int) $question['id']); // ensure fresh
            model(\App\Models\CommunityQuestionModel::class)->update(
                (int) $question['id'],
                ['personal_data_detected' => true, 'sensitivity_flags' => ['personal_data']]
            );
        }

        return [
            'product_classification'      => $this->detectProduct($combined, $question['product'] ?? ''),
            'category_classification'     => $this->detectCategory($combined, $question['category'] ?? ''),
            'risk_classification'         => $riskLevel,
            'jurisdiction_classification' => $question['jurisdiction'] ?? $this->detectJurisdiction($combined),
            'language_detected'           => $question['language'] ?? 'en',
            'complexity_score'            => $this->estimateComplexity($combined),
            'classified_by'               => $modelSlug ? 'ai' : 'heuristic',
            'model_slug'                  => $modelSlug,
        ];
    }

    private function storeClassification(int $questionId, array $data): void
    {
        $db = db_connect();
        $db->table('reach_community_question_classifications')->upsert([
            'question_id'                 => $questionId,
            'product_classification'      => $data['product_classification'],
            'category_classification'     => $data['category_classification'],
            'risk_classification'         => $data['risk_classification'],
            'jurisdiction_classification' => $data['jurisdiction_classification'],
            'language_detected'           => $data['language_detected'],
            'complexity_score'            => $data['complexity_score'],
            'classified_at'               => date('Y-m-d H:i:s'),
            'classified_by'               => $data['classified_by'],
            'model_slug'                  => $data['model_slug'],
        ]);
    }

    private function detectRiskLevel(string $text): string
    {
        foreach (self::RISK_KEYWORDS_HIGH as $kw) {
            if (str_contains($text, $kw)) {
                return 'high';
            }
        }
        foreach (self::RISK_KEYWORDS_MEDIUM as $kw) {
            if (str_contains($text, $kw)) {
                return 'medium';
            }
        }
        return 'low';
    }

    private function detectProduct(string $text, string $existing): string
    {
        if ($existing) {
            return $existing;
        }
        $productMap = [
            'aicountly'    => 'aicountly',
            'accounting'   => 'accounting',
            'gst'          => 'gst',
            'invoicing'    => 'invoicing',
        ];
        foreach ($productMap as $kw => $product) {
            if (str_contains($text, $kw)) {
                return $product;
            }
        }
        return 'general';
    }

    private function detectCategory(string $text, string $existing): string
    {
        if ($existing) {
            return $existing;
        }
        $categoryMap = [
            'how to'        => 'how_to',
            'troubleshoot'  => 'troubleshooting',
            'error'         => 'troubleshooting',
            'feature'       => 'product_feature',
            'compliance'    => 'compliance',
            'tax'           => 'tax',
        ];
        foreach ($categoryMap as $kw => $cat) {
            if (str_contains($text, $kw)) {
                return $cat;
            }
        }
        return 'general';
    }

    private function detectJurisdiction(string $text): string
    {
        if (str_contains($text, 'india') || str_contains($text, 'indian')) {
            return 'IN';
        }
        return '';
    }

    private function estimateComplexity(string $text): float
    {
        $wordCount = str_word_count($text);
        if ($wordCount > 200) {
            return 0.9;
        }
        if ($wordCount > 80) {
            return 0.6;
        }
        if ($wordCount > 30) {
            return 0.4;
        }
        return 0.2;
    }
}
