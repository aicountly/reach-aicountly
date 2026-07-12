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

/**
 * Calculates a knowledge completeness score for a product.
 *
 * A product is NOT considered AI-ready solely because it has a name and
 * description. All dimensions must pass minimum thresholds.
 */
class KnowledgeCompletenessService
{
    /** Weights must sum to 100. */
    private const WEIGHTS = [
        'identity'        => 10,
        'modules'         => 10,
        'features'        => 10,
        'personas'        => 8,
        'industries'      => 8,
        'markets'         => 6,
        'problems'        => 8,
        'search_intents'  => 10,
        'claims'          => 10,
        'evidence'        => 10,
        'sources'         => 5,
        'brand_rules'     => 5,
    ];

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
    }

    /**
     * Calculate completeness for a single product (by ID or slug).
     */
    public function forProduct(int $productId): array
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return ['error' => 'Product not found'];
        }
        return $this->calculate($product);
    }

    public function forProductSlug(string $slug): array
    {
        $product = $this->products->findBySlug($slug);
        if ($product === null) {
            return ['error' => 'Product not found'];
        }
        return $this->calculate($product);
    }

    /**
     * Summary completeness scores for all products.
     */
    public function summaryAll(): array
    {
        $products = $this->products->withDeleted(false)->findAll();
        $summaries = [];
        foreach ($products as $product) {
            $score = $this->calculate($product);
            $summaries[] = [
                'product_id'   => $product['id'],
                'slug'         => $product['slug'],
                'name'         => $product['name'],
                'status'       => $product['status'],
                'score'        => $score['score'],
                'ai_ready'     => $score['ai_ready'],
                'gap_count'    => count($score['missing']),
            ];
        }
        usort($summaries, fn($a, $b) => $a['score'] <=> $b['score']);
        return $summaries;
    }

    // ── Protected (overrideable in tests) ────────────────────────────────────

    public function calculate(array $product): array
    {
        $id      = (int) $product['id'];
        $missing = [];
        $warnings = [];
        $scores  = [];

        // 1. Identity
        $identityScore = 0;
        if (! empty($product['name'])) {
            $identityScore += 40;
        }
        if (! empty($product['description'])) {
            $identityScore += 30;
        }
        if (! empty($product['public_url'])) {
            $identityScore += 20;
        }
        if ($product['status'] === 'approved') {
            $identityScore += 10;
        }
        if ($identityScore < 100) {
            $missing[] = 'incomplete_identity';
        }
        $scores['identity'] = $identityScore;

        // 2. Modules
        $modules = $this->modulesForProduct($id);
        $modScore = min(100, count($modules) * 25);
        if (count($modules) === 0) {
            $missing[] = 'no_modules';
        }
        $scores['modules'] = $modScore;

        // 3. Features
        $featureCount = 0;
        $availCounts  = $this->featureAvailabilityCountsForProduct($id);
        foreach ($availCounts as $count) {
            $featureCount += $count;
        }
        // Warn about planned features — they must never be represented as available
        if (! empty($availCounts['planned'])) {
            $warnings[] = 'has_planned_features';
        }
        $featScore = min(100, $featureCount * 10);
        if ($featureCount === 0) {
            $missing[] = 'no_features';
        }
        $scores['features'] = $featScore;

        // 4. Personas
        $personaCount = count($this->personasForProduct($id));
        $persScore    = min(100, $personaCount * 34);
        if ($personaCount === 0) {
            $missing[] = 'no_personas';
        }
        $scores['personas'] = $persScore;

        // 5. Industries
        $industryIds = $this->industriesForProduct($id);
        $indScore    = min(100, count($industryIds) * 34);
        if (empty($industryIds)) {
            $missing[] = 'no_industries';
        }
        $scores['industries'] = $indScore;

        // 6. Markets
        $marketIds  = $this->marketsForProduct($id);
        $mktScore   = min(100, count($marketIds) * 50);
        if (empty($marketIds)) {
            $missing[] = 'no_markets';
        }
        $scores['markets'] = $mktScore;

        // 7. Business problems
        $problemIds = $this->problemsForProduct($id);
        $probScore  = min(100, count($problemIds) * 34);
        if (empty($problemIds)) {
            $missing[] = 'no_business_problems';
        }
        $scores['problems'] = $probScore;

        // 8. Search intents
        $intentCount = count($this->intentsForProduct($id));
        $intScore    = min(100, $intentCount * 10);
        if ($intentCount < 3) {
            $missing[] = 'insufficient_search_intents';
        }
        $scores['search_intents'] = $intScore;

        // 9. Claims
        $allClaims    = $this->claimsForProduct($id);
        $approvedClaims = array_filter($allClaims, fn($c) => $c['status'] === 'approved');
        $unsupportedClaims = [];
        $expiredEvidence   = [];

        foreach ($approvedClaims as $claim) {
            if ($claim['requires_evidence']) {
                $evCount = $this->approvedEvidenceCountForClaim((int) $claim['id']);
                if ($evCount === 0) {
                    $unsupportedClaims[] = [
                        'id'           => $claim['id'],
                        'claim_summary' => $claim['claim_summary'],
                        'risk_level'   => $claim['risk_level'],
                    ];
                }
            }
        }
        $claimScore = count($allClaims) > 0 ? min(100, count($approvedClaims) * 25) : 0;
        if (count($allClaims) === 0) {
            $missing[] = 'no_claims';
        }
        if (! empty($unsupportedClaims)) {
            $warnings[] = 'unsupported_approved_claims';
        }
        $scores['claims'] = $claimScore;

        // 10. Evidence
        $evidenceRows  = $this->evidenceForProduct($id);
        $approvedEv    = array_filter($evidenceRows, fn($e) => $e['status'] === 'approved');
        $expiredEv     = array_filter($approvedEv, fn($e) => $this->isEvidenceExpired($e));
        $evScore       = min(100, count($approvedEv) * 17);
        if (count($approvedEv) === 0) {
            $missing[] = 'no_evidence';
        }
        if (! empty($expiredEv)) {
            $warnings[] = 'has_expired_evidence';
            foreach ($expiredEv as $e) {
                $expiredEvidence[] = ['id' => $e['id'], 'title' => $e['title']];
            }
        }
        $scores['evidence'] = $evScore;

        // 11. Sources
        $sourceCount = count($this->findApprovedSources());
        $srcScore    = min(100, $sourceCount * 20);
        if ($sourceCount === 0) {
            $missing[] = 'no_verified_sources';
        }
        $scores['sources'] = $srcScore;

        // 12. Brand rules
        $brandRuleCount = count($this->brandRulesForProduct($id));
        $brScore        = min(100, $brandRuleCount * 50);
        if ($brandRuleCount === 0) {
            $missing[] = 'no_brand_rules';
        }
        $scores['brand_rules'] = $brScore;

        // Weighted total
        $total = 0;
        foreach (self::WEIGHTS as $dim => $weight) {
            $total += ($scores[$dim] / 100) * $weight;
        }
        $score = (int) round($total);

        // AI-ready requires ALL dimensions to be above minimum thresholds
        $aiReady = empty($missing)
            && empty($unsupportedClaims)
            && empty($expiredEvidence)
            && $product['status'] === 'approved';

        return [
            'product_id'          => $id,
            'slug'                => $product['slug'],
            'name'                => $product['name'],
            'status'              => $product['status'],
            'score'               => $score,
            'ai_ready'            => $aiReady,
            'dimension_scores'    => $scores,
            'missing'             => $missing,
            'warnings'            => $warnings,
            'unsupported_claims'  => array_values($unsupportedClaims),
            'expired_evidence'    => array_values($expiredEvidence),
            'review_due'          => $product['review_due_at'] ?? null,
            'last_reviewed_at'    => $product['reviewed_at'] ?? null,
        ];
    }

    protected function modulesForProduct(int $productId): array
    {
        return $this->modules->forProduct($productId, true);
    }

    protected function featureAvailabilityCountsForProduct(int $productId): array
    {
        return $this->features->availabilityCountsForProduct($productId);
    }

    protected function personasForProduct(int $productId): array
    {
        return $this->personas->forProduct($productId, true);
    }

    protected function marketsForProduct(int $productId): array
    {
        return $this->markets->forProduct($productId, true);
    }

    protected function intentsForProduct(int $productId): array
    {
        return $this->intents->forProduct($productId, true);
    }

    protected function claimsForProduct(int $productId): array
    {
        return $this->claims->forProduct($productId);
    }

    protected function approvedEvidenceCountForClaim(int $claimId): int
    {
        return $this->claims->approvedEvidenceCount($claimId);
    }

    protected function isEvidenceExpired(array $evidence): bool
    {
        return $this->evidence->isExpired($evidence);
    }

    protected function findApprovedSources(): array
    {
        return $this->sources->findApproved();
    }

    protected function brandRulesForProduct(int $productId): array
    {
        return $this->brandRules->forProduct($productId, true);
    }

    protected function industriesForProduct(int $productId): array
    {
        $rows = \Config\Database::connect()
            ->table('reach_product_industries')
            ->select('industry_id')
            ->where('product_id', $productId)
            ->get()->getResultArray();
        return array_column($rows, 'industry_id');
    }

    protected function problemsForProduct(int $productId): array
    {
        $db = \Config\Database::connect();
        $rows = $db->table('reach_feature_problems fp')
            ->select('fp.problem_id')
            ->join('reach_product_features f', 'f.id = fp.feature_id')
            ->join('reach_product_modules m', 'm.id = f.module_id')
            ->where('m.product_id', $productId)
            ->where('f.deleted_at IS NULL')
            ->get()->getResultArray();
        return array_unique(array_column($rows, 'problem_id'));
    }

    protected function evidenceForProduct(int $productId): array
    {
        $db = \Config\Database::connect();
        $rows = $db->table('reach_product_evidence pe')
            ->select('e.*')
            ->join('reach_evidence e', 'e.id = pe.evidence_id')
            ->where('pe.product_id', $productId)
            ->where('e.deleted_at IS NULL')
            ->get()->getResultArray();
        return $rows;
    }
}
