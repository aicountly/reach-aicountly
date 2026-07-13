<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Prompts;

/**
 * Phase 3 — Validates AI-generated structured output against a JSON Schema.
 *
 * Uses a lightweight JSON Schema validator (draft-07 subset) without external libraries.
 * Rules enforced: required fields, type checks, minLength, maxLength, enum, minimum, maximum.
 * additionalProperties checking is also supported.
 *
 * Malformed output must NEVER create content versions or enter the review queue.
 */
class StructuredOutputValidator
{
    /**
     * Returns a list of validation error messages.
     * Empty array = valid.
     */
    public function validate(array $data, array $schema): array
    {
        return $this->validateObject($data, $schema, '$');
    }

    public function isValid(array $data, array $schema): bool
    {
        return empty($this->validate($data, $schema));
    }

    private function validateObject(array $data, array $schema, string $path): array
    {
        $errors = [];

        if (($schema['type'] ?? null) === 'object') {
            // Required fields
            foreach ($schema['required'] ?? [] as $field) {
                if (! array_key_exists($field, $data)) {
                    $errors[] = "{$path}.{$field} is required";
                }
            }

            // Properties
            foreach ($schema['properties'] ?? [] as $key => $propSchema) {
                if (array_key_exists($key, $data)) {
                    $errors = array_merge($errors, $this->validateValue($data[$key], $propSchema, "{$path}.{$key}"));
                }
            }

            // Additional properties
            if (($schema['additionalProperties'] ?? true) === false) {
                $allowed = array_keys($schema['properties'] ?? []);
                foreach (array_keys($data) as $k) {
                    if (! in_array($k, $allowed, true)) {
                        $errors[] = "{$path}.{$k} is not an allowed property";
                    }
                }
            }
        }

        return $errors;
    }

    private function validateValue(mixed $value, array $schema, string $path): array
    {
        $errors = [];
        $types  = (array) ($schema['type'] ?? 'string');

        if (! $this->matchesType($value, $types)) {
            $typeStr  = implode('|', $types);
            $actual   = gettype($value);
            $errors[] = "{$path} must be of type {$typeStr}, got {$actual}";
            return $errors;
        }

        if ($value === null) {
            return $errors;
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $errors[] = "{$path} must be at least {$schema['minLength']} characters";
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = "{$path} must be at most {$schema['maxLength']} characters";
            }
            if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
                $allowed  = implode(', ', $schema['enum']);
                $errors[] = "{$path} must be one of: {$allowed}";
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path} must be >= {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path} must be <= {$schema['maximum']}";
            }
        }

        if (is_array($value)) {
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$path} must have at most {$schema['maxItems']} items";
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    $errors = array_merge(
                        $errors,
                        $this->validateValue($item, $schema['items'], "{$path}[{$i}]")
                    );
                }
            }
        }

        if (is_array($value) && ($schema['type'] ?? null) === 'object') {
            $errors = array_merge($errors, $this->validateObject($value, $schema, $path));
        }

        return $errors;
    }

    private function matchesType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            if ($type === 'null' && $value === null) return true;
            if ($type === 'string' && is_string($value)) return true;
            if ($type === 'integer' && is_int($value)) return true;
            if ($type === 'number' && (is_int($value) || is_float($value))) return true;
            if ($type === 'boolean' && is_bool($value)) return true;
            if ($type === 'array' && is_array($value) && array_is_list($value)) return true;
            if ($type === 'object' && is_array($value) && ! array_is_list($value)) return true;
        }
        return false;
    }
}
