<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use CodeIgniter\Database\BaseConnection;

/**
 * Finds distribution records that claim a delivery no real provider made
 * (`reach:distribution-audit`).
 *
 * Fixable (--fix marks them failed with a reason):
 *   social_mock_posted     social posts `posted` with a mock provider / `mock-post-…` id
 *   attempt_mock_accepted  delivery attempts accepted/sent/delivered by a mock provider
 *   email_mock_sent        email campaigns `sent` whose stats name a mock provider
 *   whatsapp_mock_sent     WhatsApp campaigns `sent` whose stats name a mock provider
 *   sms_mock_completed     SMS campaigns whose only accepted attempts came from a mock
 *
 * Informational (listed, never changed):
 *   attempt_skipped          attempts recorded `skipped` (SMS_SKIP_SEND / WHATSAPP_SKIP_SEND)
 *   attempt_unverified_gateway  legacy SMS/WhatsApp attempts "accepted" with a locally
 *                            made-up id (sms-… / wa-…): before this change SKIP_SEND (on by
 *                            default) reported success without calling the gateway, so these
 *                            may never have been sent. Check the gateway's own logs.
 */
class DistributionAuditService
{
    public const FIX_REASON = 'Marked failed by reach:distribution-audit: recorded as delivered by a mock provider; nothing was actually sent.';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /** @return array<string, list<array<string,mixed>>> */
    public function findings(int $limit = 500): array
    {
        $delivered = "('accepted','sent','delivered','read')";
        $limit     = max(1, $limit);

        return [
            'social_mock_posted' => $this->db->query(
                "SELECT id, channel, status, external_post_id, provider, published_at FROM reach_social_posts
                  WHERE status = 'posted'
                    AND (external_post_id LIKE 'mock-%' OR remote_post_id LIKE 'mock-%' OR provider LIKE 'mock%' OR remote_url LIKE '%mock-social.example.com%')
                  ORDER BY id LIMIT {$limit}"
            )->getResultArray(),
            'attempt_mock_accepted' => $this->db->query(
                "SELECT id, dispatch_id, recipient_id, status, provider, provider_message_id, idempotency_key, created_at
                   FROM reach_campaign_delivery_attempts
                  WHERE status IN {$delivered} AND (provider LIKE 'mock%' OR provider_message_id LIKE 'mock-%')
                  ORDER BY id LIMIT {$limit}"
            )->getResultArray(),
            'email_mock_sent' => $this->db->query(
                "SELECT id, subject, status, sent_at FROM reach_email_campaigns
                  WHERE status = 'sent' AND stats IS NOT NULL AND stats::text LIKE '%mock\\_%'
                  ORDER BY id LIMIT {$limit}"
            )->getResultArray(),
            'whatsapp_mock_sent' => $this->db->query(
                "SELECT id, template_name, status, sent_at FROM reach_whatsapp_campaigns
                  WHERE status = 'sent' AND stats IS NOT NULL AND stats::text LIKE '%mock\\_%'
                  ORDER BY id LIMIT {$limit}"
            )->getResultArray(),
            'sms_mock_completed' => $this->db->query(
                "SELECT c.id, c.name, c.status FROM reach_campaigns c
                  WHERE c.status IN ('completed','partially_completed')
                    AND EXISTS (SELECT 1 FROM reach_campaign_delivery_attempts a
                                 WHERE a.idempotency_key LIKE 'sms-dispatch:' || c.id || ':%' AND a.provider LIKE 'mock%' AND a.status IN {$delivered})
                  ORDER BY c.id LIMIT {$limit}"
            )->getResultArray(),
            'attempt_skipped' => $this->db->query(
                "SELECT id, dispatch_id, provider, failure_detail, created_at FROM reach_campaign_delivery_attempts
                  WHERE status = 'skipped' ORDER BY id DESC LIMIT {$limit}"
            )->getResultArray(),
            'attempt_unverified_gateway' => $this->db->query(
                "SELECT id, dispatch_id, provider, provider_message_id, created_at FROM reach_campaign_delivery_attempts
                  WHERE status IN {$delivered}
                    AND provider IN ('aoc_portal_sms','infobip_whatsapp')
                    AND (provider_message_id LIKE 'sms-%' OR provider_message_id LIKE 'wa-%')
                  ORDER BY id LIMIT {$limit}"
            )->getResultArray(),
        ];
    }

