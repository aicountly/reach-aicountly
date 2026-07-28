<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

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

        $db = \Config\Database::connect();

        $channel = trim((string) $this->request->getGet('channel'));

        $countBuilder = $db->table('reach_campaign_operational_metrics m')
            ->where('m.tenant_id', $tenantId);
        if ($channel !== '') {
            $countBuilder->where('m.channel', $channel);
        }
        $total = $countBuilder->countAllResults();

        $builder = $db->table('reach_campaign_operational_metrics m')
            ->select('m.*, d.campaign_id, d.status AS dispatch_status')
            ->join('reach_campaign_dispatches d', 'd.id = m.dispatch_id', 'left')
            ->where('m.tenant_id', $tenantId)
            ->orderBy('m.last_updated', 'DESC')
            ->limit($perPage, $offset);

        if ($channel !== '') {
            $builder->where('m.channel', $channel);
        }

        $rows = $builder->get()->getResultArray();

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
    }
}
