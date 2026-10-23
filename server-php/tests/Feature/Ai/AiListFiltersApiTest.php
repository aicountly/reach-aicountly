<?php

namespace Tests\Feature\Ai;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * The AI dashboard's tiles and the budgets page drill into the generations
 * list and the usage ledger with filters. Those lists must apply them, or the
 * tile lands on an unfiltered list and looks like it counted wrong.
 */
final class AiListFiltersApiTest extends ApiTestCase
{
    private function request(array $row): string
    {
        $db = Database::connect();
        $db->table('reach_ai_generation_requests')->insert(array_merge([
            'task_type'    => 'draft_generation',
            'content_type' => 'blog_post',
            'status'       => 'pending',
        ], $row));

        return (string) $db->table('reach_ai_generation_requests')
            ->where('id', $db->insertID())->get()->getRowArray()['uuid'];
    }

    private function data(string $path): array
    {
        $response = $this->withHeaders($this->authAs('reach_admin'))->call('GET', $path);
        $this->assertSame(200, $response->response()->getStatusCode(), $path);

        return json_decode((string) $response->getJSON(), true)['data'];
    }

    public function testGenerationsFilterByStatusAndTaskType(): void
    {
        $failed = $this->request(['status' => 'failed', 'task_type' => 'outline']);
        $this->request(['status' => 'completed', 'task_type' => 'outline']);
        $this->request(['status' => 'failed', 'task_type' => 'draft_generation']);

        $data = $this->data('v1/ai/generations?status=failed&task_type=outline');

        $this->assertSame([$failed], array_column($data['requests'], 'uuid'));
        $this->assertSame(1, $data['total'], 'The total must use the same filter as the page');
    }

    public function testFailedTodayMatchesTheDashboardCount(): void
    {
        $today = $this->request(['status' => 'failed']);
        $this->request(['status' => 'failed', 'created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))]);
        $done = $this->request(['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')]);

        $failed = $this->data('v1/ai/generations?status=failed&created_on=today');
        $this->assertSame([$today], array_column($failed['requests'], 'uuid'));

        $completed = $this->data('v1/ai/generations?status=completed&completed_on=today');
        $this->assertSame([$done], array_column($completed['requests'], 'uuid'));

        $stats = $this->data('v1/ai/dashboard')['stats'];
        $this->assertSame($failed['total'], $stats['failed_today']);
        $this->assertSame($completed['total'], $stats['completed_today']);
    }

    public function testGenerationsIgnoreUnknownStatusAndBadDates(): void
    {
        $this->request([]);

        $data = $this->data('v1/ai/generations?status=bogus&created_on=2026-02-31&from=yesterday');

        $this->assertSame(1, $data['total']);
    }

    public function testUsageFiltersByProviderModelAndDay(): void
    {
        $db = Database::connect();
        $db->table('reach_ai_providers')->insert([
            'provider_key' => 'drill_test', 'display_name' => 'Drill test', 'adapter_class' => 'X',
        ]);
        $providerId = (int) $db->insertID();
        $db->table('reach_ai_models')->insert([
            'provider_id' => $providerId, 'model_key' => 'drill-model', 'display_name' => 'Drill model',
        ]);
        $modelId = (int) $db->insertID();

        $uuid = $this->request(['status' => 'completed']);
        $requestId = (int) $db->table('reach_ai_generation_requests')->where('uuid', $uuid)->get()->getRowArray()['id'];

        $ledger = static fn (string $date, string $task, ?int $req = null): array => [
            'provider_id' => $providerId, 'model_id' => $modelId, 'task_type' => $task,
            'content_type' => 'blog_post', 'estimated_cost' => 0.5, 'generation_request_id' => $req,
            'usage_date' => $date, 'billing_month' => substr($date, 0, 7),
        ];
        $db->table('reach_ai_usage_ledger')->insert($ledger(date('Y-m-d'), 'outline', $requestId));
        $db->table('reach_ai_usage_ledger')->insert($ledger(date('Y-m-d', strtotime('-40 days')), 'outline'));
        $db->table('reach_ai_usage_ledger')->insert($ledger(date('Y-m-d'), 'draft_generation'));

        $today = $this->data('v1/ai/usage?provider=drill_test&model=drill-model&date=today&task_type=outline');
        $this->assertSame(1, $today['total']);
        $this->assertSame($uuid, $today['usage'][0]['generation_uuid'], 'A ledger row links back to its request');

        $month = $this->data('v1/ai/usage?provider=drill_test&billing_month=current');
        $this->assertSame(2, $month['total']);

        $none = $this->data('v1/ai/usage?provider=no_such_provider');
        $this->assertSame(0, $none['total']);
    }
}
