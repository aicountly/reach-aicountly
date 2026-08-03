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
        $base = $this->envValue('AI_GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com';
        $this->baseUrl = rtrim($base, '/');
    }

    public function getProviderKey(): string
    {
        return self::PROVIDER_KEY;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
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
            // Gemini 2.5 Flash thinking otherwise consumes the first part and
            // leaves blog drafts failing schema validation on non-JSON thought text.
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ];

        $userPrompt = $input->userPrompt;
        $safeSchema = null;
        if (! empty($input->outputSchema)) {
            $safeSchema = $this->geminiSafeSchema($input->outputSchema);
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseSchema']   = $safeSchema;
            // Always reinforce shape in the prompt — Gemini may drop long body_*
            // fields when schema enforcement is weak or falls back to JSON-only.
            $userPrompt = $this->appendSchemaInstructions($userPrompt, $safeSchema);
        }

        $body = [
            'systemInstruction' => [
                'parts' => [['text' => $input->systemPrompt]],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $userPrompt]],
                ],
            ],
            'generationConfig' => $generationConfig,
        ];

        $url = $this->generateContentUrl($input->modelKey);

        try {
            $response = $this->curlRequest('POST', $url, $body, $input->timeoutSeconds);
        } catch (\Throwable $e) {
            // Gemini often rejects complex JSON Schema; retry with JSON mime only.
            if ($safeSchema !== null) {
                try {
                    unset($body['generationConfig']['responseSchema']);
                    $body['generationConfig']['responseMimeType'] = 'application/json';
                    $response = $this->curlRequest('POST', $url, $body, $input->timeoutSeconds);
                } catch (\Throwable $fallbackError) {
                    $error = $this->classifyError($fallbackError);
                    throw new AiProviderException($error->message, $error, $fallbackError);
                }
            } else {
                $error = $this->classifyError($e);
                throw new AiProviderException($error->message, $error, $e);
            }
        }

        $durationMs         = (int) ((hrtime(true) - $start) / 1_000_000);
        $rawContent         = $this->extractCandidateText($response);
        $providerResponseId = $response['responseId'] ?? null;
        $usage              = $response['usageMetadata'] ?? [];
        $inputTokens        = (int) ($usage['promptTokenCount'] ?? 0);
        $outputTokens       = (int) ($usage['candidatesTokenCount'] ?? 0);
        $totalTokens        = (int) ($usage['totalTokenCount'] ?? ($inputTokens + $outputTokens));

        $parsedJson = null;
        if (! empty($input->outputSchema) && $rawContent !== '') {
            $parsedJson = $this->decodeJsonPayload($rawContent);
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
        return '?key=' . rawurlencode($this->apiKey());
    }

    private function apiKey(): string
    {
        return $this->envValue('AI_GEMINI_API_KEY');
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
     * Prefer non-thought text parts. Gemini 2.5 "thinking" responses put
     * reasoning in parts[0] with thought=true; blog JSON is usually later.
     *
     * @param array<string,mixed> $response
     */
    private function extractCandidateText(array $response): string
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        if (! is_array($parts) || $parts === []) {
            return '';
        }

        $answerChunks = [];
        $anyChunks    = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            $text = trim((string) ($part['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $anyChunks[] = $text;
            if (empty($part['thought'])) {
                $answerChunks[] = $text;
            }
        }

        $joined = implode("\n", $answerChunks !== [] ? $answerChunks : $anyChunks);
        return trim($joined);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJsonPayload(string $raw): ?array
    {
        $candidates = [$raw];
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
            $candidates[] = trim($m[1]);
        }
        if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $candidates[] = $m[0];
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Convert app JSON Schema into a Gemini responseSchema-compatible subset.
     *
     * Gemini generateContent rejects many JSON Schema features (type unions,
     * additionalProperties, oversized required lists). When rejected we fall
     * back to JSON mime only — so this conversion must succeed often enough
     * that long blog body fields are actually enforced.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function geminiSafeSchema(array $schema): array
    {
        unset(
            $schema['$schema'],
            $schema['$id'],
            $schema['$comment'],
            $schema['additionalProperties'],
            $schema['$defs'],
            $schema['definitions'],
        );

        // Prefer a single body field under structured output — coercer derives
        // markdown/plain from body_html. Requiring three long duplicates often
        // yields empty/truncated bodies under token pressure.
        if (isset($schema['required']) && is_array($schema['required'])) {
            $schema['required'] = array_values(array_filter(
                $schema['required'],
                static fn ($f) => ! in_array($f, ['body_markdown', 'body_plain_text'], true),
            ));
        }

        if (isset($schema['type']) && is_array($schema['type'])) {
            $types = array_values(array_filter(
                $schema['type'],
                static fn ($t) => is_string($t) && strtolower($t) !== 'null',
            ));
            $nullable = count($schema['type']) > count($types);
            $schema['type'] = $types[0] ?? 'string';
            if ($nullable) {
                $schema['nullable'] = true;
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $ordered = [];
            // Fill substantive article text before SEO/meta so MAX_TOKENS truncations
            // do not leave body_html empty.
            $priority = ['title', 'summary', 'body_html', 'sections', 'body_markdown', 'body_plain_text'];
            foreach ($priority as $name) {
                if (isset($schema['properties'][$name]) && is_array($schema['properties'][$name])) {
                    $ordered[$name] = $this->geminiSafeSchema($schema['properties'][$name]);
                }
            }
            foreach ($schema['properties'] as $name => $child) {
                if (isset($ordered[$name]) || ! is_array($child)) {
                    continue;
                }
                $ordered[$name] = $this->geminiSafeSchema($child);
            }
            $schema['properties'] = $ordered;
            $schema['propertyOrdering'] = array_keys($ordered);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->geminiSafeSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function appendSchemaInstructions(string $userPrompt, array $schema): string
    {
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $requiredList = $required !== [] ? implode(', ', $required) : 'title, summary, body_html';

        return $userPrompt
            . "\n\n---\nOUTPUT RULES (mandatory):\n"
            . "- Respond with one JSON object only (no markdown fences).\n"
            . "- Required keys: {$requiredList}.\n"
            . "- body_html MUST be a complete HTML article: multiple <h2>/<h3>/<p>/<ul><li> sections,"
            . " at least 900 words of real prose. Never placeholders like \"Untitled draft\".\n"
            . "- Prefer putting the full article in body_html; body_markdown and body_plain_text may mirror it.\n"
            . "- If you include sections[], each item needs heading + body with real paragraphs.\n"
            . "- Do not leave body_html empty or under ~400 characters.\n";
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
