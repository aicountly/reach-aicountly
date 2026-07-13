<?php

namespace App\Libraries\Publishing\KnowledgeBase;

/**
 * Phase 4 — Validates structural requirements for KB articles.
 *
 * Steps must be sequential, non-duplicate, and complete.
 * Unsafe instruction patterns are flagged.
 */
class KnowledgeBaseStructureValidator
{
    private const UNSAFE_PATTERNS = [
        '/\bdelete\s+all\b/i',
        '/\bdrop\s+table\b/i',
        '/\brm\s+-rf\b/i',
        '/\bformat\s+c:/i',
        '/\bshutdown\b/i',
        '/\bpassword\s*=\s*[\'"]?[^\'"\s]+/i',
    ];

    /**
     * Validate a steps array for how-to articles.
     *
     * @param array $steps Array of step objects
     * @return array<int, string> Error messages (empty = valid)
     */
    public function validateSteps(array $steps): array
    {
        $errors = [];

        if (empty($steps)) {
            $errors[] = 'Steps array is empty';
            return $errors;
        }

        $seenNumbers = [];

        foreach ($steps as $index => $step) {
            $pos = $index + 1;

            if (!isset($step['step_number'])) {
                $errors[] = "Step {$pos}: missing step_number";
                continue;
            }

            $num = (int) $step['step_number'];

            // Duplicate step numbers
            if (isset($seenNumbers[$num])) {
                $errors[] = "Duplicate step number: {$num}";
            }
            $seenNumbers[$num] = true;

            // Title required
            if (empty($step['title'])) {
                $errors[] = "Step {$num}: title is required";
            }

            // Description required
            if (empty($step['description'])) {
                $errors[] = "Step {$num}: description is required";
            }

            // Unsafe instruction detection
            $textToCheck = ($step['title'] ?? '') . ' ' . ($step['description'] ?? '');
            foreach (self::UNSAFE_PATTERNS as $pattern) {
                if (preg_match($pattern, $textToCheck)) {
                    $errors[] = "Step {$num}: potentially unsafe instruction detected";
                    break;
                }
            }
        }

        // Sequential check — steps must form a contiguous sequence
        if (!empty($seenNumbers)) {
            $nums = array_keys($seenNumbers);
            sort($nums);
            $expected = range($nums[0], $nums[count($nums) - 1]);
            $missing = array_diff($expected, $nums);
            foreach ($missing as $m) {
                $errors[] = "Missing step number: {$m} (steps must be sequential)";
            }
        }

        return $errors;
    }

    /**
     * Validate troubleshooting entries.
     *
     * @param array $entries
     * @return array<int, string>
     */
    public function validateTroubleshooting(array $entries): array
    {
        $errors = [];

        foreach ($entries as $index => $entry) {
            $pos = $index + 1;
            if (empty($entry['symptom'])) {
                $errors[] = "Troubleshooting entry {$pos}: symptom is required";
            }
            if (empty($entry['resolution'])) {
                $errors[] = "Troubleshooting entry {$pos}: resolution is required";
            }
        }

        return $errors;
    }

    /**
     * Validate version applicability declaration.
     *
     * @param array $applicability
     * @return array<int, string>
     */
    public function validateVersionApplicability(array $applicability): array
    {
        $errors   = [];
        $allowed  = ['all_current_versions', 'specific_versions', 'version_range', 'planned_version', 'historical_version', 'not_applicable'];

        $type = $applicability['type'] ?? '';

        if (!in_array($type, $allowed, true)) {
            $errors[] = "Invalid version applicability type: {$type}. Allowed: " . implode(', ', $allowed);
        }

        if ($type === 'specific_versions' && empty($applicability['versions'])) {
            $errors[] = 'specific_versions type requires a versions array';
        }

        if ($type === 'version_range' && (empty($applicability['from']) || empty($applicability['to']))) {
            $errors[] = 'version_range type requires from and to fields';
        }

        // planned_version must not be presented as currently available
        if ($type === 'planned_version' && empty($applicability['preview_label'])) {
            $errors[] = 'planned_version type requires a preview_label to prevent confusion with available features';
        }

        return $errors;
    }
}
