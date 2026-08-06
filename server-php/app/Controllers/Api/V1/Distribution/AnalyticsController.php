<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class AnalyticsController extends BaseApiController
{
    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    /**
     * GET /distribution/analytics
     *
     * Returns per-dispatch operational metrics for the tenant,
     * shaped for the Distribution Analytics UI.
     */
    public function index(): ResponseInterface
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? $this->request->getGet('limit') ?? 50)));
        $offset  = ($page - 1) * $perPage;
        $tenantId = $this->tenantId();
        $channel  = trim((string) $this->request->getGet('channel'));

        $empty = static fn (): array => [
            'data'     => [],
            'total'    => 0,
            'page'     => $page,
            'per_page' => $perPage,
        ];

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_campaign_operational_metrics')) {
                return $this->ok($empty());
            }

            $countBuilder = $db->table('reach_campaign_operational_metrics')
                ->where('tenant_id', $tenantId);
            if ($channel !== '') {
                $countBuilder->where('channel', $channel);
            }
            $total = $countBuilder->countAllResults();

            // Avoid table aliases in Query Builder — some CI4/Postgres
            // identifier escaping paths quote "table alias" as one name.
            $hasDispatches = SchemaGuard::hasTable($db, 'reach_campaign_dispatches');
            $select = $hasDispatches
                ? 'reach_campaign_operational_metrics.*, '
                    . 'reach_campaign_dispatches.campaign_id, '
                    . 'reach_campaign_dispatches.status AS dispatch_status'
                : 'reach_campaign_operational_metrics.*';

            $builder = $db->table('reach_campaign_operational_metrics')
                ->select($select)
                ->where('reach_campaign_operational_metrics.tenant_id', $tenantId)
                ->orderBy('reach_campaign_operational_metrics.last_updated', 'DESC')
                ->limit($perPage, $offset);

            if ($hasDispatches) {
                $builder->join(
                    'reach_campaign_dispatches',
                    'reach_campaign_dispatches.id = reach_campaign_operational_metrics.dispatch_id',
                    'left'
                );
            }

            if ($channel !== '') {
                $builder->where('reach_campaign_operational_metrics.channel', $channel);
            }

            $rows = $builder->get()->getResultArray();
            if (! is_array($rows)) {
                $rows = [];
            }

            $data = array_map(static function (array $row): array {
                return [
                    'id'                 => (int) ($row['id'] ?? 0),
                    'dispatch_id'        => (int) ($row['dispatch_id'] ?? 0),
                    'campaign_id'        => isset($row['campaign_id']) ? (int) $row['campaign_id'] : null,
                    'channel'            => $row['channel'] ?? null,
                    'dispatch_status'    => $row['dispatch_status'] ?? null,
                    'sent_count'         => (int) ($row['sent'] ?? 0),
                    'delivered_count'    => (int) ($row['delivered'] ?? 0),
                    'open_count'         => (int) ($row['read_count'] ?? 0),
                    'click_count'        => 0,
                    'bounce_count'       => (int) ($row['bounced'] ?? 0),
                    'unsubscribe_count'  => (int) ($row['unsubscribed'] ?? 0),
                    'failed_count'       => (int) ($row['failed'] ?? 0),
                    'suppressed_count'   => (int) ($row['suppressed'] ?? 0),
                    'queued'             => (int) ($row['queued'] ?? 0),
                    'last_updated'       => $row['last_updated'] ?? null,
                ];
            }, $rows);

            return $this->ok([
                'data'     => $data,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'AnalyticsController::index: ' . $e->getMessage());
            return $this->ok($empty());
        }
    }
}