    public static function fixableKinds(): array
    {
        return ['social_mock_posted', 'attempt_mock_accepted', 'email_mock_sent', 'whatsapp_mock_sent', 'sms_mock_completed'];
    }

    /**
     * Mark every fixable finding failed with FIX_REASON.
     *
     * @return array<string,int> rows changed per kind
     */
    public function fix(?int $actorId = null): array
    {
        $findings = $this->findings(100000);
        $now      = date('Y-m-d H:i:s');
        $changed  = [];

        $ids = array_column($findings['social_mock_posted'], 'id');
        if ($ids !== []) {
            $this->db->table('reach_social_posts')->whereIn('id', $ids)->update([
                'status' => 'failed', 'error_message' => self::FIX_REASON, 'updated_at' => $now,
            ]);
        }
        $changed['social_mock_posted'] = count($ids);

        $ids = array_column($findings['attempt_mock_accepted'], 'id');
        if ($ids !== []) {
            $this->db->table('reach_campaign_delivery_attempts')->whereIn('id', $ids)->update([
                'status' => 'failed', 'failure_class' => 'not_configured', 'failure_detail' => self::FIX_REASON, 'failed_at' => $now,
            ]);
        }
        $changed['attempt_mock_accepted'] = count($ids);

        foreach (['email_mock_sent' => 'reach_email_campaigns', 'whatsapp_mock_sent' => 'reach_whatsapp_campaigns'] as $kind => $table) {
            $ids = array_column($findings[$kind], 'id');
            if ($ids !== []) {
                $this->db->table($table)->whereIn('id', $ids)->update([
                    'status' => 'failed',
                    'stats'  => json_encode(['outcome' => 'failed', 'error' => self::FIX_REASON, 'audited_at' => date('c')]),
                    'updated_at' => $now,
                ]);
            }
            $changed[$kind] = count($ids);
        }

        $ids = array_column($findings['sms_mock_completed'], 'id');
        if ($ids !== []) {
            $this->db->table('reach_campaigns')->whereIn('id', $ids)->update(['status' => 'failed', 'updated_at' => $now]);
        }
        $changed['sms_mock_completed'] = count($ids);

        // Dispatch counters follow the corrected attempts.
        $this->db->query(
            "UPDATE reach_campaign_dispatches d
                SET sent_count = (SELECT COUNT(*) FROM reach_campaign_delivery_attempts a WHERE a.dispatch_id = d.id AND a.status IN ('accepted','sent','delivered','read')),
                    failed_count = (SELECT COUNT(*) FROM reach_campaign_delivery_attempts a WHERE a.dispatch_id = d.id AND a.status IN ('failed','bounced','complained')),
                    updated_at = NOW()
              WHERE EXISTS (SELECT 1 FROM reach_campaign_delivery_attempts a WHERE a.dispatch_id = d.id AND a.failure_detail = ?)",
            [self::FIX_REASON]
        );
        $this->db->query(
            "UPDATE reach_campaign_dispatches SET status = 'failed', last_error = ?, updated_at = NOW()
              WHERE status IN ('completed','partially_completed') AND sent_count = 0
                AND EXISTS (SELECT 1 FROM reach_campaign_delivery_attempts a WHERE a.dispatch_id = reach_campaign_dispatches.id AND a.failure_detail = ?)",
            [self::FIX_REASON, self::FIX_REASON]
        );

        if (array_sum($changed) > 0) {
            AuditLogger::record(AuditLogger::DISTRIBUTION_AUDIT_FIXED, ['changed' => $changed], $actorId);
        }

        return $changed;
    }
}
