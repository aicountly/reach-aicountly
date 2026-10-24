<?php

declare(strict_types=1);

namespace App\Libraries\Leads;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\AttributionConversions;
use CodeIgniter\Database\BaseConnection;

/**
 * Everything Reach stores about one lead, for the lead detail page
 * (GET v1/leads/:id → `related`): its campaign and landing page, every Engage
 * push attempt, the Engage outcome events matched to it, the attribution
 * conversions and touchpoints, the social inbox message it came from, and
 * the audit trail. A missing table yields an empty section, never an error.
 */
final class LeadDetail
{
    public const PUSH_LIMIT     = 100;
    public const OUTCOME_LIMIT  = 200;
    public const AUDIT_LIMIT    = 200;
    public const TOUCH_LIMIT    = 100;

    /** @param array<string,mixed> $lead a reach_leads row */
    public static function related(BaseConnection $db, array $lead): array
    {
        $id = (int) $lead['id'];

        return [
            'campaign'     => self::campaign($db, $lead),
            'landing_page' => self::landingPage($db, $lead),
            'push_attempts'=> self::rows($db, 'reach_engage_push_attempts',
                'SELECT id, attempt_number, ok, response_status, error_message, request_body, response_body, attempted_at
                   FROM reach_engage_push_attempts WHERE lead_id = ?
                  ORDER BY attempted_at DESC, id DESC LIMIT ' . self::PUSH_LIMIT, [$id], ['request_body', 'response_body']),
            'outcomes'     => self::rows($db, 'reach_lead_outcomes',
                'SELECT o.id, o.event_id, o.engage_lead_code, o.match_method, o.stage, o.sales_status, o.product,
                        o.value_inr::text AS value_inr, o.occurred_at, o.received_at, o.seller_code, o.campaign_code,
                        o.source_portal, o.campaign_id, c.name AS campaign_name, o.landing_page_id,
                        o.content_identity_id, o.source, o.attribution_method
                   FROM reach_lead_outcomes o
                   LEFT JOIN reach_campaigns c ON c.id = o.campaign_id
                  WHERE o.lead_id = ?
                  ORDER BY o.occurred_at DESC, o.id DESC LIMIT ' . self::OUTCOME_LIMIT, [$id]),
            'attribution'  => self::attribution($db, $id),
            'social_inbox' => self::rows($db, 'reach_social_inbox',
                'SELECT id, network, kind, account_id, author_name, author_handle, text, post_url, received_at,
                        status, status_reason, classification, summary, ticket_number, reply_status
                   FROM reach_social_inbox WHERE lead_id = ?
                  ORDER BY received_at DESC NULLS LAST, id DESC LIMIT 20', [$id]),
            'audit'        => self::rows($db, 'reach_audit_logs',
                "SELECT a.id, a.action, a.user_id, u.name AS user_name, a.actor_type, a.actor_service,
                        a.old_value, a.new_value, a.reason, a.created_at
                   FROM reach_audit_logs a
                   LEFT JOIN reach_users u ON u.id = a.user_id
                  WHERE a.entity_type = 'lead' AND a.entity_id = ?
                  ORDER BY a.created_at DESC, a.id DESC LIMIT " . self::AUDIT_LIMIT, [$id], ['old_value', 'new_value']),
        ];
    }

    private static function campaign(BaseConnection $db, array $lead): ?array
    {
        if (empty($lead['campaign_id']) || ! SchemaGuard::hasTable($db, 'reach_campaigns')) {
            return null;
        }
        return $db->query('SELECT id, name, campaign_type, status FROM reach_campaigns WHERE id = ?', [(int) $lead['campaign_id']])->getRowArray() ?: null;
    }

    private static function landingPage(BaseConnection $db, array $lead): ?array
    {
        if (empty($lead['landing_page_id']) || ! SchemaGuard::hasTable($db, 'reach_landing_pages')) {
            return null;
        }
        return $db->query('SELECT id, title, slug, status FROM reach_landing_pages WHERE id = ?', [(int) $lead['landing_page_id']])->getRowArray() ?: null;
    }

    /**
     * The lead's conversion links (as the conversions list shows them) and its
     * journey: the first / last touchpoints plus every touchpoint of the same
     * pseudonymous visitor.
     */
    private static function attribution(BaseConnection $db, int $leadId): array
    {
        if (! SchemaGuard::hasTable($db, 'reach_attribution_conversion_links')) {
            return ['conversions' => [], 'touchpoints' => []];
        }
        $conversions = AttributionConversions::list($db, null, ['lead_id' => (string) $leadId], 50)['items'];

        $ids = [];
        foreach ($conversions as $c) {
            foreach (['first_touchpoint_id', 'last_touchpoint_id'] as $k) {
                if (! empty($c[$k])) {
                    $ids[] = (int) $c[$k];
                }
            }
        }
        $touchpoints = [];
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $touchpoints = $db->query(
                "SELECT tp.id, tp.channel, tp.touchpoint_type, tp.utm_source, tp.utm_medium, tp.utm_campaign,
                        tp.utm_content, tp.utm_term, tp.referrer_domain, tp.campaign_id, tp.content_identity_id,
                        tp.source_event_ref, tp.touched_at
                   FROM reach_attribution_touchpoints tp
                  WHERE tp.id IN ({$in})
                     OR tp.visitor_pseudonym_hash IN (
                          SELECT visitor_pseudonym_hash FROM reach_attribution_touchpoints
                           WHERE id IN ({$in}) AND visitor_pseudonym_hash IS NOT NULL)
                  ORDER BY tp.touched_at ASC, tp.id ASC LIMIT " . self::TOUCH_LIMIT,
                [...$ids, ...$ids],
            )->getResultArray();
        }

        return ['conversions' => $conversions, 'touchpoints' => $touchpoints];
    }

    /**
     * @param list<mixed>  $params
     * @param list<string> $jsonCols decoded so the page gets objects, not strings
     */
    private static function rows(BaseConnection $db, string $table, string $sql, array $params, array $jsonCols = []): array
    {
        if (! SchemaGuard::hasTable($db, $table)) {
            return [];
        }
        $rows = $db->query($sql, $params)->getResultArray();
        foreach ($rows as &$r) {
            foreach ($jsonCols as $c) {
                if (is_string($r[$c] ?? null)) {
                    $r[$c] = json_decode($r[$c], true);
                }
            }
            if (array_key_exists('ok', $r)) {
                $r['ok'] = in_array($r['ok'], [true, 't', 'true', 1, '1'], true);
            }
        }
        unset($r);

        return $rows;
    }
}
