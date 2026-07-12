<?php

namespace App\Libraries;

use App\Models\Knowledge\ProductModel;
use App\Models\Knowledge\ProductModuleModel;
use App\Models\Knowledge\ProductFeatureModel;
use App\Models\Knowledge\PersonaModel;
use App\Models\Knowledge\IndustryModel;
use App\Models\Knowledge\MarketModel;
use App\Models\Knowledge\SearchIntentModel;
use App\Models\Knowledge\ProductClaimModel;
use App\Models\Knowledge\EvidenceModel;
use App\Models\Knowledge\SourceModel;
use App\Models\Knowledge\BrandRuleModel;
use App\Models\Knowledge\ContentPolicyModel;

/**
 * Assembles deterministic, machine-readable grounding context from the
 * knowledge store. All queries return approved records only. Internal notes,
 * approval comments, and secrets are never included in responses.
 *
 * No AI-provider calls are made here.
 */
class KnowledgeGroundingService
{
    private ProductModel       $products;
    private ProductModuleModel $modules;
    private ProductFeatureModel $features;
    private PersonaModel       $personas;
    private IndustryModel      $industries;
    private MarketModel        $markets;
    private SearchIntentModel  $intents;
    private ProductClaimModel  $claims;
    private EvidenceModel      $evidence;
    private SourceModel        $sources;
    private BrandRuleModel     $brandRules;
    private ContentPolicyModel $policies;

    public function __construct()
    {
        $this->products   = new ProductModel();
        $this->modules    = new ProductModuleModel();
        $this->features   = new ProductFeatureModel();
        $this->personas   = new PersonaModel();
        $this->industries = new IndustryModel();
        $this->markets    = new MarketModel();
        $this->intents    = new SearchIntentModel();
        $this->claims     = new ProductClaimModel();
        $this->evidence   = new EvidenceModel();
        $this->sources    = new SourceModel();
        $this->brandRules = new BrandRuleModel();
        $this->policies   = new ContentPolicyModel();
    }

    /**
     * Full product grounding context by slug.
     * Returns null (not 404) when the product does not exist or is not approved,
     * to avoid leaking draft existence.
     */
    public function forProduct(string $slug): ?array
    {
        $product = $this->products->findApprovedBySlug($slug);
        if ($product === null) {
            return null;
        }

        $productId = (int) $product['id'];

        $modules = $this->modules->forProduct($productId, true);
        $moduleData = [];
        foreach ($modules as $module) {
            $moduleFeatures = $this->features->forModule((int) $module['id'], true);
            $moduleData[]   = [
                'id'          => $module['id'],
                'slug'        => $module['slug'],
                'name'        => $module['name'],
                'description' => $module['description'],
                'sort_order'  => $module['sort_order'],
                'features'    => array_map(
                    fn($f) => $this->sanitizeFeature($f),
                    $moduleFeatures
                ),
            ];
        }

        $claims = $this->claims->forProductApproved($productId);
        $claimData = [];
        foreach ($claims as $claim) {
            $ev = $this->evidence->forClaim((int) $claim['id'], true);
            $claimData[] = [
                'id'               => $claim['id'],
                'claim_summary'    => $claim['claim_summary'],
                'risk_level'       => $claim['risk_level'],
                'valid_from'       => $claim['valid_from'],
                'valid_until'      => $claim['valid_until'],
                'evidence_count'   => count($ev),
                'evidence'         => array_map(fn($e) => $this->sanitizeEvidence($e), $ev),
            ];
        }

        return [
            'product'         => $this->sanitizeProduct($product),
            'modules'         => $moduleData,
            'personas'        => $this->personas->forProduct($productId, true),
            'industries'      => array_map(fn($i) => $this->sanitizeSimple($i), $this->industries->forProduct($productId) ?? []),
            'markets'         => array_map(fn($m) => $this->sanitizeSimple($m), $this->markets->forProduct($productId, true)),
            'claims'          => $claimData,
            'brand_rules'     => array_map(fn($r) => $this->sanitizeBrandRule($r), $this->brandRules->forProduct($productId, true)),
            'search_intents'  => array_map(fn($i) => $this->sanitizeIntent($i), $this->intents->forProduct($productId, true)),
        ];
    }

