<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Grounding;

use App\Libraries\KnowledgeGroundingService;

/**
 * Phase 3 — Wraps KnowledgeGroundingService for AI generation use.
 *
 * Adds eligibility filtering, conflict detection, size limiting,
 * and snapshot preparation on top of Phase 1 grounding.
 *
 * Contract:
 * - Only approved Phase 1 knowledge is ever included.
 * - Internal notes, draft/unapproved items, secrets, and confidential data are excluded.
 * - Grounding must not fail silently; if no product is found, throw GroundingException.
 */
class AiGroundingContextBuilder
{
    private KnowledgeGroundingService $knowledge;
    private GroundingEligibilityService $eligibility;
    private GroundingConflictDetector $conflicts;
    private GroundingSizeLimiter $sizeLimiter;

    public function __construct(
        ?KnowledgeGroundingService $knowledge = null,
        ?GroundingEligibilityService $eligibility = null,
        ?GroundingConflictDetector $conflicts = null,
        ?GroundingSizeLimiter $sizeLimiter = null,
    ) {
        $this->knowledge   = $knowledge  ?? new KnowledgeGroundingService();
        $this->eligibility = $eligibility ?? new GroundingEligibilityService();
        $this->conflicts   = $conflicts  ?? new GroundingConflictDetector();
        $this->sizeLimiter = $sizeLimiter ?? new GroundingSizeLimiter();
    }

    /**
     * Build a complete, approved grounding context for a product.
     *
     * @throws GroundingException when no approved product is found
     */
    public function buildForProduct(string $productSlug, ?string $intent = null): array
    {
        $rawContext = $this->knowledge->forProduct($productSlug, $intent);

        if (! $rawContext || empty($rawContext['product'])) {
            throw new GroundingException(
                "No approved product found for slug '{$productSlug}'."
            );
        }

        return $this->process($rawContext, $productSlug);
    }

    /**
     * Build grounding context for a specific task intent.
     * Falls back to generic brand rules and policies when no product is specified.
     */
    public function buildForIntent(string $intent, ?string $productSlug = null): array
    {
        if ($productSlug !== null) {
            return $this->buildForProduct($productSlug, $intent);
        }

        $rawContext = $this->knowledge->forIntent($intent);

        if (! $rawContext) {
            $rawContext = ['intent' => $intent, 'product' => null];
        }

        return $this->process($rawContext, null);
    }

    /**
     * Prepares a grounding snapshot record for database storage.
     */
    public function prepareSnapshot(array $groundingContext, int $generationRequestId): array
    {
        $snapshotJson = json_encode($groundingContext);
        $hash         = hash('sha256', $snapshotJson);

        return [
            'generation_request_id'   => $generationRequestId,
            'product_ids_json'         => json_encode($this->extractIds($groundingContext, 'product')),
            'module_ids_json'          => json_encode($this->extractIds($groundingContext, 'modules')),
            'feature_ids_json'         => json_encode($this->extractIds($groundingContext, 'features')),
            'persona_ids_json'         => json_encode($this->extractIds($groundingContext, 'personas')),
            'industry_ids_json'        => json_encode($this->extractIds($groundingContext, 'industries')),
            'claim_ids_json'           => json_encode($this->extractIds($groundingContext, 'claims')),
            'evidence_ids_json'        => json_encode($this->extractIds($groundingContext, 'evidence')),
            'source_ids_json'          => json_encode($this->extractIds($groundingContext, 'sources')),
            'brand_rule_ids_json'      => json_encode($this->extractIds($groundingContext, 'brand_rules')),
            'content_policy_ids_json'  => json_encode($this->extractIds($groundingContext, 'content_policies')),
            'snapshot_json'            => $snapshotJson,
            'snapshot_hash'            => $hash,
            'token_estimate'           => $this->sizeLimiter->estimateTokens($groundingContext),
            'created_at'               => date('Y-m-d H:i:s'),
        ];
    }

    private function process(array $rawContext, ?string $productSlug): array
    {
        // Apply eligibility filtering to each section
        $filterable = ['modules', 'features', 'personas', 'industries', 'markets', 'claims', 'evidence', 'sources', 'brand_rules', 'content_policies'];

        foreach ($filterable as $section) {
            if (isset($rawContext[$section]) && is_array($rawContext[$section])) {
                $type = $section === 'features' ? 'feature' : 'generic';
                $rawContext[$section] = $this->eligibility->filterEligible($rawContext[$section], $type);
            }
        }

        // Detect conflicts
        $detectedConflicts = $this->conflicts->detect($rawContext);
        if (! empty($detectedConflicts)) {
            $rawContext['__conflicts'] = $detectedConflicts;
        }

        // Size limit
        $limitedContext = $this->sizeLimiter->limit($rawContext);
        $limitedContext['__token_estimate'] = $this->sizeLimiter->estimateTokens($limitedContext);
        $limitedContext['__product_slug']   = $productSlug;
        $limitedContext['__built_at']       = date('c');

        return $limitedContext;
    }

    private function extractIds(array $context, string $section): array
    {
        if ($section === 'product') {
            return isset($context['product']['id']) ? [(int) $context['product']['id']] : [];
        }

        $items = $context[$section] ?? [];
        return array_values(array_filter(array_map(
            fn($item) => isset($item['id']) ? (int) $item['id'] : null,
            is_array($items) ? $items : []
        )));
    }
}
