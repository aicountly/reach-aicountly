<?php

namespace App\Libraries;

use App\Models\Knowledge\ProductModel;
use App\Models\Knowledge\ProductAliasModel;

/**
 * Idempotent importer from the hardcoded SaasProductTaxonomy into the
 * knowledge store (reach_products + reach_product_aliases).
 *
 * Safety guarantees:
 *   - Will not overwrite an existing product whose status is 'approved'.
 *   - Will not approve any product record automatically.
 *   - Can be re-run any number of times without creating duplicates.
 *   - Does not seed features, pricing, compliance, integrations, or claims.
 *   - Records the legacy code path as evidence on each imported product.
 *
 * Deprecation roadmap:
 *   - Phase 1: DB store is seeded; SaasProductTaxonomy::products() remains
 *     the live source for existing callers (analytics, GA4).
 *   - Phase 2: Callers progressively switch to ProductModel::findBySlug().
 *   - Phase 3: SaasProductTaxonomy::products() becomes a thin DB-backed
 *     façade that reads from reach_products where status = 'approved'.
 *   - Phase 4: SaasProductTaxonomy.php is retired once all callers migrated.
 */
class KnowledgeTaxonomyImporter
{
    private const LEGACY_CODE_PATH = 'App\\Libraries\\SaasProductTaxonomy::products()';

    private ProductModel      $productModel;
    private ProductAliasModel $aliasModel;
    private array             $result = [
        'created'  => [],
        'skipped'  => [],
        'aliases'  => [],
        'errors'   => [],
    ];

    public function __construct(
        ?ProductModel $productModel = null,
        ?ProductAliasModel $aliasModel = null
    ) {
        $this->productModel = $productModel ?? new ProductModel();
        $this->aliasModel   = $aliasModel   ?? new ProductAliasModel();
    }

    /**
     * Run the idempotent import. Returns a result summary.
     *
     * @param int|null $actorUserId User ID to record as creator (null = system import)
     */
    public function run(?int $actorUserId = null): array
    {
        $this->result = ['created' => [], 'skipped' => [], 'aliases' => [], 'errors' => []];

        $products = SaasProductTaxonomy::products();
        $aliases  = SaasProductTaxonomy::productAliases();
        $urls     = $this->buildUrlMap();

        foreach ($products as $slug => $label) {
            try {
                $this->importProduct($slug, $label, $urls[$slug] ?? null, $actorUserId);
            } catch (\Throwable $e) {
                $this->result['errors'][] = [
                    'slug'    => $slug,
                    'message' => $e->getMessage(),
                ];
            }
        }

        foreach ($aliases as $alias => $canonicalSlug) {
            try {
                $this->importAlias($alias, $canonicalSlug, $actorUserId);
            } catch (\Throwable $e) {
                $this->result['errors'][] = [
                    'alias'   => $alias,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $this->result;
    }

    /**
     * Import a single product slug. Idempotent.
     */
    private function importProduct(string $slug, string $label, ?string $url, ?int $actorUserId): void
    {
        $existing = $this->productModel->findBySlug($slug);

        if ($existing !== null) {
            // Never overwrite an approved product
            if ($existing['status'] === 'approved') {
                $this->result['skipped'][] = ['slug' => $slug, 'reason' => 'already_approved'];
                return;
            }
            // Update only non-approved records to fill in missing data
            if (empty($existing['legacy_code_path'])) {
                $this->productModel->update($existing['id'], [
                    'legacy_code_path' => self::LEGACY_CODE_PATH,
                    'updated_by'       => $actorUserId,
                ]);
            }
            $this->result['skipped'][] = ['slug' => $slug, 'reason' => 'already_exists'];
            return;
        }

        $this->productModel->insert([
            'slug'             => $slug,
            'name'             => $label,
            'short_description'=> null,
            'description'      => null,
            'public_url'       => $url,
            'status'           => 'needs_review',
            'legacy_code_path' => self::LEGACY_CODE_PATH,
            'created_by'       => $actorUserId,
            'created_actor_type' => $actorUserId !== null ? 'human' : 'system',
            'created_by_service' => $actorUserId !== null ? null : 'KnowledgeTaxonomyImporter',
        ]);

        $this->result['created'][] = ['slug' => $slug, 'name' => $label];
    }

    /**
     * Import a legacy alias for an existing product. Idempotent.
     */
    private function importAlias(string $alias, string $canonicalSlug, ?int $actorUserId): void
    {
        $product = $this->productModel->findBySlug($canonicalSlug);
        if ($product === null) {
            $this->result['errors'][] = [
                'alias'   => $alias,
                'message' => "Canonical slug '$canonicalSlug' not found in DB",
            ];
            return;
        }

        if ($this->aliasModel->aliasExists($alias, (int) $product['id'])) {
            $this->result['aliases'][] = ['alias' => $alias, 'reason' => 'already_exists'];
            return;
        }

        $this->aliasModel->insert([
            'product_id' => $product['id'],
            'alias'      => $alias,
            'source'     => 'legacy_code',
            'created_by' => $actorUserId,
        ]);
        $this->result['aliases'][] = ['alias' => $alias, 'canonical' => $canonicalSlug, 'created' => true];
    }

    /**
     * Build a map of slug → dedicated site URL from SaasProductTaxonomy.
     */
    private function buildUrlMap(): array
    {
        $map = [];
        foreach (array_keys(SaasProductTaxonomy::products()) as $slug) {
            $url = SaasProductTaxonomy::productDedicatedSiteUrl($slug);
            if ($url !== null) {
                $map[$slug] = $url;
            }
        }
        return $map;
    }
}
