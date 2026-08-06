<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Permanent deletion of a content item: DELETE /v1/content/items/:id/purge.
 *
 * The archive endpoint (DELETE without /purge) only flips workflow_status;
 * this route removes the row and everything hanging off it, including the
 * child tables Postgres refuses to cascade.
 */
final class ContentPurgeTest extends ApiTestCase
{
    private function createItem(array $headers, string $title = 'Purge target'): int
    {
        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => $title,
            'content_type' => 'blog',
        ]);
        $this->assertSame(201, $res->response()->getStatusCode());
        $body = json_decode((string) $res->getJSON(), true);

        return (int) $body['data']['id'];
    }

    public function testPurgeRemovesTheItemAndItsVersions(): void
    {
        $headers = $this->authAs('super_admin');
        $id      = $this->createItem($headers);
        $db      = \Config\Database::connect();

        $this->assertGreaterThan(
            0,
            $db->table('reach_content_versions')->where('content_item_id', $id)->countAllResults(),
            'the create endpoint should have written an initial version'
        );

        $res = $this->withHeaders($headers)->call('DELETE', 'v1/content/items/' . $id . '/purge');
        $this->assertSame(200, $res->response()->getStatusCode());

        $body = json_decode((string) $res->getJSON(), true);
        $this->assertTrue($body['ok']);
        $this->assertTrue($body['data']['deleted']);

        $this->assertSame(0, $db->table('reach_content_items')->where('id', $id)->countAllResults());
        $this->assertSame(0, $db->table('reach_content_versions')->where('content_item_id', $id)->countAllResults());
    }

    public function testPurgeClearsChildRowsThatWouldBlockTheDelete(): void
    {
        $headers = $this->authAs('super_admin');
        $id      = $this->createItem($headers, 'Purge with blocking children');
        $db      = \Config\Database::connect();

        $versionId = (int) $db->table('reach_content_versions')
            ->where('content_item_id', $id)
            ->get()->getRowArray()['id'];

        // reach_ai_validation_runs.content_item_id is NOT NULL with no cascade,
        // and its findings hang off the run — both must be cleared for us.
        $db->table('reach_ai_validation_runs')->insert([
            'content_item_id'    => $id,
            'content_version_id' => $versionId,
            'status'             => 'completed',
        ]);
        $runId = (int) $db->insertID();
        $db->table('reach_ai_validation_findings')->insert([
            'validation_run_id' => $runId,
            'validator_type'    => 'word_count',
            'severity'          => 'info',
            'status'            => 'passed',
        ]);

        // Same story for similarity records, on both sides of the pair.
        $otherId = $this->createItem($headers, 'Similarity neighbour');
        $db->table('reach_content_similarity_records')->insert([
            'content_item_id'          => $id,
            'content_version_id'       => $versionId,
            'compared_item_id'         => $otherId,
            'overall_similarity_score' => 0.42,
        ]);
        $db->table('reach_content_similarity_records')->insert([
            'content_item_id'          => $otherId,
            'content_version_id'       => $versionId,
            'compared_item_id'         => $id,
            'overall_similarity_score' => 0.42,
        ]);

        $res = $this->withHeaders($headers)->call('DELETE', 'v1/content/items/' . $id . '/purge');
        $this->assertSame(200, $res->response()->getStatusCode());

        $this->assertSame(0, $db->table('reach_content_items')->where('id', $id)->countAllResults());
        $this->assertSame(0, $db->table('reach_ai_validation_runs')->where('content_item_id', $id)->countAllResults());
        $this->assertSame(0, $db->table('reach_ai_validation_findings')->where('validation_run_id', $runId)->countAllResults());
        $this->assertSame(0, $db->table('reach_content_similarity_records')->where('content_item_id', $id)->countAllResults());

        // The neighbour survives with its pointer cleared, not deleted with it.
        $neighbour = $db->table('reach_content_similarity_records')
            ->where('content_item_id', $otherId)
            ->get()->getRowArray();
        $this->assertNotNull($neighbour);
        $this->assertNull($neighbour['compared_item_id']);
    }

    public function testPurgeWritesAnAuditRecord(): void
    {
        $headers = $this->authAs('super_admin');
        $id      = $this->createItem($headers, 'Audited purge');

        $this->withHeaders($headers)->call('DELETE', 'v1/content/items/' . $id . '/purge');

        $audit = \Config\Database::connect()->table('reach_audit_logs')
            ->where('action', 'content.deleted')
            ->where('entity_id', $id)
            ->get()->getRowArray();

        $this->assertNotNull($audit, 'a permanent delete must leave an audit trail');
    }

    public function testPurgeIsDeniedWithoutContentDelete(): void
    {
        $adminHeaders = $this->authAs('super_admin');
        $id           = $this->createItem($adminHeaders, 'Not yours to delete');

        $viewer = $this->authAs('viewer');
        $res    = $this->withHeaders($viewer)->call('DELETE', 'v1/content/items/' . $id . '/purge');

        $this->assertSame(403, $res->response()->getStatusCode());
        $this->assertSame(
            1,
            \Config\Database::connect()->table('reach_content_items')->where('id', $id)->countAllResults()
        );
    }

    public function testPurgeReturns404ForAnUnknownItem(): void
    {
        $headers = $this->authAs('super_admin');
        $res     = $this->withHeaders($headers)->call('DELETE', 'v1/content/items/99999999/purge');

        $this->assertSame(404, $res->response()->getStatusCode());
    }
}
