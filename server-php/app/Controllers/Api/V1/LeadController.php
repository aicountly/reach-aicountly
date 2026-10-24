<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Leads\LeadDetail;
use App\Models\CampaignModel;
use App\Models\LeadModel;
use Config\Services;

class LeadController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new LeadModel();
        foreach (['engage_push_status', 'source_kind', 'priority'] as $f) {
            $v = trim((string) $this->request->getGet($f));
            if ($v !== '') {
                $q->where($f, $v);
            }
        }
        // Drill links: a campaign's / landing page's leads, one lead (lead_id), and
        // "captured in the last N days" — the same predicate as analytics/summary.
        foreach (['lead_id' => 'id', 'campaign_id' => 'campaign_id', 'landing_page_id' => 'landing_page_id'] as $key => $col) {
            $v = trim((string) $this->request->getGet($key));
            if ($v !== '' && ctype_digit($v)) {
                $q->where($col, (int) $v);
            }
        }
        $days = trim((string) $this->request->getGet('days'));
        if ($days !== '' && ctype_digit($days) && (int) $days >= 1 && (int) $days <= 365) {
            $q->where('created_at >=', date('Y-m-d H:i:s', strtotime('-' . (int) $days . ' days')));
        }
        // An ad campaign's leads, with the same predicate AdsSummary::leads()
        // counts by: case- and space-insensitive UTM match, a blank medium also
        // matches, and created_from..created_to inclusive.
        $utmSource   = strtolower(trim((string) $this->request->getGet('utm_source')));
        $utmCampaign = strtolower(trim((string) $this->request->getGet('utm_campaign')));
        $utmMedium   = strtolower(trim((string) $this->request->getGet('utm_medium')));
        if ($utmSource !== '') {
            $q->where('LOWER(TRIM(utm_source))', $utmSource);
        }
        if ($utmCampaign !== '') {
            $q->where('LOWER(TRIM(utm_campaign))', $utmCampaign);
        }
        if ($utmMedium !== '') {
            $q->groupStart()
                ->where('utm_medium', null)
                ->orWhere("TRIM(utm_medium) = ''", null, false)
                ->orWhere('LOWER(TRIM(utm_medium))', $utmMedium)
              ->groupEnd();
        }
        $isDate = static fn (string $v): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
        $createdFrom = trim((string) $this->request->getGet('created_from'));
        $createdTo   = trim((string) $this->request->getGet('created_to'));
        if ($isDate($createdFrom)) {
            $q->where('created_at >=', $createdFrom);
        }
        if ($isDate($createdTo)) {
            $q->where('created_at <', date('Y-m-d', strtotime($createdTo . ' +1 day')));
        }
        $search = trim((string) $this->request->getGet('search'));
        if ($search !== '') {
            $q->groupStart()
                ->like('name', $search)
                ->orLike('email', $search)
                ->orLike('mobile', $search)
                ->orLike('organization', $search)
                ->orLike('engage_lead_code', $search)
                ->orLike('ref_code', $search)
              ->groupEnd();
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('created_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /**
     * One lead plus `related` (LeadDetail): campaign, landing page, Engage push
     * attempts, outcomes, attribution, social inbox origin and audit trail —
     * the lead detail page's single call.
     */
    public function show(int $id)
    {
        $m   = new LeadModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Lead not found.', 404);
        }
        $row['related'] = LeadDetail::related($m->db, $row);
        return $this->ok($row);
    }

    public function store()
    {
        $body = $this->input();
        $row  = $this->normalize($body);
        $row['created_by'] = $this->userId();
        $m = new LeadModel();
        $m->insert($row);
        $id = (int) $m->db->insertID();
        $this->audit('lead.create', 'lead', $id, null, $row);
        return $this->ok($m->find($id), 201);
    }

    public function update(int $id)
    {
        $m = new LeadModel();
        if (! $m->find($id)) {
            return $this->fail('Lead not found.', 404);
        }
        $update = $this->normalize($this->input(), partial: true);
        $m->update($id, $update);
        $this->audit('lead.update', 'lead', $id, null, $update);
        return $this->ok($m->find($id));
    }

    public function destroy(int $id)
    {
        $m = new LeadModel();
        if (! $m->find($id)) {
            return $this->fail('Lead not found.', 404);
        }
        $m->delete($id);
        $this->audit('lead.delete', 'lead', $id);
        return $this->ok(['message' => 'Deleted.']);
    }

    /**
     * Public capture endpoint for embedded forms / landing pages.
     * Guarded by PublicCaptureFilter (X-Public-Capture-Token).
     */
    public function publicCapture()
    {
        $body = $this->input();
        if (trim((string) ($body['name'] ?? '')) === '') {
            return $this->fail('name is required.', 422);
        }
        $row = $this->normalize($body);
        $row['raw_payload'] = $body;
        $m = new LeadModel();
        $m->insert($row);
        $id = (int) $m->db->insertID();

        // Auto-push immediately (no approval needed for internal-only Engage push).
        $lead = $m->find($id);
        try {
            $result = Services::engageClient()->pushLead($this->enrichForEngage($lead));
            $this->audit('lead.public_capture', 'lead', $id, null, ['engage_status' => $result['status']]);
            return $this->ok(['lead_id' => $id, 'engage_push_status' => $result['status']], 201);
        } catch (\Throwable $e) {
            log_message('error', 'Public capture engage push failed: ' . $e->getMessage());
            return $this->ok(['lead_id' => $id, 'engage_push_status' => 'retry_scheduled'], 201);
        }
    }

    private function normalize(array $body, bool $partial = false): array
    {
        $out = array_intersect_key($body, array_flip([
            'name', 'email', 'mobile', 'whatsapp', 'organization',
            'source_kind', 'campaign_id', 'landing_page_id',
            'product_interest', 'priority', 'notes',
        ]));
        if (isset($out['email'])) {
            $out['email'] = strtolower(trim((string) $out['email']));
        }
        $out += self::attributionFields($body);
        if (! $partial) {
            $out['name']     ??= '';
            $out['priority'] ??= 'normal';
        }
        return $out;
    }

    /**
     * Seller referral code and UTM / landing data from a capture body.
     *
     * Accepts flat `ref`, `utm_source` … `utm_term`, `landing_url`, `referrer` (or
     * `referrer_url`), and a nested `utm: {source, medium, campaign, content, term}`.
     * Values that do not fit are dropped rather than failing the capture: a lead is
     * never lost over a malformed tracking parameter.
     *
     * @return array<string,string>
     */
    public static function attributionFields(array $body): array
    {
        $out = [];
        $ref = $body['ref'] ?? $body['ref_code'] ?? null;
        if (is_string($ref)) {
            $ref = strtoupper(trim($ref));
            if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,31}\z/', $ref) === 1) {
                $out['ref_code'] = $ref;
            }
        }

        $utm = is_array($body['utm'] ?? null) ? $body['utm'] : [];
        foreach (['source' => 120, 'medium' => 120, 'campaign' => 240, 'content' => 240, 'term' => 240] as $k => $max) {
            $v = $body['utm_' . $k] ?? $utm[$k] ?? null;
            if (is_string($v) || is_int($v)) {
                $v = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $v) ?? '');
                if ($v !== '') {
                    $out['utm_' . $k] = mb_substr($v, 0, $max);
                }
            }
        }

        foreach (['landing_url' => ['landing_url', 'page_url'], 'referrer_url' => ['referrer', 'referrer_url']] as $col => $keys) {
            foreach ($keys as $key) {
                $v = $body[$key] ?? null;
                if (is_string($v)) {
                    $v = trim($v);
                    $scheme = strtolower((string) parse_url($v, PHP_URL_SCHEME));
                    if ($v !== '' && strlen($v) <= 2000 && in_array($scheme, ['http', 'https'], true)
                        && filter_var($v, FILTER_VALIDATE_URL) !== false) {
                        $out[$col] = $v;
                        break;
                    }
                }
            }
        }

        return $out;
    }

    private function enrichForEngage(array $lead): array
    {
        if (! empty($lead['campaign_id'])) {
            $c = (new CampaignModel())->find((int) $lead['campaign_id']);
            if ($c) {
                $lead['campaign_code'] = 'REACH-' . $c['id'];
                $lead['campaign_name'] = $c['name'];
                $lead['campaign_kind'] = $c['campaign_type'];
            }
        }
        return $lead;
    }
}
