<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

class HashtagCountValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'hashtag_count'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $contentType = $context['content_type'] ?? '';
        if ($contentType !== 'social_post') {
            return [new ValidationFinding('hashtag_count', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'Not applicable', 'Only applies to social_post.')];
        }

        $hashtags = $content['hashtags'] ?? [];
        $count    = count($hashtags);
        $platform = $content['platform'] ?? 'generic';

        $max = match ($platform) {
            'instagram' => 30,
            'twitter'   => 5,
            'linkedin'  => 5,
            default     => 15,
        };

        if ($count > $max) {
            return [new ValidationFinding('hashtag_count', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Too many hashtags', "{$count} hashtags for platform '{$platform}'; maximum recommended is {$max}.", 'hashtags', null, "Reduce hashtags to at most {$max}.")];
        }

        return [new ValidationFinding('hashtag_count', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Hashtag count acceptable', "{$count} hashtags for {$platform}.")];
    }
}
