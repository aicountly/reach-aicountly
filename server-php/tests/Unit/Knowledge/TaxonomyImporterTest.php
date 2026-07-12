<?php

namespace Tests\Unit\Knowledge;

use App\Libraries\KnowledgeTaxonomyImporter;
use App\Libraries\SaasProductTaxonomy;
use App\Models\Knowledge\ProductModel;
use App\Models\Knowledge\ProductAliasModel;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for KnowledgeTaxonomyImporter using stateful in-memory mocks.
 * No database required.
 */
final class TaxonomyImporterTest extends TestCase
{
    /**
     * A fresh import on an empty store creates every product slug.
     */
    public function testFirstRunCreatesAllProducts(): void
    {
        $store = [];
        [$productModel, $aliasModel] = $this->makeStatefulModels($store);

        $importer = new KnowledgeTaxonomyImporter($productModel, $aliasModel);
        $result   = $importer->run(null);

        $expectedCount = count(SaasProductTaxonomy::products());
        $this->assertCount($expectedCount, $result['created'],
            'Every product slug should be created on first run');
        // Product errors (not alias errors) must be absent
        $productErrors = array_filter($result['errors'], fn($e) => isset($e['slug']));
        $this->assertEmpty($productErrors, 'No product-level errors expected');
    }

    /**
     * A second run on a fully-imported store creates nothing.
     */
    public function testSecondRunCreatesNothing(): void
    {
        $store = $this->buildExistingProducts('needs_review');
        [$productModel, $aliasModel] = $this->makeStatefulModels($store);

        $importer = new KnowledgeTaxonomyImporter($productModel, $aliasModel);
        $result   = $importer->run(null);

        $this->assertEmpty($result['created'],
            'Second run should not create any new products');
        $productErrors = array_filter($result['errors'], fn($e) => isset($e['slug']));
        $this->assertEmpty($productErrors);
    }

    /**
     * Approved products must never be overwritten on any run.
     */
    public function testApprovedProductsAreNotOverwritten(): void
    {
        $store = $this->buildExistingProducts('approved');
        [$productModel, $aliasModel] = $this->makeStatefulModels($store);

        $importer = new KnowledgeTaxonomyImporter($productModel, $aliasModel);
        $result   = $importer->run(null);

        $this->assertEmpty($result['created'],
            'No product should be created when all are already approved');
        foreach ($result['skipped'] as $skipped) {
            $this->assertSame('already_approved', $skipped['reason']);
        }
    }

    /**
     * Aliases for known canonical slugs are created on first run.
     */
    public function testAliasesAreCreatedForKnownCanonicals(): void
    {
        $store = $this->buildExistingProducts('needs_review');
        [$productModel, $aliasModel] = $this->makeStatefulModels($store);

        $importer = new KnowledgeTaxonomyImporter($productModel, $aliasModel);
        $result   = $importer->run(null);

        $validAliases = array_filter(
            SaasProductTaxonomy::productAliases(),
            fn($canonical) => array_key_exists($canonical, SaasProductTaxonomy::products())
        );

        $createdAliases = array_filter($result['aliases'], fn($a) => ($a['created'] ?? false) === true);
        $this->assertCount(count($validAliases), $createdAliases,
            'All aliases pointing to valid canonical slugs should be created');
    }

    /**
     * Aliases for unknown canonical slugs produce errors, not silently succeed.
     */
    public function testAliasWithUnknownCanonicalProducesError(): void
    {
        $store = $this->buildExistingProducts('needs_review');
        [$productModel, $aliasModel] = $this->makeStatefulModels($store);

        $importer = new KnowledgeTaxonomyImporter($productModel, $aliasModel);
        $result   = $importer->run(null);

        $invalidAliases = array_filter(
            SaasProductTaxonomy::productAliases(),
            fn($canonical) => ! array_key_exists($canonical, SaasProductTaxonomy::products())
        );

        if (! empty($invalidAliases)) {
            $aliasErrors = array_filter($result['errors'], fn($e) => isset($e['alias']));
            $this->assertCount(count($invalidAliases), $aliasErrors,
                'Each alias with an unknown canonical should produce exactly one error');
        } else {
            $this->markTestSkipped('No invalid aliases in legacy taxonomy — test not applicable');
        }
    }

    /**
     * The import must never seed features, pricing, compliance, or comparisons.
     */
    public function testNoForbiddenDataIsSeeded(): void
    {
        $store = [];
        [$productModel, $aliasModel] = $this->makeStatefulModels($store);
        $importer = new KnowledgeTaxonomyImporter($productModel, $aliasModel);
        $result   = $importer->run(null);

        foreach ($result['created'] as $item) {
            $this->assertArrayNotHasKey('features',   $item);
            $this->assertArrayNotHasKey('pricing',    $item);
            $this->assertArrayNotHasKey('compliance', $item);
            $this->assertArrayNotHasKey('claims',     $item);
        }
    }

    /**
     * The status of every newly imported product must be 'needs_review', never 'approved'.
     */
    public function testImportedStatusIsNeedsReview(): void
    {
        $store = [];
        [$productModel, $aliasModel] = $this->makeStatefulModels($store);
        $importer = new KnowledgeTaxonomyImporter($productModel, $aliasModel);
        $importer->run(null);

        foreach ($store as $slug => $data) {
            $this->assertSame('needs_review', $data['status'] ?? null,
                "Product '$slug' must be imported with status 'needs_review'");
        }
    }

    /**
     * Running the importer twice produces the same store as running once.
     */
    public function testImportIsIdempotent(): void
    {
        $store = [];
        [$productModel, $aliasModel] = $this->makeStatefulModels($store);

        $importer = new KnowledgeTaxonomyImporter($productModel, $aliasModel);
        $first  = $importer->run(null);
        $second = $importer->run(null);

        $this->assertCount(0, $second['created'],
            'Second run must create nothing');
        $this->assertCount(count($first['created']), $second['skipped'],
            'Everything created on first run should be skipped on second run');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Creates mock models backed by a shared in-memory $store array.
     * Inserts are reflected in $store so subsequent findBySlug calls succeed.
     */
    private function makeStatefulModels(array &$store): array
    {
        $nextId = 1;

        $productModel = $this->createPartialMock(ProductModel::class, ['findBySlug', 'insert', 'update']);
        $productModel->method('findBySlug')->willReturnCallback(
            function ($slug) use (&$store) {
                return $store[$slug] ?? null;
            }
        );
        $productModel->method('insert')->willReturnCallback(
            function ($data) use (&$store, &$nextId) {
                $store[$data['slug']] = array_merge($data, ['id' => $nextId++]);
                return true;
            }
        );
        $productModel->method('update')->willReturn(true);

        $aliasStore   = [];
        $aliasModel   = $this->createPartialMock(ProductAliasModel::class, ['aliasExists', 'insert']);
        $aliasModel->method('aliasExists')->willReturnCallback(
            fn($alias, $productId) => in_array($alias, $aliasStore, true)
        );
        $aliasModel->method('insert')->willReturnCallback(
            function ($data) use (&$aliasStore) {
                $aliasStore[] = $data['alias'];
                return true;
            }
        );

        return [$productModel, $aliasModel];
    }

    private function buildExistingProducts(string $status): array
    {
        $out = [];
        $i   = 1;
        foreach (SaasProductTaxonomy::products() as $slug => $label) {
            $out[$slug] = [
                'id'               => $i++,
                'slug'             => $slug,
                'name'             => $label,
                'status'           => $status,
                'legacy_code_path' => 'App\\Libraries\\SaasProductTaxonomy::products()',
            ];
        }
        return $out;
    }
}
