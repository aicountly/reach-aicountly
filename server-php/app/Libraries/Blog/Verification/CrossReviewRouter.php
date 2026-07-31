<?php

declare(strict_types=1);

namespace App\Libraries\Blog\Verification;

/**
 * Routes editorial review and fact verification to complementary providers.
 */
class CrossReviewRouter
{
    public function reviewerFor(string $generatorProviderKey): string
    {
        return match ($generatorProviderKey) {
            'openai' => 'gemini',
            'gemini' => 'openai',
            default  => 'openai',
        };
    }

    public function verifierKey(): string
    {
        return 'perplexity';
    }

    public function assertNotSameProvider(string $generator, string $reviewer): void
    {
        if ($generator === $reviewer) {
            throw new \InvalidArgumentException(
                "Generator and reviewer must differ; both are '{$generator}'.",
            );
        }
    }
}
