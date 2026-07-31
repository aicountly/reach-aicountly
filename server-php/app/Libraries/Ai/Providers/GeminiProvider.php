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
 * Phase 3 — Google Gemini provider adapter.
 *
 * Reads AI_GEMINI_API_KEY from environment (never from database).
 * Uses native cURL — no external SDK dependency.
 *
 * Security:
 * - API key is never logged, returned, or stored.
 * - Error messages redact key= query parameters before propagation.
 */
class GeminiProvider implements AiProviderInterface
{
    public const PROVIDER_KEY = 'gemini';

    private AiErrorClassifier $classifier;
    private string $baseUrl;

    public function __construct()
    {
        $this->classifier = new AiErrorClassifier();
        $this->baseUrl    = rtrim($_ENV['AI_GEMINI_BASE_URL'] ?? 'https://generativelanguage.googleapis.com', '/');
    }

    public function getProviderKey(): string
    {
        return self::PROVIDER_KEY;
    }

    public function isConfigured(): bool
    {
        $key = $_ENV['AI_GEMINI_API_KEY'] ?? '';
        return is_string($key) && strlen(trim($key)) > 0;
    }

    public function healthCheck(): AiProviderHealthResult
    {
        if (! $this->isConfigured()) {
            return new AiProviderHealthResult(false, null, 'Provider not configured.');
        }

        $start = hrtime(true);
        try {
            $this->curlRequest('GET', $this->modelsUrl(), null, 5);
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
                'Gemini provider is not configured.',
                new AiProviderError(AiProviderError::CATEGORY_CONFIGURATION, 'API key missing.'),
            );
        }

        $start = hrtime(true);

        $generationConfig = [
            'maxOutputTokens' => $input->maxOutputTokens,
        ];

        if (! empty($input->outputSchema)) {
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseSchema'] = $input->outputSchema;
        }

        $body = [
            'systemInstruction' => [
                'parts' => [['text' => $input->systemPrompt]],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $input->userPrompt]],
                ],
            ],
            'generationConfig' => $generationConfig,
        ];

        $url = $this->generateContentUrl($input->modelKey);

        try {
            $response = $this->curlRequest('POST', $url, $body, $input->timeoutSeconds);
        } catch (\Throwable $e) {
            $error = $this->classifyError($e);
            throw new AiProviderException($error->message, $error, $e);
        }

        $durationMs         = (int) ((hrtime(true) - $start) / 1_000_000);
        $rawContent         = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $providerResponseId = $response['responseId'] ?? null;
        $usage              = $response['usageMetadata'] ?? [];
        $inputTokens        = (int) ($usage['promptTokenCount'] ?? 0);
        $outputTokens       = (int) ($usage['candidatesTokenCount'] ?? 0);
        $totalTokens        = (int) ($usage['totalTokenCount'] ?? ($inputTokens + $outputTokens));

        $parsedJson = null;
        if (! empty($input->outputSchema) && $rawContent !== '') {
            $decoded = json_decode($rawContent, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $parsedJson = $decoded;
            }
        }

        return new AiGenerationResult(
            rawContent:         $rawContent,
            parsedJson:         $parsedJson,
            inputTokens:        $inputTokens,
            outputTokens:       $outputTokens,
            totalTokens:        $totalTokens,
            providerResponseId: $providerResponseId,
            durationMs:         $durationMs,
            modelKey:           $input->modelKey,
            providerKey:        self::PROVIDER_KEY,
        );
    }

    public function classifyError(\Throwable $error): AiProviderError
    {
        return $this->classifier->classify($error);
    }

    private function modelsUrl(): string
    {
        return $this->baseUrl . '/v1beta/models' . $this->apiKeyQuery();
    }

    private function generateContentUrl(string $model): string
    {
        return $this->baseUrl . '/v1beta/models/' . rawurlencode($model) . ':generateContent' . $this->apiKeyQuery();
    }

    private function apiKeyQuery(): string
    {
        return '?key=' . rawurlencode(trim((string) ($_ENV['AI_GEMINI_API_KEY'] ?? '')));
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
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
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
        $redacted = preg_replace('/key=[^&\s"\']+/i', 'key=[REDACTED]', $msg) ?? $msg;
        $redacted = preg_replace('/AIza[A-Za-z0-9\-_]+/', '[REDACTED]', $redacted) ?? $redacted;

        return $redacted;
    }
}
