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
 * Phase 3 — Perplexity provider adapter (OpenAI-compatible chat completions).
 *
 * Reads AI_PERPLEXITY_API_KEY from environment (never from database).
 * Uses native cURL — no external SDK dependency.
 *
 * Citations:
 * Perplexity may return `citations` (URL array) and/or `search_results` in the
 * response body. Because AiGenerationResult has no dedicated citations field,
 * citations are merged into parsedJson under the `_citations` key when present.
 * If the model content is JSON, decoded fields are preserved and `_citations`
 * is added alongside them for downstream fact verification.
 *
 * Security:
 * - API key is never logged, returned, or stored.
 * - Error messages redact Bearer tokens and pplx- style keys.
 */
class PerplexityProvider implements AiProviderInterface
{
    public const PROVIDER_KEY = 'perplexity';

    private const CHAT_URL = '/chat/completions';

    private AiErrorClassifier $classifier;
    private string $baseUrl;

    public function __construct()
    {
        $this->classifier = new AiErrorClassifier();
        $this->baseUrl    = rtrim($_ENV['AI_PERPLEXITY_BASE_URL'] ?? 'https://api.perplexity.ai', '/');
    }

    public function getProviderKey(): string
    {
        return self::PROVIDER_KEY;
    }

    public function isConfigured(): bool
    {
        $key = $_ENV['AI_PERPLEXITY_API_KEY'] ?? '';
        return is_string($key) && strlen(trim($key)) > 0;
    }

    public function healthCheck(): AiProviderHealthResult
    {
        if (! $this->isConfigured()) {
            return new AiProviderHealthResult(false, null, 'Provider not configured.');
        }

        $allowSpend = filter_var(
            $_ENV['AI_PERPLEXITY_HEALTHCHECK_ALLOW_SPEND'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

        if (! $allowSpend) {
            return new AiProviderHealthResult(true, null, 'Configured; live check skipped to avoid spend.');
        }

        $start = hrtime(true);
        try {
            $body = [
                'model'      => $this->resolveModelKey(null),
                'messages'   => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 1,
            ];
            $this->curlRequest('POST', $this->baseUrl . self::CHAT_URL, $body, 5);
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            return new AiProviderHealthResult(true, $ms);
        } catch (\Throwable $e) {
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            return new AiProviderHealthResult(false, $ms, 'Health check failed.');
        }
    }

    public function generate(AiGenerationInput $input): AiGenerationResult
    {
        if (! $this->isConfigured()) {
            throw new AiProviderException(
                'Perplexity provider is not configured.',
                new AiProviderError(AiProviderError::CATEGORY_CONFIGURATION, 'API key missing.'),
            );
        }

        $start    = hrtime(true);
        $modelKey = $this->resolveModelKey($input->modelKey !== '' ? $input->modelKey : null);

        $body = [
            'model'      => $modelKey,
            'messages'   => [
                ['role' => 'system', 'content' => $input->systemPrompt],
                ['role' => 'user',   'content' => $input->userPrompt],
            ],
            'max_tokens' => $input->maxOutputTokens,
        ];

        if (! empty($input->outputSchema)) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = $this->curlRequest(
                'POST',
                $this->baseUrl . self::CHAT_URL,
                $body,
                $input->timeoutSeconds,
            );
        } catch (\Throwable $e) {
            $error = $this->classifyError($e);
            throw new AiProviderException($error->message, $error, $e);
        }

        $durationMs         = (int) ((hrtime(true) - $start) / 1_000_000);
        $rawContent         = $response['choices'][0]['message']['content'] ?? '';
        $providerResponseId = $response['id'] ?? null;
        $usage              = $response['usage'] ?? [];
        $inputTokens        = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens       = (int) ($usage['completion_tokens'] ?? 0);
        $totalTokens        = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));
        $citations          = $this->extractCitations($response);

        $parsedJson = null;
        if ($rawContent !== '') {
            $decoded = json_decode($rawContent, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $parsedJson = $decoded;
            }
        }

        if ($citations !== []) {
            $parsedJson = is_array($parsedJson) ? $parsedJson : [];
            $parsedJson['_citations'] = $citations;
        }

        return new AiGenerationResult(
            rawContent:         $rawContent,
            parsedJson:         $parsedJson,
            inputTokens:        $inputTokens,
            outputTokens:       $outputTokens,
            totalTokens:        $totalTokens,
            providerResponseId: $providerResponseId,
            durationMs:         $durationMs,
            modelKey:           $modelKey,
            providerKey:        self::PROVIDER_KEY,
        );
    }

    public function classifyError(\Throwable $error): AiProviderError
    {
        return $this->classifier->classify($error);
    }

    /**
     * Resolve the model key from input or environment defaults.
     */
    public function resolveModelKey(?string $modelKey): string
    {
        if ($modelKey !== null && $modelKey !== '') {
            return $modelKey;
        }

        $envDefault = $_ENV['AI_PERPLEXITY_DEFAULT_MODEL'] ?? '';
        if (is_string($envDefault) && trim($envDefault) !== '') {
            return trim($envDefault);
        }

        return 'sonar-pro';
    }

    /**
     * @return list<string>
     */
    private function extractCitations(array $response): array
    {
        $citations = [];

        if (! empty($response['citations']) && is_array($response['citations'])) {
            foreach ($response['citations'] as $citation) {
                if (is_string($citation) && $citation !== '') {
                    $citations[] = $citation;
                }
            }
        }

        if (! empty($response['search_results']) && is_array($response['search_results'])) {
            foreach ($response['search_results'] as $result) {
                if (is_array($result) && ! empty($result['url']) && is_string($result['url'])) {
                    $citations[] = $result['url'];
                }
            }
        }

        return array_values(array_unique($citations));
    }

    /**
     * Internal cURL helper. Never logs the API key.
     *
     * @throws \RuntimeException with redacted message on failure
     */
    private function curlRequest(string $method, string $url, ?array $body, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($_ENV['AI_PERPLEXITY_API_KEY'] ?? ''),
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST' && $body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw      = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errmsg   = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== CURLE_OK) {
            throw new \RuntimeException('cURL error: ' . $this->redactMessage($errmsg));
        }

        $decoded = json_decode((string) $raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Malformed JSON response from provider.');
        }

        if ($httpCode >= 400) {
            $errMsg = $decoded['error']['message'] ?? ("HTTP {$httpCode}");
            throw new \RuntimeException($this->redactMessage($errMsg));
        }

        return $decoded;
    }

    private function redactMessage(string $msg): string
    {
        $redacted = preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $msg) ?? $msg;
        $redacted = preg_replace('/pplx-[A-Za-z0-9\-_]+/i', '[REDACTED]', $redacted) ?? $redacted;

        return $redacted;
    }
}
