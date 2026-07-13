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
 * Phase 3 — OpenAI provider adapter.
 *
 * Reads AI_OPENAI_API_KEY from environment (never from database).
 * Adapter is completely inactive (isConfigured() = false) when the key is absent.
 * Uses native cURL — no external SDK dependency.
 *
 * Security:
 * - API key is never logged, returned, or stored.
 * - Error messages are redacted before propagation.
 * - Full provider headers are never written to logs.
 */
class OpenAiProvider implements AiProviderInterface
{
    private const PROVIDER_KEY  = 'openai';
    private const MODELS_URL    = '/v1/models';
    private const CHAT_URL      = '/v1/chat/completions';

    private AiErrorClassifier $classifier;
    private string $baseUrl;

    public function __construct()
    {
        $this->classifier = new AiErrorClassifier();
        $this->baseUrl    = rtrim($_ENV['AI_OPENAI_BASE_URL'] ?? 'https://api.openai.com/v1', '/');
        // Normalise so CHAT_URL prefix works regardless
        $this->baseUrl    = preg_replace('#/v1$#', '', $this->baseUrl);
    }

    public function getProviderKey(): string
    {
        return self::PROVIDER_KEY;
    }

    public function isConfigured(): bool
    {
        $key = $_ENV['AI_OPENAI_API_KEY'] ?? '';
        return is_string($key) && strlen(trim($key)) > 0;
    }

    public function healthCheck(): AiProviderHealthResult
    {
        if (! $this->isConfigured()) {
            return new AiProviderHealthResult(false, null, 'Provider not configured.');
        }

        $start = hrtime(true);
        try {
            $this->curlRequest('GET', $this->baseUrl . self::MODELS_URL, null, 5);
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
                'OpenAI provider is not configured.',
                new AiProviderError(AiProviderError::CATEGORY_CONFIGURATION, 'API key missing.'),
            );
        }

        $start = hrtime(true);

        $body = [
            'model'       => $input->modelKey,
            'messages'    => [
                ['role' => 'system', 'content' => $input->systemPrompt],
                ['role' => 'user',   'content' => $input->userPrompt],
            ],
            'max_tokens'  => $input->maxOutputTokens,
        ];

        if (! empty($input->outputSchema)) {
            $body['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'output',
                    'strict' => true,
                    'schema' => $input->outputSchema,
                ],
            ];
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

        $durationMs        = (int) ((hrtime(true) - $start) / 1_000_000);
        $rawContent        = $response['choices'][0]['message']['content'] ?? '';
        $providerResponseId = $response['id'] ?? null;
        $usage             = $response['usage'] ?? [];
        $inputTokens       = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens      = (int) ($usage['completion_tokens'] ?? 0);
        $totalTokens       = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));

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
                'Authorization: Bearer ' . ($_ENV['AI_OPENAI_API_KEY'] ?? ''),
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST' && $body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== CURLE_OK) {
            throw new \RuntimeException('cURL error: ' . $this->redactCurlError($errmsg));
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

    private function redactCurlError(string $msg): string
    {
        return preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $msg) ?? $msg;
    }

    private function redactMessage(string $msg): string
    {
        $redacted = preg_replace('/sk-[A-Za-z0-9\-_]+/', '[REDACTED]', $msg) ?? $msg;
        return $redacted;
    }
}
