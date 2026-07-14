<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\DailyMarketingPackService;
use App\Models\DailyMarketingPackModel;
use App\Models\SettingModel;

/**
 * Daily marketing pack management.
 *
 * Routes:
 *   GET  /v1/content/daily-packs
 *   POST /v1/content/daily-packs/generate
 *   GET  /v1/content/daily-packs/:id
 *   PUT  /v1/content/daily-packs/:id/items/:itemId
 */
class DailyPackController extends BaseContentController
{
    private DailyMarketingPackService $service;
    private DailyMarketingPackModel   $packs;
    private SettingModel              $settings;

    public function __construct()
    {
        parent::__construct();
        $this->service  = new DailyMarketingPackService();
        $this->packs    = new DailyMarketingPackModel();
        $this->settings = new SettingModel();
    }

    public function index()
    {
        return $this->ok(['packs' => $this->packs->listPaged(25)]);
    }

    public function show($id)
    {
        try {
            $pack = $this->service->getPackWithItems((int) $id);
            return $this->ok($pack);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 404);
        }
    }

    public function generate()
    {
        $body     = $this->input();
        $date     = $body['pack_date'] ?? date('Y-m-d');
        $marketId = isset($body['market_id']) ? (int) $body['market_id'] : null;
        $language = $body['language'] ?? 'en';

        try {
            $pack = $this->service->generateForDate($date, $marketId, $language, $this->actor());
            return $this->ok($pack, 201);
        } catch (\Throwable $e) {
            log_message('error', '[DailyPackController::generate] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->fail($e->getMessage(), 422);
        }
    }

    /** GET /v1/content/daily-packs/config */
    public function getConfig()
    {
        $value = $this->settings->getSetting('daily_pack_config', null);
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return $this->ok(['config' => $value ?? $this->defaultConfig()]);
    }

    /** PUT /v1/content/daily-packs/config */
    public function updateConfig()
    {
        $body = $this->input();
        if (empty($body)) {
            return $this->fail('Request body required.', 422);
        }
        $this->settings->setSetting('daily_pack_config', json_encode($body), $this->actor()['id'] ?? null);
        return $this->ok(['config' => $body]);
    }

    private function defaultConfig(): array
    {
        return [
            'slot_types' => [
                ['content_type' => 'blog',         'target_count' => 2, 'priority' => 2],
                ['content_type' => 'social_post',  'target_count' => 3, 'priority' => 2],
                ['content_type' => 'email',        'target_count' => 1, 'priority' => 1],
                ['content_type' => 'knowledge_base', 'target_count' => 1, 'priority' => 3],
            ],
            'max_pending_backlog' => 50,
        ];
    }

    public function assignItem($packId, $slotItemId)
    {
        $body          = $this->input();
        $contentItemId = (int) ($body['content_item_id'] ?? 0);
        if (!$contentItemId) {
            return $this->fail('content_item_id is required.', 422);
        }

        try {
            $item = $this->service->assignItem((int) $packId, (int) $slotItemId, $contentItemId, $this->actor());
            return $this->ok($item);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }
}
