<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Visuals;

use App\Libraries\Ai\AiProviderException;
use App\Libraries\Ai\Images\ImageGenerationInput;
use App\Libraries\Ai\Images\OpenAiImageProvider;
use App\Libraries\Video\Automation\VideoProviderException;

/**
 * GPT Image (OpenAI images API, model from ai.image.model) stills for scenes
 * and the YouTube thumbnail. The prompt comes from StillPromptBuilder (the
 * scene's own words + the video topic); brand guidance is appended to every
 * prompt. The logo is never drawn by the model — the render template overlays
 * the Aicountly corner mark and the thumbnail gets it composited.
 */
final class OpenAiStillSource implements StillImageSource
{
    public const STYLE = 'Clean, modern, professional illustration for an Indian small-business software brand. '
        . 'Colour palette: deep navy (#071a33), blue (#145490) and fresh green (#8cc854) on light backgrounds. '
        . 'No text, no letters, no logos, no watermarks, no real or recognisable people.';

    public function __construct(private readonly ?OpenAiImageProvider $provider = null)
    {
    }

    public function generate(string $prompt, string $orientation): string
    {
        $provider = $this->provider ?? new OpenAiImageProvider();
        if (! $provider->isConfigured()) {
            throw VideoProviderException::notConfigured('OpenAI API key is not set — stills cannot be generated.');
        }
        $portrait = $orientation === 'portrait';

        try {
            $result = $provider->generateImage(new ImageGenerationInput(
                prompt: trim($prompt) . "\n\n" . self::STYLE,
                width: $portrait ? 1024 : 1792,
                height: $portrait ? 1792 : 1024,
                format: 'png',
                timeoutSeconds: 120,
                extraParams: ['size' => $portrait ? '1024x1792' : '1792x1024'],
            ));
        } catch (AiProviderException $e) {
            throw new VideoProviderException('Image generation failed: ' . $e->getMessage(),
                $e->isRetryable() ? VideoProviderException::TRANSIENT : VideoProviderException::PERMANENT, $e);
        }

        $image = $result->images[0] ?? null;
        $bytes = is_array($image) && is_string($image['b64'] ?? null) ? base64_decode($image['b64'], true) : false;
        if ($bytes === false || $bytes === '') {
            throw new VideoProviderException('Image generation returned no image.', VideoProviderException::TRANSIENT);
        }
        return $bytes;
    }
}
