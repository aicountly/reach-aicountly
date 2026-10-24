<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Distribution\SocialInbox\FlowTicketClient;
use App\Libraries\Distribution\SocialInbox\SocialInboxService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;

/**
 * Social → Inbox: what the social inbox bot read and did.
 *
 *   GET  v1/social/inbox               list (status, classification, network, severity, reply_status, has=ticket|lead,
 *                                      waiting=flow, since_hours, replied_since_hours, updated_since_hours, search, page, limit)
 *   GET  v1/social/inbox/summary       24 h / 7 day counts, the last run, configuration state
 *   POST v1/social/inbox/{id}/retry    handle a failed item again (social.dispatch)
 */
class SocialInboxController extends BaseApiController
{
    private const LIST_COLUMNS = 'id, network, account_id, external_id, kind, thread_id, author_name, author_handle, text, post_url, received_at, fetched_at,
        status, status_reason, attempts, classification, confidence, severity, severity_reason, personal_data, summary, classifier_model,
        reply_text, reply_status, reply_reason, replied_at, vetting_decision, vetting_reasons_json, ticket_number, ticket_id, ticket_error,
        lead_id, owner_alerted_at, updated_at';

    public function index()
    {
        $db = Database::connect();
        if (! SchemaGuard::hasTable($db, 'reach_social_inbox')) {
            return $this->ok(['items' => [], 'total' => 0, 'page' => 1, 'limit' => 25, 'note' => 'Run the database migrations (reach_social_inbox is missing).']);
        }
        [$page, $limit, $offset] = $this->pagination(25, 100);
        $q = $db->table('reach_social_inbox');
        foreach (['status', 'classification', 'network', 'severity', 'reply_status'] as $f) {
            $v = trim((string) $this->request->getGet($f));
            if ($v !== '' && preg_match('/^[a-z_]{1,32}$/', $v)) {
                $q->where($f, $v);
            }
        }
        // The summary tiles' predicates (SocialInboxService::stats), so a tile
        // opens exactly the rows it counted.
        $has = (string) $this->request->getGet('has');
        if ($has === 'ticket') {
            $q->where('ticket_number IS NOT NULL', null, false);
        } elseif ($has === 'lead') {
            $q->where('lead_id IS NOT NULL', null, false);
        }
        // waiting=flow: complaints not yet lodged in Flow and still queued for
        // another try — the "complaints waiting for Flow" count (no time window).
        if ((string) $this->request->getGet('waiting') === 'flow') {
            $q->where('classification', 'complaint')->where('ticket_number IS NULL', null, false)->whereIn('status', ['deferred', 'retry']);
        }
        // updated_since_hours: reply failures are counted by when the row last
        // changed (the failed attempt), not when it was read.
        foreach (['since_hours' => 'fetched_at', 'replied_since_hours' => 'replied_at', 'updated_since_hours' => 'updated_at'] as $param => $col) {
            $h = trim((string) $this->request->getGet($param));
            if ($h !== '' && ctype_digit($h) && (int) $h >= 1 && (int) $h <= 24 * 90) {
                $q->where($col . ' >=', gmdate('Y-m-d H:i:sP', time() - (int) $h * 3600));
            }
        }
        $search = trim((string) $this->request->getGet('search'));
        if ($search !== '') {
            $q->groupStart()->like('text', $search, 'both', null, true)->orLike('author_handle', $search, 'both', null, true)
                ->orLike('author_name', $search, 'both', null, true)->orLike('ticket_number', $search, 'both', null, true)->groupEnd();
        }
        $total = $q->countAllResults(false);
        $rows  = $q->select(self::LIST_COLUMNS)->orderBy('received_at', 'DESC', false)->orderBy('id', 'DESC')->get($limit, $offset)->getResultArray();
        $flowWeb = self::flowWebBase();
        foreach ($rows as &$r) {
            $r['vetting_reasons'] = json_decode((string) ($r['vetting_reasons_json'] ?? '[]'), true) ?: [];
            unset($r['vetting_reasons_json']);
            $r['personal_data'] = in_array($r['personal_data'], [true, 't', 'true', 1, '1'], true);
            $r['ticket_url']    = $flowWeb !== null && ! empty($r['ticket_id']) ? $flowWeb . '/desk/tickets/' . (int) $r['ticket_id'] : null;
        }
        unset($r);

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function summary()
    {
        $db  = Database::connect();
        $now = time();
        $cfg = [
            'enabled'         => (bool) ReachConfig::get('social.inbox.enabled', true),
            'auto_reply'      => (bool) ReachConfig::get('social.inbox.auto_reply', true),
            'social_api_key'  => ReachConfig::secret('social.socialapi.api_key') !== null,
            'flow'            => FlowTicketClient::fromConfig()->configurationProblem() ?? 'configured',
            'platforms'       => SocialInboxService::platforms(),
            'daily_reply_cap' => (int) ReachConfig::get('social.inbox.daily_reply_cap', 40),
            'min_confidence'  => (int) ReachConfig::get('social.inbox.min_confidence', 70),
            'hide_spam'       => (bool) ReachConfig::get('social.inbox.hide_spam', false),
            'list_path'       => (string) ReachConfig::get('social.inbox.list_path'),
        ];
        if (! SchemaGuard::hasTable($db, 'reach_social_inbox')) {
            return $this->ok(['config' => $cfg, 'note' => 'Run the database migrations (reach_social_inbox is missing).']);
        }
        $end  = gmdate('Y-m-d H:i:sP', $now + 60);
        $last = $db->query(
            "SELECT status, result_json, error_message, completed_at, updated_at FROM reach_jobs WHERE job_type = ? ORDER BY id DESC LIMIT 1",
            [SocialInboxService::JOB_TYPE]
        )->getRowArray();
        if ($last !== null) {
            $last['result'] = is_string($last['result_json'] ?? null) ? json_decode($last['result_json'], true) : ($last['result_json'] ?? null);
            unset($last['result_json']);
        }

        return $this->ok([
            'config'   => $cfg,
            'last_24h' => SocialInboxService::stats($db, gmdate('Y-m-d H:i:sP', $now - 86400), $end),
            'last_7d'  => SocialInboxService::stats($db, gmdate('Y-m-d H:i:sP', $now - 7 * 86400), $end),
            'last_run' => $last,
        ]);
    }

    public function retry(int $id)
    {
        $db  = Database::connect();
        $row = $db->table('reach_social_inbox')->select('id, status, reply_status')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            return $this->fail('Inbox item not found.', 404);
        }
        if ($row['status'] !== 'failed' && $row['reply_status'] !== 'failed') {
            return $this->fail('Only failed items can be retried.', 409);
        }
        $reset = ['attempts' => 0, 'last_error' => null, 'updated_at' => gmdate('Y-m-d H:i:s') . '+00'];
        if ($row['status'] === 'failed') {
            $reset['status'] = 'new';
        }
        if ($row['reply_status'] === 'failed') {
            $reset['reply_status'] = 'none';
        }
        $db->table('reach_social_inbox')->where('id', $id)->update($reset);
        $this->audit('social.inbox.retry', 'social_inbox', $id, ['status' => $row['status'], 'reply_status' => $row['reply_status']]);
        $status = SocialInboxService::fromConfig()->processOne($id);

        return $this->ok(['id' => $id, 'status' => $status]);
    }

    /** https://flow.aicountly.org for https://flow.aicountly.org/api (ticket links in the UI). */
    private static function flowWebBase(): ?string
    {
        $api = rtrim(trim((string) ReachConfig::get('flow.api_base_url', '')), '/');
        if ($api === '' || ! preg_match('#^https://#i', $api)) {
            return null;
        }
        return preg_replace('#/api$#', '', $api) ?? $api;
    }
}
