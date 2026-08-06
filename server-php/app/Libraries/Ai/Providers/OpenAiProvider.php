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
        $base = $this->envValue('AI_OPENAI_BASE_URL') ?: 'https://api.openai.com/v1';
        $this->baseUrl = rtrim($base, '/');
        // Normalise so CHAT_URL prefix works regardless
        $this->baseUrl = preg_replace('#/v1$#', '', $this->baseUrl) ?? $this->baseUrl;
    }

    public function getProviderKey(): string
    {
        return self::PROVIDER_KEY;
    }

    public function isConfigured(): bool
    {
        $key = $this->apiKey();
        return $key !== '';
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

        $tokenParam = $this->tokenLimitParam($input->modelKey);
        $body = [
            'model'       => $input->modelKey,
            'messages'    => [
                ['role' => 'system', 'content' => $input->systemPrompt],
                ['role' => 'user',   'content' => $input->userPrompt],
            ],
            $tokenParam   => $input->maxOutputTokens,
        ];

        if (! empty($input->outputSchema)) {
            // Prefer json_schema (strict:false). If OpenAI rejects the schema shape,
            // fall back to json_object so drafts can still complete; app-side
            // StructuredOutputValidator remains the source of truth.
            $body['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'output',
                    'strict' => false,
                    'schema' => $this->openaiSafeSchema($input->outputSchema),
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
            // Self-heal a token-limit parameter mismatch: swap to the other
            // spelling and retry once. tokenLimitParam() gets this right for
            // known families, but OpenAI has renamed it once already and a
            // guessed-wrong prefix would otherwise kill generation outright.
            if ($this->isTokenParamRejection($e)) {
                $swapped = $tokenParam === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens';
                unset($body[$tokenParam]);
                $body[$swapped] = $input->maxOutputTokens;
                try {
                    $response = $this->curlRequest(
                        'POST',
                        $this->baseUrl . self::CHAT_URL,
                        $body,
                        $input->timeoutSeconds,
                    );
                } catch (\Throwable $retryError) {
                    $error = $this->classifyError($retryError);
                    throw new AiProviderException($error->message, $error, $retryError);
                }
            } elseif (! empty($input->outputSchema) && $this->isSchemaRejection($e)) {
                try {
                    $body['response_format'] = ['type' => 'json_object'];
                    $response = $this->curlRequest(
                        'POST',
                        $this->baseUrl . self::CHAT_URL,
                        $body,
                        $input->timeoutSeconds,
                    );
                } catch (\Throwable $fallbackError) {
                    $error = $this->classifyError($fallbackError);
                    throw new AiProviderException($error->message, $error, $fallbackError);
                }
            } else {
                $error = $this->classifyError($e);
                throw new AiProviderException($error->message, $error, $e);
            }
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
                'Authorization: Bearer ' . $this->apiKey(),
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST' && $body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($payload === false) {
                throw new \RuntimeException('Failed to encode OpenAI request body as JSON.');
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
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

    private function apiKey(): string
    {
        return $this->envValue('AI_OPENAI_API_KEY');
    }

    private function envValue(string $key): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null) {
            $v = function_exists('env') ? env($key) : null;
        }

        return is_string($v) ? trim($v) : '';
    }

    /**
     * Which token-limit parameter the Chat Completions API expects.
     *
     * The reasoning families (gpt-5*, o1/o3/o4*) reject the legacy 'max_tokens'
     * outright — "Unsupported parameter: 'max_tokens' is not supported with this
     * model. Use 'max_completion_tokens' instead." — while older chat models
     * still take it. A wrong guess is recoverable: isTokenParamRejection()
     * swaps and retries.
     */
    private function tokenLimitParam(string $modelKey): string
    {
        $key = strtolower(trim($modelKey));

        return preg_match('/^(gpt-5|o[1-9])/', $key) === 1
            ? 'max_completion_tokens'
            : 'max_tokens';
    }

    private function isTokenParamRejection(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return (str_contains($msg, 'max_tokens') || str_contains($msg, 'max_completion_tokens'))
            && (str_contains($msg, 'unsupported parameter')
                || str_contains($msg, 'unrecognized request argument')
                || str_contains($msg, 'is not supported with this model')
                || str_contains($msg, 'use \'max_completion_tokens\' instead'));
    }

    private function isSchemaRejection(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'json_schema')
            || str_contains($msg, 'response_format')
            || str_contains($msg, 'invalid schema')
            || str_contains($msg, 'schema is too')
            || (str_contains($msg, '400') && str_contains($msg, 'schema'));
    }

    /**
     * Strip draft-07 / CI-only keys OpenAI json_schema often rejects even with strict:false.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function openaiSafeSchema(array $schema): array
    {
        unset($schema['$schema'], $schema['$id'], $schema['$comment']);

        foreach (['properties', 'items', 'additionalProperties', 'definitions', '$defs'] as $key) {
            if (! isset($schema[$key])) {
                continue;
            }
            if (is_array($schema[$key])) {
                if ($key === 'properties' || $key === 'definitions' || $key === '$defs') {
                    foreach ($schema[$key] as $propName => $propSchema) {
                        if (is_array($propSchema)) {
                            $schema[$key][$propName] = $this->openaiSafeSchema($propSchema);
                        }
                    }
                } elseif ($key === 'items' || $key === 'additionalProperties') {
                    $schema[$key] = $this->openaiSafeSchema($schema[$key]);
                }
            }
        }

        return $schema;
    }
}
