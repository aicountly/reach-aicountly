<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Providers;

use App\Libraries\Ai\AiErrorClassifier;
use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiGenerationResult;
use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\AiProviderException;
use App\Libraries\Ai\AiProviderHealthResult;
use App\Libraries\Ai\AiProviderInterface;

/**
 * Phase 3 — Deterministic mock provider for tests and local demo.
 *
 * NEVER used in production unless explicitly forced via REACH_AI_MOCK=true.
 * All token counts and costs are fixed for stable test assertions.
 *
 * Configure via $scenario:
 *   success          — returns valid structured JSON
 *   malformed        — returns unparseable output
 *   retryable_error  — throws rate-limited error
 *   terminal_error   — throws authentication error
 *   timeout          — throws timeout error
 *   budget           — throws budget_blocked error
 *   empty            — returns empty content string
 */
class MockAiProvider implements AiProviderInterface
{
    public const PROVIDER_KEY  = 'mock';
    public const FIXED_TOKENS  = ['input' => 150, 'output' => 350, 'total' => 500];

    private string $scenario;
    private ?array $customOutput;

    public function __construct(string $scenario = 'success', ?array $customOutput = null)
    {
        $this->scenario     = $scenario;
        $this->customOutput = $customOutput;
    }

    public function getProviderKey(): string
    {
        return self::PROVIDER_KEY;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function healthCheck(): AiProviderHealthResult
    {
        if ($this->scenario === 'retryable_error') {
            return new AiProviderHealthResult(false, 0, 'Mock health check failed.');
        }
        return new AiProviderHealthResult(true, 1);
    }

    public function generate(AiGenerationInput $input): AiGenerationResult
    {
        switch ($this->scenario) {
            case 'retryable_error':
                throw new AiProviderException(
                    'Rate limit reached.',
                    new AiProviderError(AiProviderError::CATEGORY_RATE_LIMITED, 'Rate limit reached.'),
                );

            case 'terminal_error':
                throw new AiProviderException(
                    'Authentication failed.',
                    new AiProviderError(AiProviderError::CATEGORY_AUTHENTICATION, 'Authentication failed.'),
                );

            case 'timeout':
                throw new AiProviderException(
                    'Request timed out.',
                    new AiProviderError(AiProviderError::CATEGORY_TIMEOUT, 'Request timed out.'),
                );

            case 'budget':
                throw new AiProviderException(
                    'Budget limit exceeded.',
                    new AiProviderError(AiProviderError::CATEGORY_BUDGET_BLOCKED, 'Budget limit exceeded.'),
                );

            case 'malformed':
                return new AiGenerationResult(
                    rawContent:         'this is not valid json {{{{',
                    parsedJson:         null,
                    inputTokens:        self::FIXED_TOKENS['input'],
                    outputTokens:       self::FIXED_TOKENS['output'],
                    totalTokens:        self::FIXED_TOKENS['total'],
                    providerResponseId: 'mock-malformed-001',
                    durationMs:         100,
                    modelKey:           $input->modelKey,
                    providerKey:        self::PROVIDER_KEY,
                );

            case 'empty':
                return new AiGenerationResult(
                    rawContent:         '',
                    parsedJson:         null,
                    inputTokens:        self::FIXED_TOKENS['input'],
                    outputTokens:       0,
                    totalTokens:        self::FIXED_TOKENS['input'],
                    providerResponseId: 'mock-empty-001',
                    durationMs:         50,
                    modelKey:           $input->modelKey,
                    providerKey:        self::PROVIDER_KEY,
                );

            default: // 'success'
                $output = $this->customOutput ?? $this->defaultOutput($input);
                $json   = json_encode($output);
                return new AiGenerationResult(
                    rawContent:         $json,
                    parsedJson:         $output,
                    inputTokens:        self::FIXED_TOKENS['input'],
                    outputTokens:       self::FIXED_TOKENS['output'],
                    totalTokens:        self::FIXED_TOKENS['total'],
                    providerResponseId: 'mock-success-001',
                    durationMs:         120,
                    modelKey:           $input->modelKey,
                    providerKey:        self::PROVIDER_KEY,
                );
        }
    }

    public function classifyError(\Throwable $error): AiProviderError
    {
        return (new AiErrorClassifier())->classify($error);
    }

    private function defaultOutput(AiGenerationInput $input): array
    {
        // GENERATE_DRAFT (and blog_post schema) require a real article: minLength
        // on body fields plus WorkBlockService's ≥200-word gate. Keep this
        // deterministic and long enough for Feature acceptance tests.
        $paragraph = 'Indian SMEs should reconcile purchase invoices, maintain books of account, '
            . 'and file GST returns on time to avoid interest, late fees, and notice risk. '
            . 'A practical monthly checklist covers GSTR-1 reporting, GSTR-3B payment, '
            . 'input tax credit eligibility, vendor compliance, and cash-flow planning for tax dues. ';
        $plain = trim(str_repeat($paragraph, 8));
        $html  = '<h2>Overview</h2><p>' . htmlspecialchars($paragraph) . '</p>'
            . '<h2>Monthly checklist</h2><p>' . htmlspecialchars(str_repeat($paragraph, 3)) . '</p>'
            . '<h2>Common mistakes</h2><p>' . htmlspecialchars(str_repeat($paragraph, 2)) . '</p>'
            . '<h2>Next steps</h2><p>' . htmlspecialchars(str_repeat($paragraph, 2)) . '</p>';

        return [
            'title'            => 'Mock Generated Title',
            'summary'          => 'A practical mock-generated guide covering GST filing, bookkeeping, and compliance basics for Indian SMEs.',
            'body_html'        => $html,
            'body_markdown'    => "## Overview\n\n{$plain}",
            'body_plain_text'  => $plain,
            'slug_suggestion'  => 'mock-generated-title',
            'meta_title'       => 'Mock Generated Title | Aicountly',
            'meta_description' => 'A mock-generated meta description covering GST and bookkeeping guidance for small businesses.',
            'primary_cta'      => 'Learn More',
            'reading_time_minutes' => max(1, (int) ceil(str_word_count($plain) / 200)),
            'sections'         => [
                ['heading' => 'Overview', 'body' => $paragraph],
                ['heading' => 'Monthly checklist', 'body' => str_repeat($paragraph, 3)],
            ],
            'claims_used'      => [],
            'citations_used'   => [],
            'risk_notes'       => [],
        ];
    }
}
