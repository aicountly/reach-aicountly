<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Controllers\BaseApiController;
use App\Libraries\KnowledgeGroundingService;
use App\Libraries\AuditLogger;

/**
 * Grounding API — returns deterministic, approved-only knowledge context.
 * No AI-provider calls are made here.
 * Internal notes, approval comments, and secrets are never included.
 */
class GroundingController extends BaseApiController
{
    private KnowledgeGroundingService $grounding;

    public function __construct()
    {
        $this->grounding = new KnowledgeGroundingService();
    }

    /**
     * GET /api/v1/knowledge/grounding/product/{slug}
     *
     * Returns full approved product grounding context.
     * Returns 404 if product does not exist OR is not approved
     * (to avoid leaking draft existence to callers).
     */
    public function product(string $slug)
    {
        $context = $this->grounding->forProduct($slug);
        if ($context === null) {
            return $this->fail('Product not found or not yet approved.', 404);
        }

        $this->audit('knowledge.grounded', 'product', null, null, null, ['slug' => $slug]);
        return $this->ok($context);
    }

    /**
     * GET /api/v1/knowledge/grounding/intent/{id}
     *
     * Returns approved search intent grounding context by ID.
     */
    public function intent(int $id)
    {
        $context = $this->grounding->forIntent($id);
        if ($context === null) {
            return $this->fail('Search intent not found or not yet approved.', 404);
        }
        return $this->ok($context);
    }

    /**
     * POST /api/v1/knowledge/grounding/context
     *
     * Multi-entity context assembly. Body:
     * {
     *   "product_slugs": ["smart_books", "hrms"],
     *   "channel": "blog"
     * }
     *
     * Returns only approved entities. Callers must validate the returned
     * feature availability fields — 'planned' is never collapsed into 'available'.
     */
    public function context()
    {
        $body = $this->input();

        if (empty($body['product_slugs']) && empty($body['intent_ids'])) {
            return $this->fail('At least one of product_slugs or intent_ids is required.', 422);
        }

        $context = $this->grounding->assembleContext($body);
        $this->audit('knowledge.context_assembled', 'grounding', null, null, null, [
            'product_slugs' => $body['product_slugs'] ?? [],
        ]);
        return $this->ok($context);
    }
}
