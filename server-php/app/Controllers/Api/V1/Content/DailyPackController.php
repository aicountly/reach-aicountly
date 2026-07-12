<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\DailyMarketingPackService;
use App\Models\DailyMarketingPackModel;

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

    public function __construct()
    {
        parent::__construct();
        $this->service = new DailyMarketingPackService();
        $this->packs   = new DailyMarketingPackModel();
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
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
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
