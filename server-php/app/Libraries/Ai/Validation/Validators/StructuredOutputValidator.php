<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;
use App\Libraries\Ai\Prompts\OutputSchemaRegistry;
use App\Libraries\Ai\Prompts\StructuredOutputValidator as SchemaValidator;

/**
 * Validates AI output against the content-type JSON schema.
 * Blocking: missing required fields and schema violations at critical level.
 */
class StructuredOutputValidator implements ContentValidatorInterface
{
    private SchemaValidator $schemaValidator;

    public function __construct()
    {
        $this->schemaValidator = new SchemaValidator();
    }

    public function getType(): string { return 'structured_output'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $contentType = $context['content_type'] ?? 'generic';
        $schema      = OutputSchemaRegistry::get($contentType);
        $errors      = $this->schemaValidator->validate($content, $schema);

        if (empty($errors)) {
            return [new ValidationFinding('structured_output', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Schema valid', 'Output matches the required schema for ' . $contentType)];
        }

        return [new ValidationFinding(
            'structured_output',
            ValidationFinding::STATUS_FAILED,
            ValidationFinding::SEVERITY_CRITICAL,
            'Schema validation failed',
            'AI output does not conform to schema: ' . implode('; ', array_slice($errors, 0, 5)),
            null,
            ['errors' => $errors],
            'Ensure the AI model returns all required fields in the correct format.',
        )];
    }
}
