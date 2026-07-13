<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * Detects exact hash matches against existing published content.
 * Near-duplicate detection is handled by reach_content_similarity_records.
 */
class DuplicateContentValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'duplicate_content'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $body = $content['body_plain_text'] ?? strip_tags($content['body_html'] ?? '');
        if (empty(trim($body))) {
            return [new ValidationFinding('duplicate_content', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'No body to check', 'Not applicable.')];
        }

        $hash  = hash('sha256', mb_strtolower(trim($body)));

        try {
            $db    = db_connect();
            $match = $db->table('reach_content_similarity_records')
                ->where('exact_body_hash', $hash)
                ->where('is_duplicate', true)
                ->limit(1)
                ->get()
                ->getRowArray();
        } catch (\Throwable) {
            return [new ValidationFinding('duplicate_content', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'Duplicate check skipped', 'Could not connect to similarity records.')];
        }

        if ($match) {
            return [new ValidationFinding(
                'duplicate_content',
                ValidationFinding::STATUS_FAILED,
                ValidationFinding::SEVERITY_HIGH,
                'Exact duplicate detected',
                'This content body is identical to an existing content item.',
                'body_html',
                ['matched_content_item_id' => $match['content_item_id']],
                'Regenerate with different instructions or review the existing content.',
            )];
        }

        return [new ValidationFinding('duplicate_content', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'No duplicate detected', 'Content body hash is unique.')];
    }
}
