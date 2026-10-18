<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Libraries\Bot\Plan\PlanGenerator;
use App\Libraries\Bot\Plan\PlanPeriods;
use App\Libraries\Bot\Plan\PlanStore;
use Config\Database;

/** Shared fixtures for the Bot Plan & Performance feature tests. */
trait BotPlanFixtures
{
    /** @return array<string, mixed> */
    private static function planInputs(string $today, array $config = []): array
    {
        return [
            'today'  => $today,
            'config' => array_replace_recursive([
                'blog'           => ['enabled' => true, 'daily_limit' => 6, 'auto_publish' => true, 'ai_cover' => true],
                'knowledge_base' => ['enabled' => true, 'daily_limit' => 10, 'auto_publish' => true, 'cadence_total' => 10, 'cadence_products' => 5],
                'community'      => ['enabled' => true, 'daily_limit' => 10],
                'release_note'   => ['enabled' => true, 'daily_limit' => 40],
                'workflow_guide' => ['enabled' => true, 'daily_limit' => 40],
                'video'          => ['enabled' => true, 'vlogs' => 1, 'shorts' => 1, 'youtube_publisher' => 'social_api'],
                'seo'            => ['enabled' => true, 'max_per_day' => 35, 'active_keywords' => 30],
            ], $config),
            'scheduled'         => ['social' => [], 'email' => [], 'calendar' => [], 'content_schedules' => [], 'campaigns' => [], 'topic_queue' => null, 'items' => []],
            'history'           => ['series' => [], 'first_dates' => [], 'notes' => []],
            'overrides'         => [],
            'self_tune_enabled' => true,
        ];
    }

    /** Store a day plan of record for every date in the range (generated at 01:00 IST that day). */
    private function seedDayPlans(string $from, string $to, array $config = []): void
    {
        $store = new PlanStore(Database::connect());
        foreach (PlanPeriods::dates($from, $to) as $d) {
            $plan = PlanGenerator::generate(self::planInputs($d, $config), 'day', $d, $d);
            $store->save('day', $d, $d, $plan['items'], $plan['summary'], new \DateTimeImmutable($d . ' 01:00:00', new \DateTimeZone('Asia/Kolkata')));
        }
    }

    /** @param array<string, float|null> $byDate */
    private function seedActuals(string $metric, array $byDate): void
    {
        $db = Database::connect();
        foreach ($byDate as $d => $v) {
            $db->query('INSERT INTO reach_bot_plan_actuals (metric_date, metric_key, value) VALUES (?, ?, ?)
                        ON CONFLICT (metric_date, metric_key) DO UPDATE SET value = EXCLUDED.value', [$d, $metric, $v]);
        }
    }

    /** @return \Closure(): array<string, bool|null> */
    private static function integrations(array $o = []): \Closure
    {
        return static fn (): array => $o + ['gemini' => true, 'openai' => true, 'shotstack' => true, 'media_signing' => true, 'social_api' => true,
            'dataforseo' => true, 'search_console' => true, 'email' => true, 'engage_push' => true, 'engage_inbound' => true];
    }
}
