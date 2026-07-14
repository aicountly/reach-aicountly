<?php

namespace App\Libraries;

use App\Models\DailyMarketingPackModel;
use App\Models\DailyMarketingPackItemModel;
use App\Models\Content\ContentItemModel;
use App\Models\SettingModel;

/**
 * Generates and manages daily marketing packs.
 *
 * Configuration is stored in reach_settings (key: daily_pack_config).
 * Prevents duplicate item inclusion via unique DB constraint and explicit checks.
 * Missing slots are represented as placeholder records.
 */
class DailyMarketingPackService
{
    private DailyMarketingPackModel     $packs;
    private DailyMarketingPackItemModel $items;
    private ContentItemModel            $content;
    private SettingModel                $settings;
    private AuditLogger                 $audit;

    public function __construct()
    {
        $this->packs    = new DailyMarketingPackModel();
        $this->items    = new DailyMarketingPackItemModel();
        $this->content  = new ContentItemModel();
        $this->settings = new SettingModel();
        $this->audit    = new AuditLogger();
    }

    /**
     * Generate a daily marketing pack for the given date.
     * Idempotent: returns existing pack if already generated.
     */
    public function generateForDate(string $date, ?int $marketId = null, string $language = 'en', array $actor = []): array
    {
        $existing = $this->packs->forDate($date, $marketId, $language);
        if ($existing) {
            return $existing;
        }

        $config = $this->getConfig();

        $packId = $this->packs->insert([
            'pack_date'          => $date,
            'market_id'          => $marketId,
            'language'           => $language,
            'pack_status'        => 'draft',
            'admin_owner_id'     => $actor['id'] ?? null,
            'config_snapshot'    => $config,
            'generated_by'       => $actor['id'] ?? null,
            'created_actor_type' => $actor['type'] ?? 'system',
        ], true);

        if (! $packId) {
            throw new \RuntimeException('Failed to insert daily marketing pack.');
        }

        $this->buildSlots((int) $packId, $date, $marketId, $language, $config, $actor);

        $this->audit->log(
            $actor['id'] ?? null,
            AuditLogger::DAILY_PACK_GENERATED,
            'daily_pack',
            $packId,
            null,
            null,
            ['pack_date' => $date, 'market_id' => $marketId, 'language' => $language],
        );

        return $this->packs->find($packId);
    }

    /** Assign a content item to a pack slot. */
    public function assignItem(int $packId, int $slotItemId, int $contentItemId, array $actor = []): array
    {
        if ($this->items->isContentInPack($packId, $contentItemId)) {
            throw new \RuntimeException('Content item is already in this pack.');
        }

        $this->items->update($slotItemId, [
            'content_item_id' => $contentItemId,
            'is_placeholder'  => false,
        ]);

        $this->audit->log(
            $actor['id'] ?? null,
            AuditLogger::DAILY_PACK_ITEM_ASSIGNED,
            'daily_pack',
            $packId,
            null,
            null,
            ['content_item_id' => $contentItemId],
        );

        return $this->items->find($slotItemId);
    }

    public function getPackWithItems(int $packId): array
    {
        $pack  = $this->packs->find($packId);
        if (!$pack) {
            throw new \RuntimeException("Pack {$packId} not found.");
        }
        $pack['items'] = $this->items->forPack($packId);
        return $pack;
    }

    private function buildSlots(int $packId, string $date, ?int $marketId, string $language, array $config, array $actor): void
    {
        $slotTypes  = $config['slot_types'] ?? $this->defaultSlotTypes();
        $maxBacklog = $config['max_pending_backlog'] ?? 50;
        $sortOrder  = 0;

        foreach ($slotTypes as $slotDef) {
            $type   = $slotDef['content_type'];
            $target = $slotDef['target_count'] ?? 1;

            // Find existing content matching criteria
            $candidates = $this->content->listPaged([
                'content_type'    => $type,
                'workflow_status' => 'idea',
                'market_id'       => $marketId,
            ], $maxBacklog);

            // Filter already in any pack today
            $assigned = 0;
            foreach ($candidates as $candidate) {
                if ($assigned >= $target) {
                    break;
                }

                $this->items->insert([
                    'pack_id'         => $packId,
                    'content_item_id' => $candidate['id'],
                    'slot_type'       => $type,
                    'is_placeholder'  => false,
                    'priority'        => $slotDef['priority'] ?? 3,
                    'sort_order'      => $sortOrder++,
                    'created_by'      => $actor['id'] ?? null,
                ]);
                $assigned++;
            }

            // Fill remaining with placeholders
            for ($i = $assigned; $i < $target; $i++) {
                $this->items->insert([
                    'pack_id'         => $packId,
                    'content_item_id' => null,
                    'slot_type'       => $type,
                    'slot_label'      => "Missing: {$type}",
                    'is_placeholder'  => true,
                    'priority'        => $slotDef['priority'] ?? 3,
                    'sort_order'      => $sortOrder++,
                    'created_by'      => $actor['id'] ?? null,
                ]);
            }
        }
    }

    private function getConfig(): array
    {
        $setting = $this->settings->where('key', 'daily_pack_config')->first();
        if ($setting && !empty($setting['value_json'])) {
            $decoded = json_decode($setting['value_json'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return ['slot_types' => $this->defaultSlotTypes(), 'max_pending_backlog' => 50];
    }

    private function defaultSlotTypes(): array
    {
        return [
            ['content_type' => 'blog',        'target_count' => 2, 'priority' => 2],
            ['content_type' => 'social_post',  'target_count' => 3, 'priority' => 2],
            ['content_type' => 'email',        'target_count' => 1, 'priority' => 1],
            ['content_type' => 'knowledge_base', 'target_count' => 1, 'priority' => 3],
        ];
    }
}
