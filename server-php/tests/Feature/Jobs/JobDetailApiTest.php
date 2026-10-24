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

    public function testADeadLetteredJobCanBeCancelledAndKeepsItsLastError(): void
    {
        $id  = $this->job(['status' => 'dead_letter', 'error_message' => "Deployment #74 is in status 'dead_letter' and cannot be retried."]);
        $res = $this->withHeaders($this->authAs('super_admin'))->withBodyFormat('json')
            ->post("v1/jobs/{$id}/cancel", ['reason' => 'published since by deployment #77']);
        $res->assertStatus(200);

        $row = \Config\Database::connect()->table('reach_jobs')->where('id', $id)->get()->getRowArray();
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame(
            "Cancelled: published since by deployment #77\n\nLast error: Deployment #74 is in status 'dead_letter' and cannot be retried.",
            $row['error_message'],
        );
        $this->assertNotNull($row['completed_at']);

        $data = json_decode((string) $this->withHeaders($this->authAs('super_admin'))->get('v1/jobs/' . $id)->getJSON(), true)['data'];
        $this->assertContains('job.cancelled', array_column($data['history'], 'action'));
    }

    public function testCancellingWithoutAReasonKeepsTheErrorAsItWas(): void
    {
        $id = $this->job(['status' => 'dead_letter']);
        $this->withHeaders($this->authAs('super_admin'))->post("v1/jobs/{$id}/cancel")->assertStatus(200);

        $row = \Config\Database::connect()->table('reach_jobs')->where('id', $id)->get()->getRowArray();
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame('boom', $row['error_message']);
    }

    public function testFinishedJobsStillCannotBeCancelled(): void
    {
        foreach (['completed', 'skipped', 'cancelled'] as $status) {
            $id = $this->job(['status' => $status]);
            $this->withHeaders($this->authAs('super_admin'))->post("v1/jobs/{$id}/cancel")->assertStatus(422);
        }
    }
}
