<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\AiProviderHealthResult;
use App\Libraries\Ai\Images\ImageGenerationInput;
use App\Libraries\Ai\Images\ImageGenerationResult;
use App\Libraries\Ai\Images\ImageProviderInterface;

/**
 * Stands in for OpenAI GPT Image: returns a real 1536x1024 PNG (so decoding,
 * branding and WebP conversion all run for real) and records every prompt.
 */
final class FakeCoverImageProvider implements ImageProviderInterface
{
    public int $calls = 0;
    /** @var list<string> */
    public array $prompts = [];

    public function __construct(private readonly bool $configured = true, private readonly ?string $failWith = null)
    {
    }

    public function getProviderKey(): string
    {
        return 'openai_image';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function healthCheck(): AiProviderHealthResult
    {
        throw new \LogicException('unused');
    }

    public function classifyError(\Throwable $error): AiProviderError
    {
        throw new \LogicException('unused');
    }

    public function generateImage(ImageGenerationInput $input): ImageGenerationResult
    {
        $this->calls++;
        $this->prompts[] = $input->prompt;
        if ($this->failWith !== null) {
            throw new \RuntimeException($this->failWith);
        }

        $img = imagecreatetruecolor(1536, 1024);
        // A different colour per call so every image has its own checksum.
        imagefill($img, 0, 0, imagecolorallocate($img, (40 + $this->calls * 17) % 256, 140, 90));
        imagefilledellipse($img, 600, 500, 400, 300, imagecolorallocate($img, 7, 26, 51));
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return new ImageGenerationResult([['b64' => base64_encode((string) ob_get_clean()), 'url' => null, 'mime' => 'image/png']], 'openai_image', 'gpt-image-2.5-flare', null, 5, 1);
    }
}
