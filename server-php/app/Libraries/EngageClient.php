<?php

namespace App\Libraries;

use App\Models\EngagePushAttemptModel;
use App\Models\LeadModel;
use Config\Services;

/**
 * Push a Reach lead to Engage.
 *
 * Endpoint (from engage-aicountly Routes.php):
 *   POST {ENGAGE_API_BASE_URL}/internal/reach/leads
 *   Header: X-Portal-Token: <ENGAGE_INBOUND_TOKEN>
 *
 * Engage's response envelope is { ok: true, data: {...} } on success,
 * { ok: false, error: '...' } on failure (matches our own envelope).
 *
 * Engage returns 201 for both create and update (no dedupe signal), so
 * duplicate detection happens locally against reach_leads.
 */
class EngageClient
{
    private string $baseUrl;
    private string $token;
    private EngagePushAttemptModel $attempts;
    private LeadModel $leads;

    public function __construct()
    {
        $this->baseUrl  = rtrim((string) env('ENGAGE_API_BASE_URL', ''), '/');
        $this->token    = (string) env('ENGAGE_INBOUND_TOKEN', '');
        $this->attempts = new EngagePushAttemptModel();
        $this->leads    = new LeadModel();
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * @return array{
     *   status:string,               // pushed|failed|duplicate|rejected|retry_scheduled
     *   attempt:array,               // the recorded attempt row
     *   response?:array,             // decoded Engage response
     *   engage_lead_code?:string,
     * }
     */
    public function pushLead(array $lead): array
    {
        $leadId = (int) ($lead['id'] ?? 0);
        $email  = strtolower((string) ($lead['email'] ?? ''));

        // ---- Local duplicate check ----
        if ($email !== '' && $this->leads->isRecentDuplicate($email, 86400)) {
            $this->leads->update($leadId, [
                'engage_push_status'    => 'duplicate',
                'engage_push_attempts'  => ((int) ($lead['engage_push_attempts'] ?? 0)) + 1,
                'last_push_at'          => date('Y-m-d H:i:s'),
                'last_push_error'       => null,
            ]);
            $attempt = $this->recordAttempt(
                leadId: $leadId,
                attempt: ((int) ($lead['engage_push_attempts'] ?? 0)) + 1,
                body: [],
                status: null,
                response: ['ok' => true, 'skipped' => 'duplicate_within_24h'],
                error: null,
                ok: true,
            );
            return ['status' => 'duplicate', 'attempt' => $attempt];
        }

        if (! $this->isConfigured()) {
            $this->leads->update($leadId, [
                'engage_push_status'   => 'retry_scheduled',
                'engage_push_attempts' => ((int) ($lead['engage_push_attempts'] ?? 0)) + 1,
                'last_push_at'         => date('Y-m-d H:i:s'),
                'last_push_error'      => 'ENGAGE_API_BASE_URL / ENGAGE_INBOUND_TOKEN not configured',
            ]);
            $attempt = $this->recordAttempt(
                leadId: $leadId,
                attempt: ((int) ($lead['engage_push_attempts'] ?? 0)) + 1,
                body: [],
                status: null,
                response: null,
                error: 'Engage client not configured',
                ok: false,
            );
            return ['status' => 'retry_scheduled', 'attempt' => $attempt];
        }

        $endpoint = $this->baseUrl . '/internal/reach/leads';
        $urlCheck = Services::urlPolicy()->validate($endpoint, [
            'allowedHosts' => array_filter([parse_url($this->baseUrl, PHP_URL_HOST) ?: null]),
        ]);
        if (! $urlCheck->allowed) {
            $attempt = $this->recordAttempt(
                $leadId,
                ((int) ($lead['engage_push_attempts'] ?? 0)) + 1,
                [],
                null,
                null,
                'URL policy rejected Engage endpoint: ' . ($urlCheck->reason ?? 'invalid'),
                false,
            );
            $this->markLeadStatus($leadId, 'failed', $urlCheck->reason ?? 'URL policy rejected endpoint', $lead);
            return ['status' => 'failed', 'attempt' => $attempt];
        }

        $body = $this->buildBody($lead);
        $ch   = curl_init($endpoint);
        if ($ch === false) {
            $attempt = $this->recordAttempt($leadId, ((int) ($lead['engage_push_attempts'] ?? 0)) + 1, $body, null, null, 'curl_init failed', false);
            $this->markLeadStatus($leadId, 'retry_scheduled', 'curl_init failed', $lead);
            return ['status' => 'retry_scheduled', 'attempt' => $attempt];
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => array_filter([
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Portal-Token: ' . $this->token,
                'X-Source: reach.aicountly.org',
                $this->currentRequestId() ? 'X-Request-Id: ' . $this->currentRequestId() : null,
            ]),
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $ok      = $raw !== false && $status >= 200 && $status < 300 && is_array($decoded) && ($decoded['ok'] ?? false);

        // ---- Classify Engage result ----
        $newStatus = 'failed';
        $error     = null;
        if ($ok) {
            $newStatus = 'pushed';
        } elseif ($status === 422) {
            $newStatus = 'rejected';
            $error     = (string) ($decoded['error'] ?? 'Engage validation rejected the lead.');
        } elseif ($status >= 500 || $status === 0) {
            $newStatus = 'retry_scheduled';
            $error     = $err !== '' ? $err : ('Engage HTTP ' . $status);
        } else {
            $error = (string) ($decoded['error'] ?? ($err !== '' ? $err : 'Engage HTTP ' . $status));
        }

        $engageCode = null;
        if ($ok && is_array($decoded['data'] ?? null)) {
            $engageCode = (string) ($decoded['data']['data']['lead']['lead_code']
                ?? $decoded['data']['lead']['lead_code']
                ?? '');
            $engageCode = $engageCode !== '' ? $engageCode : null;
        }

        $attempt = $this->recordAttempt(
            leadId: $leadId,
            attempt: ((int) ($lead['engage_push_attempts'] ?? 0)) + 1,
            body: $body,
            status: $status,
            response: is_array($decoded) ? $decoded : null,
            error: $error,
            ok: $ok,
        );

        $this->markLeadStatus($leadId, $newStatus, $error, $lead, $engageCode);

        $out = ['status' => $newStatus, 'attempt' => $attempt];
        if (is_array($decoded)) {
            $out['response'] = $decoded;
        }
        if ($engageCode) {
            $out['engage_lead_code'] = $engageCode;
        }
        return $out;
    }

    private function currentRequestId(): ?string
    {
        try {
            $req = service('request');
            $id  = $req->reachRequestId ?? null;
            return is_string($id) && $id !== '' ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildBody(array $lead): array
    {
        return array_filter([
            'source_portal'      => 'reach.aicountly.org',
            'name'               => $lead['name']            ?? '',
            'email'              => $lead['email']           ?? null,
            'mobile'             => $lead['mobile']          ?? null,
            'whatsapp'           => $lead['whatsapp']        ?? null,
            'organization'       => $lead['organization']    ?? null,
            'campaign_code'      => $lead['campaign_code']   ?? null,
            'campaign_name'      => $lead['campaign_name']   ?? null,
            'campaign_kind'      => $lead['campaign_kind']   ?? null,
            'interested_product' => $lead['product_interest']?? null,
            'notes'              => $lead['notes']           ?? null,
            'priority'           => $lead['priority']        ?? 'normal',
            'reach_meta'         => array_filter([
                'reach_lead_id'   => (int) ($lead['id'] ?? 0),
                'source_kind'     => $lead['source_kind']     ?? null,
                'landing_page_id' => $lead['landing_page_id'] ?? null,
            ]),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function recordAttempt(
        int $leadId,
        int $attempt,
        array $body,
        ?int $status,
        ?array $response,
        ?string $error,
        bool $ok,
    ): array {
        $row = [
            'lead_id'         => $leadId,
            'attempt_number'  => $attempt,
            'request_body'    => json_encode($body, JSON_UNESCAPED_SLASHES),
            'response_status' => $status,
            'response_body'   => $response !== null ? json_encode($response, JSON_UNESCAPED_SLASHES) : null,
            'error_message'   => $error,
            'ok'              => $ok,
            'attempted_at'    => date('Y-m-d H:i:s'),
        ];
        try {
            $this->attempts->insert($row);
            $row['id'] = (int) $this->attempts->db->insertID();
        } catch (\Throwable $e) {
            log_message('error', 'EngagePushAttempt write failed: ' . $e->getMessage());
        }
        return $row;
    }

    private function markLeadStatus(int $leadId, string $status, ?string $error, array $lead, ?string $engageCode = null): void
    {
        $update = [
            'engage_push_status'   => $status,
            'engage_push_attempts' => ((int) ($lead['engage_push_attempts'] ?? 0)) + 1,
            'last_push_at'         => date('Y-m-d H:i:s'),
            'last_push_error'      => $error,
        ];
        if ($engageCode) {
            $update['engage_lead_code'] = $engageCode;
        }
        try {
            $this->leads->update($leadId, $update);
        } catch (\Throwable $e) {
            log_message('error', 'Lead status update failed: ' . $e->getMessage());
        }
    }
}
