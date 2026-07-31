<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Images;

use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\AiProviderHealthResult;

interface ImageProviderInterface
{
    public function getProviderKey(): string;

    public function isConfigured(): bool;

    public function healthCheck(): AiProviderHealthResult;

    public function generateImage(ImageGenerationInput $input): ImageGenerationResult;

    public function classifyError(\Throwable $error): AiProviderError;
}
