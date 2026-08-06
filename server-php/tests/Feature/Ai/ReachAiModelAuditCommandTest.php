<?php

namespace Tests\Feature\Ai;

use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Cover for the audit that finds models the provider has withdrawn.
 *
 * A model row stays enabled locally long after the provider drops it, and both
 * the router and the fallback resolver filter on `enabled` — so they keep
 * choosing it. Runtime now routes around a withdrawn model, but the dead row
 * remains first in line, so it has to be taken out of the rotation.
 *
 * Which models are dead is decided by evidence — an actual provider rejection
 * recorded against a run — never by guessing at model names.
 */
final class ReachAiModelAuditCommandTest extends DatabaseTestCase
{
    private int $providerId = 0;

    private function seedProvider(): int
    {
        if ($this->providerId > 0) {
            return $this->providerId;
        }

        $db = Database::connect();
        $db->table('reach_ai_providers')->insert([
            'provider_key'  => 'gemini-' . bin2hex(random_bytes(3)),
            'display_name'  => 'Gemini',
            'adapter_class' => 'App\\Libraries\\Ai\\Providers\\GeminiProvider',
            'status'        => 'enabled',
        ]);

        return $this->providerId = (int) $db->insertID();
    }

    private function seedRequest(): int
    {
        $db = Database::connect();
        $db->table('reach_ai_generation_requests')->insert([
            'task_type'    => 'draft_generation',
            'content_type' => 'blog_post',
            'status'       => 'failed',
        ]);

        return (int) $db->insertID();
    }

    private function seedModel(string $key, bool $enabled = true): int
    {
        $db = Database::connect();
        $db->table('reach_ai_models')->insert([
            'provider_id' => $this->seedProvider(),
            'model_key'   => $key,
            'display_name'=> $key,
            'enabled'     => $enabled,
        ]);

        return (int) $db->insertID();
    }

    private function seedRun(int $modelId, ?string $category, ?string $message): void
    {
        Database::connect()->table('reach_ai_generation_runs')->insert([
            'generation_request_id'  => $this->seedRequest(),
            'provider_id'            => $this->seedProvider(),
            'model_id'               => $modelId,
            'status'                 => 'failed',
            'error_category'         => $category,
            'redacted_error_message' => $message,
        ]);
    }

    private function seedRoute(int $modelId): int
    {
        $db = Database::connect();
        $db->table('reach_ai_model_routes')->insert([
            'task_type'        => 'draft_generation',
            'primary_model_id' => $modelId,
            'priority'         => 90,
            'enabled'          => true,
        ]);

        return (int) $db->insertID();
    }

    private function modelEnabled(int $id): bool
    {
        $row = Database::connect()->table('reach_ai_models')->where('id', $id)->get()->getRowArray();

        return in_array($row['enabled'] ?? null, [true, 't', '1', 1], true);
    }

    private function routeEnabled(int $id): bool
    {
        $row = Database::connect()->table('reach_ai_model_routes')->where('id', $id)->get()->getRowArray();

        return in_array($row['enabled'] ?? null, [true, 't', '1', 1], true);
    }

    public function testDryRunDisablesNothing(): void
    {
        $model = $this->seedModel('gemini-2.0-flash');
        $this->seedRun($model, 'model_retired', 'This model models/gemini-2.0-flash is no longer available.');

        command('reach:ai-model-audit');

        $this->assertTrue($this->modelEnabled($model));
    }

    public function testApplyDisablesTheWithdrawnModel(): void
    {
        $model = $this->seedModel('gemini-2.0-flash');
        $this->seedRun($model, 'model_retired', 'This model models/gemini-2.0-flash is no longer available.');

        command('reach:ai-model-audit --apply');

        $this->assertFalse($this->modelEnabled($model));
    }

    public function testRoutesPointingAtTheDeadModelAreDisabledToo(): void
    {
        $model = $this->seedModel('gemini-2.0-flash');
        $route = $this->seedRoute($model);
        $this->seedRun($model, 'model_retired', 'no longer available');

        command('reach:ai-model-audit --apply');

        $this->assertFalse($this->routeEnabled($route), 'A route to a dead model keeps selecting it');
    }

    public function testRunsRecordedBeforeTheCategoryExistedAreStillFound(): void
    {
        // Production's existing rows classified as 'unknown' — the evidence is
        // in the message, and the audit is worthless if it cannot read those.
        $model = $this->seedModel('gemini-2.5-pro');
        $this->seedRun($model, 'unknown', 'This model models/gemini-2.5-pro is no longer available to new users.');

        command('reach:ai-model-audit --apply');

        $this->assertFalse($this->modelEnabled($model));
    }

    public function testHealthyModelsAreLeftAlone(): void
    {
        $healthy = $this->seedModel('gemini-2.5-flash');
        $route   = $this->seedRoute($healthy);
        // Failures that say nothing about the model being gone.
        $this->seedRun($healthy, 'rate_limited', 'Rate limit reached for requests');
        $this->seedRun($healthy, 'budget_blocked', 'You exceeded your current quota');

        command('reach:ai-model-audit --apply');

        $this->assertTrue($this->modelEnabled($healthy), 'A throttled model is not a dead model');
        $this->assertTrue($this->routeEnabled($route));
    }

    public function testARunCanActuallyStoreTheNewCategory(): void
    {
        // error_category is constrained to an enumerated list. Adding the
        // category in PHP without widening the constraint would turn the first
        // withdrawn-model failure into an unhandled INSERT error — a worse
        // outcome than the bug being fixed.
        $model = $this->seedModel('gemini-2.0-flash');
        $this->seedRun($model, 'model_retired', 'This model is no longer available.');

        $row = Database::connect()->table('reach_ai_generation_runs')
            ->where('model_id', $model)->get()->getRowArray();

        $this->assertSame('model_retired', $row['error_category']);
    }

    public function testAModelWithNoFailuresAtAllIsUntouched(): void
    {
        $model = $this->seedModel('gemini-2.5-flash');

        command('reach:ai-model-audit --apply');

        $this->assertTrue($this->modelEnabled($model));
    }
}
