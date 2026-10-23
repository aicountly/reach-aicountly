<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Filters\EngageTokenFilter;
use App\Libraries\Leads\LeadOutcomeSummary;
use Config\Database;
use InvalidArgumentException;

/**
 * Engage lead outcomes as seen by Reach staff.
 *
 *   GET v1/leads/outcomes          list (lead.view) — filters: stage, match (a match method, or
 *                                  matched / unmatched), lead_id, campaign_id, content_identity_id,
 *                                  source (`unattributed` = none), seller_code, paid=1, from, to,
 *                                  search (lead code / seller code / lead name or e-mail)
 *   GET v1/leads/outcomes/summary  LeadOutcomeSummary::forPeriod (lead.view)
 *   GET v1/analytics/outcomes      the same summary for the Analytics page (analytics.view)
 *
 * Both summaries default to the last 30 days (IST) and report whether the Engage inbound
 * token is configured, so an empty page can say why it is empty.
 */
class LeadOutcomeController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination(50, 200);
        $db     = Database::connect();
        $where  = [];
        $params = [];

        foreach (['stage' => 'o.stage', 'seller_code' => 'o.seller_code', 'source' => "COALESCE(o.source, 'unattributed')"] as $key => $col) {
            $v = trim((string) $this->request->getGet($key));
            if ($v !== '') {
                $where[]  = "{$col} = ?";
                $params[] = $v;
            }
        }
        // matched / unmatched mean "has a Reach lead" — the summary's matched/unmatched split.
        $match = trim((string) $this->request->getGet('match'));
        if ($match === 'matched' || $match === 'unmatched') {
            $where[] = $match === 'matched' ? 'o.lead_id IS NOT NULL' : 'o.lead_id IS NULL';
        } elseif ($match !== '') {
            $where[]  = 'o.match_method = ?';
            $params[] = $match;
        }
        // paid=1: the payment-confirmed events the summary sums into revenue_inr.
        if ($this->request->getGet('paid') === '1') {
            $where[] = LeadOutcomeSummary::PAID;
        }
        foreach (['lead_id' => 'o.lead_id', 'campaign_id' => 'o.campaign_id', 'content_identity_id' => 'o.content_identity_id'] as $key => $col) {
            $v = trim((string) $this->request->getGet($key));
            if ($v !== '' && ctype_digit($v)) {
                $where[]  = "{$col} = ?";
                $params[] = (int) $v;
            }
        }
        $from = trim((string) $this->request->getGet('from'));
        $to   = trim((string) $this->request->getGet('to'));
        if ($from !== '' || $to !== '') {
            try {
                [$start, $end] = LeadOutcomeSummary::bounds($from !== '' ? $from : '2000-01-01', $to !== '' ? $to : date('Y-m-d', strtotime('+1 day')));
            } catch (InvalidArgumentException $e) {
                return $this->fail($e->getMessage(), 422);
            }
            $where[]  = 'o.occurred_at >= ?::timestamptz AND o.occurred_at < ?::timestamptz';
            $params[] = $start->format('c');
            $params[] = $end->format('c');
        }
        $search = $this->searchTerm();
        if ($search !== null) {
            $like     = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $where[]  = '(o.engage_lead_code ILIKE ? OR o.seller_code ILIKE ? OR l.name ILIKE ? OR l.email ILIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $from_    = 'FROM reach_lead_outcomes o
                     LEFT JOIN reach_leads l ON l.id = o.lead_id
                     LEFT JOIN reach_campaigns c ON c.id = o.campaign_id';

        $total = (int) ($db->query("SELECT COUNT(*) AS n {$from_} {$sqlWhere}", $params)->getRowArray()['n'] ?? 0);
        $rows  = $db->query(
            "SELECT o.id, o.event_id, o.lead_id, o.engage_lead_code, o.match_method, o.stage, o.sales_status,
                    o.product, o.value_inr::text AS value_inr, o.occurred_at, o.received_at, o.seller_code,
                    o.campaign_code, o.source_portal, o.campaign_id, c.name AS campaign_name,
                    o.landing_page_id, o.content_identity_id, o.source, o.attribution_method,
                    l.name AS lead_name, l.email AS lead_email
               {$from_} {$sqlWhere}
              ORDER BY o.occurred_at DESC, o.id DESC
              LIMIT {$limit} OFFSET {$offset}",
            $params,
        )->getResultArray();

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function summary()
    {
        $tz   = 'Asia/Kolkata';
        $now  = new \DateTimeImmutable('now', new \DateTimeZone($tz));
        $from = trim((string) $this->request->getGet('from'));
        $to   = trim((string) $this->request->getGet('to'));
        $from = $from !== '' ? $from : $now->modify('-29 days')->format('Y-m-d');
        $to   = $to !== '' ? $to : $now->format('Y-m-d');

        try {
            $summary = LeadOutcomeSummary::forPeriod($from, $to, $tz);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $summary['inbound_configured'] = EngageTokenFilter::configuredToken() !== null;
        $last = Database::connect()->query('SELECT MAX(received_at) AS t FROM reach_lead_outcomes')->getRowArray();
        $summary['last_received_at'] = $last['t'] ?? null;

        return $this->ok($summary);
    }
}
