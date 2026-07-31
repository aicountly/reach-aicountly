<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Images;

use App\Libraries\Ai\AiErrorClassifier;
use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\AiProviderException;
use App\Libraries\Ai\AiProviderHealthResult;
use App\Libraries\Ai\Providers\GeminiProvider;

/**
 * Google Gemini image generation adapter ("Nano Banana" flash image model).
 *
 * Reads AI_GEMINI_API_KEY from environment.
 * Model resolution: input modelKey, then AI_GEMINI_IMAGE_MODEL env,
 * then gemini-2.5-flash-image.
 */
class GeminiImageProvider implements ImageProviderInterface
{
    public const PROVIDER_KEY = 'gemini_image';

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
        return (new GeminiProvider())->healthCheck();
    }

    public function generateImage(ImageGenerationInput $input): ImageGenerationResult
    {
        if (! $this->isConfigured()) {
            throw new AiProviderException(
                'Gemini image provider is not configured.',
                new AiProviderError(AiProviderError::CATEGORY_CONFIGURATION, 'API key missing.'),
            );
        }

        $start    = hrtime(true);
        $modelKey = $this->resolveModelKey($input->modelKey);

        $body = [
            'contents' => [
                [
                    'parts' => [['text' => $input->prompt]],
                ],
            ],
        ];

        $url = $this->generateContentUrl($modelKey);

        try {
            $response = $this->curlRequest('POST', $url, $body, $input->timeoutSeconds);
        } catch (\Throwable $e) {
            $error = $this->classifyError($e);
            throw new AiProviderException($error->message, $error, $e);
        }

        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);
        $images     = [];
        $parts      = $response['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $part) {
            if (! is_array($part) || empty($part['inlineData']) || ! is_array($part['inlineData'])) {
                continue;
            }

            $inline = $part['inlineData'];
            $images[] = [
                'b64'  => isset($inline['data']) ? (string) $inline['data'] : null,
                'url'  => null,
                'mime' => (string) ($inline['mimeType'] ?? 'image/png'),
            ];
        }

        if ($input->count > 1 && count($images) > $input->count) {
            $images = array_slice($images, 0, $input->count);
        }

        return new ImageGenerationResult(
            images:             $images,
            providerKey:        self::PROVIDER_KEY,
            modelKey:           $modelKey,
            providerResponseId: isset($response['responseId']) ? (string) $response['responseId'] : null,
            durationMs:         $durationMs,
            imageCount:         count($images),
        );
    }

    public function classifyError(\Throwable $error): AiProviderError
    {
        return $this->classifier->classify($error);
    }

    public function formatSize(int $width, int $height): string
    {
        return "{$width}x{$height}";
    }

    public function resolveModelKey(string $inputModelKey): string
    {
        if ($inputModelKey !== '') {
            return $inputModelKey;
        }

        $envModel = $_ENV['AI_GEMINI_IMAGE_MODEL'] ?? '';
        if (is_string($envModel) && trim($envModel) !== '') {
            return trim($envModel);
        }

        return 'gemini-2.5-flash-image';
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
        return preg_replace('/AIza[A-Za-z0-9\-_]+/', '[REDACTED]', $redacted) ?? $redacted;
    }
}
