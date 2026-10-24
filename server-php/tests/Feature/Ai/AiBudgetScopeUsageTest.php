<?php

namespace Tests\Feature\Ai;

use App\Libraries\Ai\Generation\AiBudgetService;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * A budget's "used" amount, the usage-ledger rows it opens and what the
 * budget check enforces share one predicate (AiBudgetService::ledgerScope),
 * product and user budgets included.
 */
final class AiBudgetScopeUsageTest extends ApiTestCase
{
    private int $providerId;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $db->table('reach_ai_providers')->insert(['provider_key' => 'scope_test', 'display_name' => 'Scope test', 'adapter_class' => 'X']);
        $this->providerId = (int) $db->insertID();
        $db->table('reach_ai_models')->insert(['provider_id' => $this->providerId, 'model_key' => 'scope-model', 'display_name' => 'Scope model']);
        $this->modelId = (int) $db->insertID();
    }

    private function product(string $slug): int
    {
        $db = Database::connect();
        $db->table('reach_products')->insert(['slug' => $slug, 'name' => ucfirst($slug)]);

        return (int) $db->insertID();
    }

    private function spend(float $cost, array $row = [], ?string $date = null): void
    {
        $date ??= date('Y-m-d');
        Database::connect()->table('reach_ai_usage_ledger')->insert($row + [
            'provider_id' => $this->providerId, 'model_id' => $this->modelId, 'task_type' => 'outline',
            'content_type' => 'blog_post', 'estimated_cost' => $cost, 'usage_date' => $date, 'billing_month' => substr($date, 0, 7),
        ]);
    }

    private function budget(string $scope, string $ref, string $period, float $hard = 10.0): void
    {
        Database::connect()->table('reach_ai_budgets')->insert([
            'scope_type' => $scope, 'scope_reference' => $ref, 'period_type' => $period,
            'warning_limit' => 0, 'hard_limit' => $hard, 'enabled' => true,
        ]);
    }

    private function data(string $path): array
    {
        $response = $this->withHeaders($this->authAs('reach_admin'))->call('GET', $path);
        $this->assertSame(200, $response->response()->getStatusCode(), $path);

        return json_decode((string) $response->getJSON(), true)['data'];
    }

    private function sum(array $usage): float
    {
        return round(array_sum(array_map(static fn (array $u): float => (float) $u['estimated_cost'], $usage)), 6);
    }

    public function testProductAndUserBudgetsOpenTheLedgerRowsTheyCount(): void
    {
        $gst = $this->product('gst-suite');
        $other = $this->product('payroll');
        $this->authAs('analyst');
        $userId = (int) Database::connect()->table('reach_users')->where('email', 'analyst@test.aicountly.org')->get()->getRowArray()['id'];

        $this->spend(1.25, ['product_id' => $gst, 'user_id' => $userId]);
        $this->spend(0.75, ['product_id' => $gst]);
        $this->spend(2.0, ['product_id' => $other, 'user_id' => $userId]);
        $this->spend(9.0, ['product_id' => $gst, 'user_id' => $userId], date('Y-m-d', strtotime('-40 days')));

        $this->budget('product', 'gst-suite', 'daily');
        $this->budget('user', (string) $userId, 'monthly');
        $this->budget('product', (string) $other, 'daily');

        $budgets = [];
        foreach ($this->data('v1/ai/budgets')['budgets'] as $b) {
            $budgets[$b['scope_type'] . ':' . $b['scope_reference']] = $b;
        }

        $product = $budgets['product:gst-suite'];
        $this->assertSame('ledger', $product['used_source']);
        $this->assertEqualsWithDelta(2.0, (float) $product['used_amount'], 1e-6);
        $productRows = $this->data('v1/ai/usage?product=gst-suite&date=today');
        $this->assertSame(2, $productRows['total']);
        $this->assertEqualsWithDelta((float) $product['used_amount'], $this->sum($productRows['usage']), 1e-6);
        $this->assertSame('gst-suite', $productRows['usage'][0]['product_slug']);

        // A product budget may name the product by id.
        $byId = $budgets['product:' . $other];
        $this->assertEqualsWithDelta(2.0, (float) $byId['used_amount'], 1e-6);
        $this->assertSame(1, $this->data('v1/ai/usage?product=' . $other . '&date=today')['total']);

        $user = $budgets['user:' . $userId];
        $userRows = $this->data('v1/ai/usage?user=' . $userId . '&billing_month=current');
        $this->assertEqualsWithDelta(3.25, (float) $user['used_amount'], 1e-6);
        $this->assertSame(2, $userRows['total']);
        $this->assertEqualsWithDelta((float) $user['used_amount'], $this->sum($userRows['usage']), 1e-6);
        $this->assertSame(2, $this->data('v1/ai/usage?user=analyst@test.aicountly.org&billing_month=current')['total'], 'by email too');

        $this->assertSame(0, $this->data('v1/ai/usage?product=no-such-product')['total']);
    }

    public function testTheCheckCountsOnlyItsOwnScope(): void
    {
        $gst = $this->product('gst-suite');
        $this->product('payroll');
        $this->budget('product', 'gst-suite', 'daily', 1.0);
        $this->budget('provider', 'scope_test', 'daily', 100.0);
        $context = ['provider_key' => 'scope_test', 'model_key' => 'scope-model', 'content_type' => 'blog_post', 'task_type' => 'outline'];
        $service = new AiBudgetService();

        // Another product's spend is not this product's budget.
        $this->spend(5.0, ['product_id' => null]);
        $this->assertTrue($service->check($context + ['product_slug' => 'gst-suite', 'product_id' => $gst])->allowed);

        $this->spend(1.5, ['product_id' => $gst]);
        $blocked = $service->check($context + ['product_slug' => 'gst-suite', 'product_id' => $gst]);
        $this->assertTrue($blocked->hardBlocked);
        $this->assertSame('product', $blocked->scopeType);
        $this->assertEqualsWithDelta(1.5, $blocked->usedAmount, 1e-6);
        $shown = array_values(array_filter($this->data('v1/ai/budgets')['budgets'], static fn (array $b): bool => $b['scope_type'] === 'product'));
        $this->assertEqualsWithDelta($blocked->usedAmount, (float) $shown[0]['used_amount'], 1e-6, 'The page shows what the check enforced');

        // Without a product the product budget does not apply.
        $this->assertTrue($service->check($context)->allowed);
    }
}
