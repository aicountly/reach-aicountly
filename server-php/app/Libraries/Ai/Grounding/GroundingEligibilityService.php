<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Grounding;

/**
 * Phase 3 — Determines whether a knowledge entity may be included in AI grounding.
 *
 * Only approved, non-expired, non-deleted entities are eligible.
 * Drafts, unapproved, expired, internal-only, and archived items are excluded.
 */
class GroundingEligibilityService
{
    /** Statuses that are never eligible for grounding. */
    private const INELIGIBLE_STATUSES = [
        'draft', 'pending_review', 'rejected', 'archived', 'deprecated',
    ];

    /** Availability values considered eligible for grounding. */
    private const ELIGIBLE_AVAILABILITIES = [
        'available', 'limited', 'beta',
    ];

    /**
     * Returns true if a generic entity (any table) is eligible for grounding.
     *
     * @param array $entity  Row from any knowledge table
     */
    public function isEligible(array $entity): bool
    {
        // Hard-deleted
        if (! empty($entity['deleted_at'])) {
            return false;
        }

        // Status check
        $status = $entity['status'] ?? $entity['approval_status'] ?? '';
        if (in_array($status, self::INELIGIBLE_STATUSES, true)) {
            return false;
        }

        // Expired validity
        if (! empty($entity['valid_until']) && strtotime($entity['valid_until']) < time()) {
            return false;
        }

        // Internal-only marker
        if (! empty($entity['internal_only'])) {
            return false;
        }

        // Secrets / confidential
        if (! empty($entity['is_confidential'])) {
            return false;
        }

        return true;
    }

    /**
     * Filters a feature entity for grounding.
     * Considers availability field in addition to base checks.
     */
    public function isFeatureEligible(array $feature): bool
    {
        if (! $this->isEligible($feature)) {
            return false;
        }

        $availability = $feature['availability'] ?? 'available';
        return in_array($availability, self::ELIGIBLE_AVAILABILITIES, true);
    }

    /**
     * Filter an array of entities to only eligible ones.
     */
    public function filterEligible(array $entities, string $type = 'generic'): array
    {
        if ($type === 'feature') {
            return array_values(array_filter($entities, fn($e) => $this->isFeatureEligible($e)));
        }
        return array_values(array_filter($entities, fn($e) => $this->isEligible($e)));
    }
}
