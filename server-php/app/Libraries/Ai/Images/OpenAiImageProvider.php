<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Images;

use App\Libraries\Settings\ReachConfig;
use App\Libraries\Ai\AiErrorClassifier;
use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\AiProviderException;
use App\Libraries\Ai\AiProviderHealthResult;
use App\Libraries\Ai\Providers\OpenAiProvider;

/**
 * OpenAI image generation adapter.
 *
 * Reuses the OpenAI key (Settings → AI & Automation, else AI_OPENAI_API_KEY).
 * Model resolution: input modelKey, then the `ai.image.model` setting
 * (AI_OPENAI_IMAGE_MODEL env fallback), then gpt-image-2.5-flare (dall-e-* left the API on 12 May 2026). DALL·E and the
 * gpt-image family take different sizes and response options; requestBody()
 * shapes the call for whichever is configured.
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
        return ReachConfig::secret('ai.openai.api_key') !== null;
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

        $body = $this->requestBody($modelKey, $input);

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

        $configured = trim((string) ReachConfig::get('ai.image.model', ''));

        return $configured !== '' ? $configured : 'gpt-image-2.5-flare';
    }

    /**
     * The generations payload for the model family. dall-e-3 accepts one image
     * per call and only 1024x1024 / 1792x1024 / 1024x1792, so the requested
     * size is snapped to the nearest aspect; dall-e-2 only squares. Both are
     * asked for base64 so the image is stored by us, not hot-linked from a URL
     * that expires. gpt-image-* returns base64 already and rejects
     * response_format.
     *
     * @return array<string, mixed>
     */
    public function requestBody(string $modelKey, ImageGenerationInput $input): array
    {
        $configuredSize = trim((string) ReachConfig::get('ai.image.size', ''));
        $size           = preg_match('/^\d{3,4}x\d{3,4}$/', $configuredSize) ? $configuredSize : $this->formatSize($input->width, $input->height);
        // A caller that needs a specific aspect (e.g. a 9:16 video still) overrides the blog-cover setting.
        $explicitSize = (string) ($input->extraParams['size'] ?? '');
        if (preg_match('/^\d{3,4}x\d{3,4}$/', $explicitSize)) {
            $size = $explicitSize;
        }
        $model          = strtolower($modelKey);

        if (str_starts_with($model, 'dall-e-3')) {
            return [
                'model'           => $modelKey,
                'prompt'          => $input->prompt,
                'size'            => $this->snapSize($size, ['1024x1024', '1792x1024', '1024x1792']),
                'n'               => 1,
                'quality'         => 'hd',
                'response_format' => 'b64_json',
            ];
        }
        if (str_starts_with($model, 'dall-e-2')) {
            return [
                'model'           => $modelKey,
                'prompt'          => $input->prompt,
                'size'            => $this->snapSize($size, ['256x256', '512x512', '1024x1024']),
                'n'               => max(1, $input->count),
                'response_format' => 'b64_json',
            ];
        }

        // GPT Image family: base64 is always returned (no response_format); quality and
        // output_format are its own options.
        $quality = strtolower(trim((string) ReachConfig::get('ai.image.quality', 'medium')));

        return [
            'model'         => $modelKey,
            'prompt'        => $input->prompt,
            'size'          => $this->snapSize($size, ['1024x1024', '1536x1024', '1024x1536']),
            'n'             => max(1, $input->count),
            'quality'       => in_array($quality, ['low', 'medium', 'high', 'auto'], true) ? $quality : 'medium',
            'output_format' => 'png',
        ];
    }

    /** @param list<string> $allowed */
    private function snapSize(string $size, array $allowed): string
    {
        if (in_array($size, $allowed, true)) {
            return $size;
        }
        [$w, $h] = array_map('intval', explode('x', $size) + [1, 1]);
        $want    = $h > 0 ? $w / $h : 1.0;
        $best    = $allowed[0];
        $bestGap = INF;
        foreach ($allowed as $candidate) {
            [$cw, $ch] = array_map('intval', explode('x', $candidate));
            $gap       = abs(($cw / $ch) - $want);
            if ($gap < $bestGap) {
                $best    = $candidate;
                $bestGap = $gap;
            }
        }
        return $best;
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
                'Authorization: Bearer ' . (ReachConfig::secret('ai.openai.api_key') ?? ''),
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
