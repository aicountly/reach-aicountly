<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Images;

use App\Libraries\Ai\AiErrorClassifier;
use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\AiProviderException;
use App\Libraries\Ai\AiProviderHealthResult;
use App\Libraries\Ai\Providers\OpenAiProvider;

/**
 * OpenAI image generation adapter.
 *
 * Reuses AI_OPENAI_API_KEY. Model resolution: input modelKey, then
 * AI_OPENAI_IMAGE_MODEL env, then gpt-image-1.
 */
class OpenAiImageProvider implements ImageProviderInterface
{
    public const PROVIDER_KEY = 'openai_image';

    private const GENERATIONS_URL = '/v1/images/generations';

    private AiErrorClassifier $classifier;
    private string $baseUrl;

    public function __construct()
    {
        $this->classifier = new AiErrorClassifier();
        $this->baseUrl    = rtrim($_ENV['AI_OPENAI_BASE_URL'] ?? 'https://api.openai.com/v1', '/');
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
        return (new OpenAiProvider())->healthCheck();
    }

    public function generateImage(ImageGenerationInput $input): ImageGenerationResult
    {
        if (! $this->isConfigured()) {
            throw new AiProviderException(
                'OpenAI image provider is not configured.',
                new AiProviderError(AiProviderError::CATEGORY_CONFIGURATION, 'API key missing.'),
            );
        }

        $start    = hrtime(true);
        $modelKey = $this->resolveModelKey($input->modelKey);

        $body = [
            'model'  => $modelKey,
            'prompt' => $input->prompt,
            'size'   => $this->formatSize($input->width, $input->height),
            'n'      => $input->count,
        ];

        try {
            $response = $this->curlRequest(
                'POST',
                $this->baseUrl . self::GENERATIONS_URL,
                $body,
                $input->timeoutSeconds,
            );
        } catch (\Throwable $e) {
            $error = $this->classifyError($e);
            throw new AiProviderException($error->message, $error, $e);
        }

        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);
        $images     = [];

        foreach ($response['data'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $images[] = [
                'b64'  => isset($item['b64_json']) ? (string) $item['b64_json'] : null,
                'url'  => isset($item['url']) ? (string) $item['url'] : null,
                'mime' => $this->mimeForFormat($input->format),
            ];
        }

        return new ImageGenerationResult(
            images:             $images,
            providerKey:        self::PROVIDER_KEY,
            modelKey:           $modelKey,
            providerResponseId: isset($response['created']) ? (string) $response['created'] : null,
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

        $envModel = $_ENV['AI_OPENAI_IMAGE_MODEL'] ?? '';
        if (is_string($envModel) && trim($envModel) !== '') {
            return trim($envModel);
        }

        return 'gpt-image-1';
    }

    private function mimeForFormat(string $format): string
    {
        return match (strtolower($format)) {
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/webp',
        };
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
        return preg_replace('/sk-[A-Za-z0-9\-_]+/', '[REDACTED]', $redacted) ?? $redacted;
    }
}
