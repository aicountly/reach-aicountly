<?php

namespace Tests\Feature\Knowledge;

use App\Libraries\SaasProductTaxonomy;
use Tests\Support\ApiTestCase;

/**
 * End-to-end cover for the Knowledge → Products listing.
 *
 * The listing shipped permanently empty: nothing seeded reach_products in a
 * deployed environment and the UI had no way to create or import products.
 * These tests pin the two paths that fill it (import + create), the search
 * filter the web client actually sends, and the permission boundary.
 */
final class ProductCatalogTest extends ApiTestCase
{
    private function listProducts(array $headers, string $query = ''): array
    {
        $response = $this->withHeaders($headers)->call('GET', 'v1/knowledge/products' . $query);
        $this->assertSame(200, $response->response()->getStatusCode());
        return json_decode($response->response()->getBody(), true)['data'] ?? [];
    }

    public function testTaxonomyImportFillsTheEmptyListing(): void
    {
        $headers = $this->authAs('reach_admin');

        $before = $this->listProducts($headers);
        $this->assertSame(0, $before['total'], 'Fresh install starts with an empty catalogue');
        $this->assertSame([], $before['items']);

        $import = $this->withHeaders($headers)->call('POST', 'v1/knowledge/products/import-taxonomy');
        $this->assertSame(200, $import->response()->getStatusCode());
        $summary = json_decode($import->response()->getBody(), true)['data'];

        $expected = count(SaasProductTaxonomy::products());
        $this->assertSame($expected, $summary['created']);

        // Alias rows may legitimately report errors (the taxonomy carries a
        // dangling `manage` → `manage_account` alias); product rows may not.
        $productErrors = array_filter($summary['error_items'], static fn ($e) => isset($e['slug']));
        $this->assertSame([], $productErrors, 'No product should fail to import');

        $after = $this->listProducts($headers);
        $this->assertSame($expected, $after['total'], 'Imported products must show up in the listing');
        $this->assertNotEmpty($after['items']);

        // Nothing may be auto-approved — grounding must still wait for a human.
        foreach ($after['items'] as $item) {
            $this->assertSame('needs_review', $item['status']);
        }
    }

    public function testTaxonomyImportIsIdempotent(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->withHeaders($headers)->call('POST', 'v1/knowledge/products/import-taxonomy');
        $second = $this->withHeaders($headers)->call('POST', 'v1/knowledge/products/import-taxonomy');
        $summary = json_decode($second->response()->getBody(), true)['data'];

        $this->assertSame(0, $summary['created'], 'Re-running the import must not duplicate products');
        $this->assertGreaterThan(0, $summary['skipped']);

        $listing = $this->listProducts($headers);
        $this->assertSame(count(SaasProductTaxonomy::products()), $listing['total']);
    }

    public function testCreatedProductAppearsInTheListing(): void
    {
        $headers = $this->authAs('reach_admin');

        $create = $this->withHeaders($headers)->call('POST', 'v1/knowledge/products', [
            'name'              => 'Reach Marketing Portal',
            'short_description' => 'Marketing operations portal',
        ]);
        $this->assertSame(201, $create->response()->getStatusCode());
        $created = json_decode($create->response()->getBody(), true)['data'];
        $this->assertSame('draft', $created['status']);

        $listing = $this->listProducts($headers);
        $this->assertSame(1, $listing['total']);
        $this->assertSame('Reach Marketing Portal', $listing['items'][0]['name']);
        $this->assertNotEmpty($listing['items'][0]['slug']);
    }

    public function testListingAcceptsBothSearchAndQParameters(): void
    {
        $headers = $this->authAs('reach_admin');

        foreach (['Alpha Analytics', 'Beta Billing'] as $name) {
            $this->withHeaders($headers)->call('POST', 'v1/knowledge/products', ['name' => $name]);
        }
        $this->assertSame(2, $this->listProducts($headers)['total']);

        // `search` is what the web client sends; `q` is the canonical name.
        $bySearch = $this->listProducts($headers, '?search=Alpha');
        $this->assertSame(1, $bySearch['total']);
        $this->assertSame('Alpha Analytics', $bySearch['items'][0]['name']);

        $byQ = $this->listProducts($headers, '?q=Alpha');
        $this->assertSame(1, $byQ['total']);
        $this->assertSame('Alpha Analytics', $byQ['items'][0]['name']);

        $this->assertSame(0, $this->listProducts($headers, '?search=Gamma')['total']);
    }

    public function testStatusFilterNarrowsTheListing(): void
    {
        $headers = $this->authAs('reach_admin');
        $this->withHeaders($headers)->call('POST', 'v1/knowledge/products', ['name' => 'Draft Product']);
        $this->withHeaders($headers)->call('POST', 'v1/knowledge/products/import-taxonomy');

        $this->assertSame(1, $this->listProducts($headers, '?status=draft')['total']);
        $this->assertSame(
            count(SaasProductTaxonomy::products()),
            $this->listProducts($headers, '?status=needs_review')['total'],
        );
    }

    public function testViewerCanReadButCannotImport(): void
    {
        $headers = $this->authAs('knowledge_viewer');

        $this->assertSame(0, $this->listProducts($headers)['total']);

        $import = $this->withHeaders($headers)->call('POST', 'v1/knowledge/products/import-taxonomy');
        $this->assertSame(403, $import->response()->getStatusCode());
        $this->assertSame(0, $this->listProducts($headers)['total'], 'A denied import must not write anything');
    }
}
