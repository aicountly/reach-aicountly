<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

class BodyMinimumLengthValidator implements ContentValidatorInterface
{
    private const MIN_CHARS = 200;

    public function getType(): string { return 'body_minimum_length'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $body   = strip_tags($content['body_html'] ?? $content['body_plain_text'] ?? $content['body_markdown'] ?? '');
        $length = mb_strlen(trim($body));

        if ($length < self::MIN_CHARS) {
            $sev = $length === 0 ? ValidationFinding::SEVERITY_HIGH : ValidationFinding::SEVERITY_WARNING;
            return [new ValidationFinding('body_minimum_length', ValidationFinding::STATUS_FAILED, $sev, 'Body too short', "Body is {$length} characters; minimum is " . self::MIN_CHARS . ".", 'body_html', null, 'Expand the body content to at least ' . self::MIN_CHARS . ' characters.')];
        }

        return [new ValidationFinding('body_minimum_length', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Body length acceptable', "Body length: {$length} characters.")];
    }
}
