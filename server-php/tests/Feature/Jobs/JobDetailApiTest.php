<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use Tests\Support\ApiTestCase;

/**
 * GET v1/jobs/{id} for the Job detail page: the job, its request_id siblings
 * and the audit trail (retries/cancels and events the job produced).
 *
 * @internal
 */
final class JobDetailApiTest extends ApiTestCase
{
    private function job(array $over = []): int
    {
        $db = \Config\Database::connect();
        $db->table('reach_jobs')->insert($over + [
            'job_type' => 'reach.test', 'queue' => 'default', 'status' => 'failed',
            'payload_json' => json_encode(['secret' => 'x', 'item_id' => 4]),
            'error_message' => 'boom', 'attempts' => 2, 'max_attempts' => 5,
        ]);

        return (int) $db->insertID();
    }

    public function testDetailIncludesSiblingsAndHistory(): void
    {
        $id      = $this->job(['request_id' => 'req-1']);
        $sibling = $this->job(['request_id' => 'req-1', 'status' => 'completed']);
        $this->job(['request_id' => 'req-2']);
        $this->job();

        $db = \Config\Database::connect();
        $db->table('reach_audit_logs')->insert(['action' => 'job.retried', 'entity_type' => 'job', 'entity_id' => $id, 'old_value' => json_encode(['status' => 'failed'])]);
        $db->table('reach_audit_logs')->insert(['action' => 'blog.published', 'entity_type' => 'content_item', 'entity_id' => 77, 'job_id' => $id]);
        $db->table('reach_audit_logs')->insert(['action' => 'job.retried', 'entity_type' => 'job', 'entity_id' => $sibling]);

        $res = $this->withHeaders($this->authAs('super_admin'))->get('v1/jobs/' . $id);
        $res->assertStatus(200);
        $data = json_decode((string) $res->getJSON(), true)['data'];

        $this->assertSame($id, $data['id']);
        $this->assertSame('boom', $data['error_message']);
        $this->assertSame(['secret', 'item_id'], $data['payload_summary']['keys']);
        $this->assertArrayNotHasKey('payload', $data, 'payload stays redacted without ?include=payload');

        $this->assertSame([$sibling], array_column($data['siblings'], 'id'));
        $this->assertSame(['blog.published', 'job.retried'], array_column($data['history'], 'action'));
        $this->assertSame(['status' => 'failed'], $data['history'][1]['old_value']);
    }

    public function testNoRequestIdMeansNoSiblings(): void
    {
        $id = $this->job();
        $this->job();
        $res = $this->withHeaders($this->authAs('super_admin'))->get('v1/jobs/' . $id);
        $data = json_decode((string) $res->getJSON(), true)['data'];

        $this->assertSame([], $data['siblings']);
        $this->assertSame([], $data['history']);
    }
}
