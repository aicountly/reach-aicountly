<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Validation\ValidationInterface;
use Config\Services;

/**
 * Thin wrapper around CI4 Validation with an ergonomic API for controllers.
 *
 * The Reach controllers historically did piecemeal `array_intersect_key`
 * calls before mutation. This helper collects the validated slice into a
 * single call and returns either `[$validated, null]` on success or
 * `[null, $errors]` on failure. Controllers translate errors into a 422
 * response with the standard envelope.
 *
 * Rulesets can be inline arrays OR named groups declared in
 * `App\Config\Validation`. Named groups are preferred because they can be
 * reused across store/update endpoints.
 */
class RequestValidator
{
    private ValidationInterface $validator;

    public function __construct(?ValidationInterface $validator = null)
    {
        $this->validator = $validator ?? Services::validation();
    }

    /**
     * @param array<string,mixed>          $data
     * @param array<string,string>|string  $rules  Named group OR rule array.
     * @param array<string,array<string,string>> $messages
     *
     * @return array{0: array<string,mixed>|null, 1: array<string,string>|null}
     */
    public function validate(array $data, array|string $rules, array $messages = []): array
    {
        if (is_string($rules)) {
            $group = $this->validator->getRuleGroup($rules);
            if ($group === []) {
                return [null, ['_rules' => "Unknown validation group: $rules"]];
            }
            $rules = $group;
        }

        $this->validator->reset();
        $this->validator->setRules($rules, $messages);

        if (! $this->validator->run($data)) {
            return [null, $this->validator->getErrors()];
        }

        $validated = [];
        foreach (array_keys($rules) as $field) {
            if (array_key_exists($field, $data)) {
                $validated[$field] = $data[$field];
            }
        }
        return [$validated, null];
    }

    /**
     * Convenience helper — return only the validated array or null if
     * validation failed. Errors are pushed to `$errorsOut` by reference.
     *
     * @param array<string,string>|null $errorsOut
     */
    public function only(array $data, array|string $rules, ?array &$errorsOut = null): ?array
    {
        [$validated, $errors] = $this->validate($data, $rules);
        $errorsOut = $errors;
        return $validated;
    }
}
