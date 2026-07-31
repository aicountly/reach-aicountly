<?php

declare(strict_types=1);

namespace App\Libraries\Blog\Verification;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiProviderInterface;

/**
 * Extracts material factual claims from article HTML or markdown.
 *
 * Uses an AI provider when configured; falls back to deterministic heuristics.
 */
class ClaimExtractor
{
    private ?AiProviderInterface $provider;

    public function __construct(?AiProviderInterface $provider = null)
    {
        $this->provider = $provider;
    }

    /**
     * Extract claims from HTML or markdown content.
     *
     * @return list<array{text: string, claim_type: string, risk: string}>
     */
    public function extract(string $htmlOrMarkdown): array
    {
        $text = $this->normalizeText($htmlOrMarkdown);

        if ($this->provider !== null && $this->provider->isConfigured()) {
            try {
                return $this->extractWithAi($text);
            } catch (\Throwable) {
                // Fall through to heuristic extraction on provider failure.
            }
        }

        return $this->extractHeuristic($text);
    }

    /**
     * Deterministic claim extraction for offline use and fallback.
     *
     * @return list<array{text: string, claim_type: string, risk: string}>
     */
    public function extractHeuristic(string $text): array
    {
        $claims  = [];
        $seen    = [];
        $sentences = $this->splitSentences(trim($text));

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '' || isset($seen[$sentence])) {
                continue;
            }

            $claimType = $this->detectClaimType($sentence);
            if ($claimType === null) {
                continue;
            }

            $seen[$sentence] = true;
            $claims[] = [
                'text'       => $sentence,
                'claim_type' => $claimType,
                'risk'       => $this->classifyRisk($sentence, $claimType),
            ];
        }

        return $claims;
    }

    /**
     * @return list<array{text: string, claim_type: string, risk: string}>
     */
    private function extractWithAi(string $text): array
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'claims' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'text'       => ['type' => 'string'],
                            'claim_type' => ['type' => 'string'],
                            'risk'       => ['type' => 'string'],
                        ],
                        'required' => ['text', 'claim_type', 'risk'],
                    ],
                ],
            ],
            'required' => ['claims'],
        ];

        $input = new AiGenerationInput(
            systemPrompt: 'Extract material factual claims from the article. Return JSON only.',
            userPrompt:     $text,
            outputSchema:   $schema,
            modelKey:       'claim-extractor',
            maxOutputTokens: 4096,
        );

        $result = $this->provider->generate($input);
        $claims = $result->parsedJson['claims'] ?? null;

        if (! is_array($claims)) {
            return $this->extractHeuristic($text);
        }

        $normalized = [];
        foreach ($claims as $claim) {
            if (! is_array($claim) || empty($claim['text'])) {
                continue;
            }

            $normalized[] = [
                'text'       => (string) $claim['text'],
                'claim_type' => (string) ($claim['claim_type'] ?? 'general'),
                'risk'       => strtoupper((string) ($claim['risk'] ?? 'LOW')),
            ];
        }

        return $normalized !== [] ? $normalized : $this->extractHeuristic($text);
    }

    private function normalizeText(string $htmlOrMarkdown): string
    {
        $text = strip_tags($htmlOrMarkdown);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function detectClaimType(string $sentence): ?string
    {
        if (preg_match('/\d+(?:\.\d+)?\s*%/', $sentence)) {
            return 'percentage';
        }

        if (preg_match('/(?:₹|Rs\.?|INR|\$|USD|€|EUR)\s*\d[\d,]*(?:\.\d+)?|\d[\d,]*(?:\.\d+)?\s*(?:crore|lakh|million|billion)/i', $sentence)) {
            return 'currency';
        }

        if (preg_match('/\b(section|notification|circular|rule|act|penalty|due date|rate)\b/i', $sentence)) {
            return 'statutory';
        }

        if (preg_match('/\b(?:19|20)\d{2}\b|\b\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}\b|\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4}\b/i', $sentence)) {
            return 'date';
        }

        return null;
    }

    private function classifyRisk(string $sentence, string $claimType): string
    {
        if ($this->isHighRiskSentence($sentence)) {
            return 'HIGH';
        }

        if (in_array($claimType, ['statutory', 'date', 'percentage'], true)) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    private function isHighRiskSentence(string $sentence): bool
    {
        $patterns = [
            '/\b(?:tax|gst|tds|income tax)\s+rate\b/i',
            '/\bdue date\b/i',
            '/\b(?:section|rule|act)\s+\d+/i',
            '/\b(?:notification|circular)\s+(?:no\.?|number)?\s*\d+/i',
            '/\bpenalty\b/i',
            '/\bcase law\b/i',
            '/\b(?:supreme court|high court)\b/i',
            '/\bstatutory\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sentence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function splitSentences(string $text): array
    {
        $protected = preg_replace('/\bno\./i', 'no‗', $text) ?? $text;
        $sentences = preg_split('/(?<=[.!?])\s+/', $protected) ?: [];

        return array_map(
            static fn (string $sentence) => str_replace('no‗', 'no.', trim($sentence)),
            array_filter($sentences, static fn (string $sentence) => trim($sentence) !== ''),
        );
    }
}