    /**
     * Grounding context for a search intent by ID.
     */
    public function forIntent(int $id): ?array
    {
        $intent = $this->intents->find($id);
        if ($intent === null || $intent['status'] !== 'approved') {
            return null;
        }
        return $this->sanitizeIntent($intent);
    }

    /**
     * Multi-entity context assembly for AI grounding requests.
     * Accepts a payload specifying which product slugs / intent IDs to include.
     * Returns approved data only; no internal notes; no secrets.
     */
    public function assembleContext(array $request): array
    {
        $context = [
            'products'         => [],
            'content_policies' => [],
            'generated_at'     => gmdate('c'),
        ];

        if (! empty($request['product_slugs'])) {
            foreach ((array) $request['product_slugs'] as $slug) {
                $grounding = $this->forProduct((string) $slug);
                if ($grounding !== null) {
                    $context['products'][] = $grounding;
                }
            }
        }

        // Always include active global content policies
        $context['content_policies'] = array_map(
            fn($p) => $this->sanitizePolicy($p),
            $this->policies->findApproved()
        );

        return $context;
    }

    // ── Private sanitizers — strip internal fields ──────────────────────────

    private function sanitizeProduct(array $row): array
    {
        return [
            'id'                => $row['id'],
            'slug'              => $row['slug'],
            'name'              => $row['name'],
            'short_description' => $row['short_description'],
            'description'       => $row['description'],
            'public_url'        => $row['public_url'],
            'status'            => $row['status'],
        ];
    }

    private function sanitizeFeature(array $row): array
    {
        return [
            'id'                 => $row['id'],
            'slug'               => $row['slug'],
            'name'               => $row['name'],
            'description'        => $row['description'],
            'availability'       => $row['availability'],
            'availability_notes' => $row['availability_notes'],
            'is_available'       => $row['availability'] === 'available',
            'is_planned'         => $row['availability'] === 'planned',
            'is_beta'            => $row['availability'] === 'beta',
            'is_limited'         => $row['availability'] === 'limited',
            'is_deprecated'      => $row['availability'] === 'deprecated',
        ];
    }

    private function sanitizeEvidence(array $row): array
    {
        return [
            'id'            => $row['id'],
            'slug'          => $row['slug'],
            'title'         => $row['title'],
            'summary'       => $row['summary'],
            'evidence_type' => $row['evidence_type'],
            'external_url'  => $row['external_url'],
            'valid_from'    => $row['valid_from'],
            'valid_until'   => $row['valid_until'],
            'is_expired'    => $this->evidence->isExpired($row),
        ];
    }

    private function sanitizeBrandRule(array $row): array
    {
        return [
            'id'           => $row['id'],
            'rule_type'    => $row['rule_type'],
            'rule_text'    => $row['rule_text'],
            'applies_to'   => $row['applies_to'],
            'is_mandatory' => $row['is_mandatory'],
        ];
    }

    private function sanitizeIntent(array $row): array
    {
        return [
            'id'            => $row['id'],
            'slug'          => $row['slug'],
            'intent_text'   => $row['intent_text'],
            'intent_type'   => $row['intent_type'],
            'funnel_stage'  => $row['funnel_stage'],
            'search_volume' => $row['search_volume'],
        ];
    }

    private function sanitizePolicy(array $row): array
    {
        return [
            'id'                  => $row['id'],
            'name'                => $row['name'],
            'policy_type'         => $row['policy_type'],
            'policy_text'         => $row['policy_text'],
            'applies_to_channels' => $row['applies_to_channels'],
            'is_mandatory'        => $row['is_mandatory'],
        ];
    }

    private function sanitizeSimple(array $row): array
    {
        return array_diff_key($row, array_flip([
            'internal_notes', 'reviewed_by', 'reviewed_at',
            'approved_by', 'approved_at', 'created_actor_type',
            'created_by_service', 'generation_job_id', 'request_id',
            'created_by', 'updated_by', 'deleted_at',
        ]));
    }

    private function forIndustry(int $productId): array
    {
        return $this->industries->forProduct($productId) ?? [];
    }
}
