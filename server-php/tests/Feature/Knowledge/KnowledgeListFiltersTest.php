<?php

namespace Tests\Feature\Knowledge;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * A product's "Claims (N)" and a source's citations drill into the claim,
 * brand-rule and citation lists by product_id / source_id, and those lists
 * keep their search in the URL. The lists must honour all of it.
 */
final class KnowledgeListFiltersTest extends ApiTestCase
{
    private function listing(string $path): array
    {
        $response = $this->withHeaders($this->authAs('reach_admin'))->call('GET', $path);
        $this->assertSame(200, $response->response()->getStatusCode(), $path);

        return json_decode($response->response()->getBody(), true)['data'] ?? [];
    }

    private function product(string $slug): int
    {
        $db = Database::connect();
        $db->table('reach_products')->insert(['name' => $slug, 'slug' => $slug, 'status' => 'draft']);

        return (int) $db->insertID();
    }

    public function testClaimsFilterByProductAndSearchTheClaimText(): void
    {
        $a  = $this->product('alpha');
        $b  = $this->product('beta');
        $db = Database::connect();
        $db->table('reach_product_claims')->insertBatch([
            ['product_id' => $a, 'claim_text' => 'Files GST returns automatically', 'status' => 'draft'],
            ['product_id' => $a, 'claim_text' => 'Reconciles bank statements', 'status' => 'draft'],
            ['product_id' => $b, 'claim_text' => 'Files GST returns too', 'status' => 'draft'],
        ]);

        $this->assertSame(2, $this->listing("v1/knowledge/claims?product_id={$a}")['total']);

        // claim_summary is empty here: the search must reach claim_text.
        $hit = $this->listing("v1/knowledge/claims?product_id={$a}&search=gst");
        $this->assertSame(1, $hit['total']);
        $this->assertSame('Files GST returns automatically', $hit['items'][0]['claim_text']);
    }

    public function testBrandRulesFilterByProductAndSearch(): void
    {
        $a  = $this->product('alpha');
        $db = Database::connect();
        $db->table('reach_brand_rules')->insertBatch([
            ['product_id' => $a, 'rule_type' => 'tone', 'rule_text' => 'Keep it plain', 'status' => 'draft'],
            ['product_id' => $a, 'rule_type' => 'avoid_term', 'rule_text' => 'Never say cheap', 'status' => 'draft'],
            ['product_id' => null, 'rule_type' => 'tone', 'rule_text' => 'Global plain rule', 'status' => 'draft'],
        ]);

        $this->assertSame(2, $this->listing("v1/knowledge/brand-rules?product_id={$a}")['total']);
        $this->assertSame(1, $this->listing("v1/knowledge/brand-rules?product_id={$a}&search=PLAIN")['total']);
    }

    public function testCitationsFilterBySourceAndSearch(): void
    {
        $db = Database::connect();
        $db->table('reach_sources')->insert(['slug' => 'cbic', 'name' => 'CBIC']);
        $source = (int) $db->insertID();
        $db->table('reach_sources')->insert(['slug' => 'other', 'name' => 'Other']);
        $other = (int) $db->insertID();
        $db->table('reach_citations')->insertBatch([
            ['source_id' => $source, 'citation_text' => 'Section 16 input tax credit', 'status' => 'draft'],
            ['source_id' => $source, 'citation_text' => 'Rule 36 reversal', 'status' => 'draft'],
            ['source_id' => $other, 'citation_text' => 'Section 16 elsewhere', 'status' => 'draft'],
        ]);

        $this->assertSame(2, $this->listing("v1/knowledge/citations?source_id={$source}")['total']);
        $this->assertSame(1, $this->listing("v1/knowledge/citations?source_id={$source}&search=section")['total']);
    }
}
