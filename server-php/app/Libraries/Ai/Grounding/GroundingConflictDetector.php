<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Grounding;

/**
 * Phase 3 — Detects conflicts within a grounding context.
 *
 * A conflict is a pair of claims that directly contradict each other based
 * on their claim_type and validity windows. Grounding with unresolved conflicts
 * is allowed but flagged so the generation request can be noted in risk_notes.
 */
class GroundingConflictDetector
{
    /**
     * Returns a list of detected conflicts. Each conflict is an array
     * with 'type' (string) and 'items' (array of conflicting entity IDs).
     *
     * @param array $groundingContext  Assembled grounding context from AiGroundingContextBuilder
     */
    public function detect(array $groundingContext): array
    {
        $conflicts = [];

        $claims = $groundingContext['claims'] ?? [];
        $conflicts = array_merge($conflicts, $this->detectClaimConflicts($claims));

        $features = $groundingContext['features'] ?? [];
        $conflicts = array_merge($conflicts, $this->detectFeatureConflicts($features));

        return $conflicts;
    }

    private function detectClaimConflicts(array $claims): array
    {
        $conflicts = [];
        $byType    = [];

        foreach ($claims as $claim) {
            $type = $claim['claim_type'] ?? 'general';
            $byType[$type][] = $claim;
        }

        // Detect contradictory claims: claims of the same type with opposite directions
        foreach ($byType as $type => $typeClaims) {
            $positive = array_filter($typeClaims, fn($c) => in_array($c['sentiment'] ?? '', ['positive', 'benefit'], true));
            $negative = array_filter($typeClaims, fn($c) => in_array($c['sentiment'] ?? '', ['negative', 'risk'], true));

            if (count($positive) > 0 && count($negative) > 0) {
                $conflicts[] = [
                    'type'    => 'claim_sentiment_conflict',
                    'message' => "Claims of type '{$type}' have both positive and negative sentiments.",
                    'items'   => array_merge(
                        array_column(array_values($positive), 'id'),
                        array_column(array_values($negative), 'id'),
                    ),
                ];
            }
        }

        return $conflicts;
    }

    private function detectFeatureConflicts(array $features): array
    {
        $conflicts = [];
        $bySlug    = [];

        foreach ($features as $feature) {
            $slug = $feature['slug'] ?? $feature['id'];
            if (isset($bySlug[$slug])) {
                $conflicts[] = [
                    'type'    => 'duplicate_feature',
                    'message' => "Feature slug '{$slug}' appears multiple times in grounding context.",
                    'items'   => [$bySlug[$slug]['id'], $feature['id']],
                ];
            } else {
                $bySlug[$slug] = $feature;
            }
        }

        return $conflicts;
    }
}
