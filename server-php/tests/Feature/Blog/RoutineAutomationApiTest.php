<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Libraries\Settings\ReachConfig;
use Tests\Support\ApiTestCase;

/**
 * The routine-facing endpoints (X-Automation-Token) and the operator screens'
 * endpoints for routine submissions.
 *
 * @internal
 */
final class RoutineAutomationApiTest extends ApiTestCase
{
    private const TOKEN = 'routine-test-token-0123456789abcdef0123';
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['REACH_AUTOMATION_TOKEN' => self::TOKEN, 'CI_ENVIRONMENT' => 'testing', 'REACH_PUB_MOCK' => 'true',
            'JWT_SECRET' => $_ENV['JWT_SECRET'] ?? 'test-jwt-secret'] as $k => $v) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            $_ENV[$k] = $v;
        }
        // No Gemini key: vetting is unavailable, so nothing can be approved.
        $this->envBackup['AI_GEMINI_API_KEY'] = $_ENV['AI_GEMINI_API_KEY'] ?? null;
        unset($_ENV['AI_GEMINI_API_KEY']);
        putenv('AI_GEMINI_API_KEY');
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        ReachConfig::flush();
        parent::tearDown();
    }

    private function routine(): array
    {
        return ['X-Automation-Token' => self::TOKEN, 'Accept' => 'application/json'];
    }

    private function data($result): array
    {
        return json_decode($result->getJSON() ?: '{}', true)['data'] ?? [];
    }

    private function draft(array $overrides = []): array
    {
        $body = '';
        for ($i = 1; $i <= 8; $i++) {
            $body .= "<h2>Part {$i}</h2><p>Every business that files GST returns benefits from a monthly review of its purchase register, "
                . "its sales register and its bank statement. Part {$i} walks through one check in that review, explains why it matters "
                . 'and shows how to record the outcome so the accountant can file on time without last-minute corrections.</p>';
        }

        return $overrides + [
            'title'            => 'A monthly GST review checklist for small businesses',
            'summary'          => 'Eight checks to run every month before you file GST returns.',
            'body_html'        => $body,
            'seo'              => ['slug' => 'monthly-gst-review-checklist', 'meta_title' => 'Monthly GST review checklist', 'meta_description' => 'Eight checks to run every month before filing GST returns, so Indian small businesses file on time without last-minute corrections.'],
            'content_base_key' => 'blog-20261017-gst-review-checklist',
            'provenance'       => ['generated_by_model' => 'claude-sonnet'],
        ];
    }

    public function testTheBlogPlanNeedsTheTokenAndCarriesQuotaTopicsAndLinks(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])->call('GET', 'v1/automation/blog-plan')->assertStatus(401);

        $plan = $this->data($this->withHeaders($this->routine())->call('GET', 'v1/automation/blog-plan'));
        $this->assertSame(6, $plan['quota']['daily_limit']);
        $this->assertSame(6, $plan['quota']['remaining_today']);
        $this->assertFalse($plan['stop']);
        $this->assertSame(['Smart Books', 'Billing', 'Inventory', 'Notes', 'Drive', 'Manage'], array_column($plan['priority_topics']['launch_apps'], 'name'));
        $this->assertNotEmpty($plan['priority_topics']['compliance_calendar']);
        $this->assertArrayHasKey('links', $plan['internal_links']);
        $this->assertIsArray($plan['existing_titles']);
    }

    public function testASubmissionWithoutVettingStaysPendingAndShowsUpForOperators(): void
    {
        $res = $this->withHeaders($this->routine())->withBodyFormat('json')->call('POST', 'v1/automation/blog-drafts', $this->draft());
        $res->assertStatus(201);
        $data = $this->data($res);
        $this->assertSame('pending_vetting', $data['pipeline']['status']);
        $this->assertStringContainsString('Vetting unavailable', $data['pipeline']['note']);

        $plan = $this->data($this->withHeaders($this->routine())->call('GET', 'v1/automation/blog-plan'));
        $this->assertSame(5, $plan['quota']['remaining_today']);
        $this->assertContains('monthly-gst-review-checklist', array_column($plan['existing_titles'], 'slug'));

        $admin = $this->authAs('super_admin');
        $list  = $this->data($this->withHeaders($admin)->call('GET', 'v1/routine-submissions', ['content_type' => 'blog']));
        $this->assertCount(1, $list['items']);
        $this->assertSame('pending_vetting', $list['items'][0]['status']);
        $this->assertTrue($list['items'][0]['retryable']);
        $this->assertArrayHasKey('blog.auto_publish', $list['flags']);

        $retry = $this->withHeaders($admin)->call('POST', 'v1/routine-submissions/' . $list['items'][0]['id'] . '/retry');
        $retry->assertStatus(200);
        $this->assertSame('pending_vetting', $this->data($retry)['status'], 'still no Gemini key — never published unvetted');

        $viewer = $this->authAs('viewer');
        $this->withHeaders($viewer)->call('POST', 'v1/routine-submissions/' . $list['items'][0]['id'] . '/retry')->assertStatus(403);
    }

    public function testRefusalsAreSpecific(): void
    {
        $this->withHeaders($this->routine())->withBodyFormat('json')->call('POST', 'v1/automation/blog-drafts', $this->draft(['content_base_key' => '']))->assertStatus(422);

        ReachConfig::set('automation.blog.daily_limit', 0);
        $this->withHeaders($this->routine())->withBodyFormat('json')->call('POST', 'v1/automation/blog-drafts', $this->draft())->assertStatus(429);

        ReachConfig::set('automation.blog.enabled', false);
        $this->withHeaders($this->routine())->withBodyFormat('json')->call('POST', 'v1/automation/blog-drafts', $this->draft())->assertStatus(403);
    }
}
