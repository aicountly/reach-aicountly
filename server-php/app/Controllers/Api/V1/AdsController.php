<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Ads\AdsConventions;
use App\Libraries\Ads\AdsCsvImporter;
use App\Libraries\Ads\AdsSummary;
use App\Models\AdCampaignModel;
use App\Models\AdResultModel;
use Config\Database;

/**
 * Social & Ads → Ads: the register of paid campaigns the owner places by hand
 * in Google Ads, Meta Ads Manager and LinkedIn Campaign Manager.
 *
 * Reach does not place, pause or read ads, and says so in every response that
 * carries figures. Results come from manual entry or the ads managers' CSV
 * exports (AdsCsvImporter); leads from Reach's lead capture; sign-ups from the
 * my.aicountly.com report.
 */
class AdsController extends BaseApiController
{
    /** GET v1/ads/meta — platforms, conventions, launch plan, CSV formats. */
    public function meta()
    {
        return $this->ok(AdsConventions::meta());
    }

    /** GET v1/ads/summary?from=YYYY-MM-DD&to=YYYY-MM-DD */
    public function summary()
    {
        $to = AdsConventions::date((string) $this->request->getGet('to')) ?? date('Y-m-d');
        $from = AdsConventions::date((string) $this->request->getGet('from')) ?? date('Y-m-d', strtotime($to . ' -29 days'));
        if ($from > $to) {
            return $this->fail('from must not be after to.', 422);
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > 366) {
            return $this->fail('The period is limited to 366 days.', 422);
        }

        return $this->ok((new AdsSummary(Database::connect()))->build($from, $to));
    }

    public function index()
    {
        $q = new AdCampaignModel();
        $status = trim((string) $this->request->getGet('status'));
        if ($status !== '') {
            $q->where('status', $status);
        } else {
            $q->where('status !=', 'archived');
        }
        $platform = trim((string) $this->request->getGet('platform'));
        if ($platform !== '') {
            $q->where('platform', $platform);
        }
        $rows = $q->orderBy('start_date', 'DESC')->orderBy('id', 'DESC')->findAll(500);

        return $this->ok(['items' => array_map([$this, 'present'], $rows), 'total' => count($rows)]);
    }

    public function show(int $id)
    {
        $row = (new AdCampaignModel())->find($id);
        if (! $row) {
            return $this->fail('Ad campaign not found.', 404);
        }

        return $this->ok($this->present($row));
    }

    public function store()
    {
        try {
            $row = AdsConventions::normalizeCampaign($this->input());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $row['created_by'] = $this->userId();
        $m = new AdCampaignModel();
        $m->insert($row);
        $id = (int) $m->getInsertID();
        $this->audit('ads.campaign.create', 'ad_campaign', $id, null, $row);

        return $this->ok($this->present($m->find($id)), 201);
    }

    public function update(int $id)
    {
        $m = new AdCampaignModel();
        $existing = $m->find($id);
        if (! $existing || $existing['status'] === 'archived') {
            return $this->fail('Ad campaign not found.', 404);
        }
        try {
            $row = AdsConventions::normalizeCampaign($this->input(), $existing);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $m->update($id, $row);
        $this->audit('ads.campaign.update', 'ad_campaign', $id, $existing, $row);

        return $this->ok($this->present($m->find($id)));
    }

    /** Archive (the results stay, for history). */
    public function destroy(int $id)
    {
        $m = new AdCampaignModel();
        $existing = $m->find($id);
        if (! $existing) {
            return $this->fail('Ad campaign not found.', 404);
        }
        $m->update($id, ['status' => 'archived']);
        $this->audit('ads.campaign.archive', 'ad_campaign', $id, ['status' => $existing['status']], ['status' => 'archived']);

        return $this->ok(['message' => 'Archived.']);
    }

    /**
     * POST v1/ads/campaigns/launch-plan — register the owner's soft-launch plan
     * as four `planned` campaigns (Google Search ₹600, YouTube ₹300, Meta ₹300,
     * LinkedIn ₹800 per day). Planned budgets only; no spend. A platform that
     * already has a campaign on the launch utm_campaign is left alone.
     */
    public function launchPlan()
    {
        $body = $this->input();
        $start = AdsConventions::date((string) ($body['start_date'] ?? '')) ?? date('Y-m-d');
        $landing = trim((string) ($body['landing_url'] ?? '')) ?: AdsConventions::DEFAULT_LANDING;
        $m = new AdCampaignModel();
        $created = [];
        $kept = [];
        foreach (AdsConventions::LAUNCH_PLAN as $platform => $budget) {
            $p = AdsConventions::PLATFORMS[$platform];
            $exists = (new AdCampaignModel())->where('platform', $platform)->where('utm_campaign', AdsConventions::DEFAULT_CAMPAIGN)
                ->where('status !=', 'archived')->first();
            if ($exists) {
                $kept[] = (int) $exists['id'];
                continue;
            }
            try {
                $row = AdsConventions::normalizeCampaign([
                    'platform'         => $platform,
                    'name'             => 'Soft launch — ' . $p['label'],
                    'objective'        => $platform === 'youtube' ? 'video_views' : 'signups',
                    'daily_budget_inr' => $budget,
                    'start_date'       => $start,
                    'landing_url'      => $landing,
                    'creatives'        => [['id' => $p['creative_prefix'] . '-01', 'label' => 'First creative (rename to match the ad)']],
                    'status'           => 'planned',
                    'notes'            => 'Created from the launch plan. Place it in ' . $p['manager'] . ', set the ads-manager name to match, then mark it live.',
                ]);
            } catch (\InvalidArgumentException $e) {
                return $this->fail($e->getMessage(), 422);
            }
            $row['created_by'] = $this->userId();
            $m->insert($row);
            $id = (int) $m->getInsertID();
            $created[] = $id;
            $this->audit('ads.campaign.create', 'ad_campaign', $id, null, $row + ['from' => 'launch_plan']);
        }

        return $this->ok(['created' => $created, 'already_registered' => $kept], $created === [] ? 200 : 201);
    }

    /**
     * GET v1/ads/campaigns/{id}/results?from=&to= — the campaign's day rows,
     * newest first. With from/to (the summary period) the rows are the ones
     * the summary summed for this campaign, and leads_by_day holds its UTM
     * leads per day under the summary's attribution rule (days without a
     * results row included).
     */
    public function results(int $id)
    {
        $campaign = (new AdCampaignModel())->find($id);
        if (! $campaign) {
            return $this->fail('Ad campaign not found.', 404);
        }
        $from = AdsConventions::date((string) $this->request->getGet('from'));
        $to = AdsConventions::date((string) $this->request->getGet('to'));
        if ($from !== null && $to !== null && $from > $to) {
            return $this->fail('from must not be after to.', 422);
        }
        $q = (new AdResultModel())->where('ad_campaign_id', $id);
        if ($from !== null) {
            $q->where('result_date >=', $from);
        }
        if ($to !== null) {
            $q->where('result_date <=', $to);
        }
        $rows = $q->orderBy('result_date', 'DESC')->findAll(400);
        $leads = (new AdsSummary(Database::connect()))->leadsByDay($campaign, $from ?? (string) $campaign['start_date'], $to ?? date('Y-m-d'));

        return $this->ok([
            'items' => $rows, 'total' => count($rows), 'period' => ['from' => $from, 'to' => $to],
            'leads_by_day' => (object) $leads, 'source' => AdsSummary::SOURCE_NOTE,
        ]);
    }

    /** PUT v1/ads/campaigns/{id}/results — one day typed in by hand. */
    public function saveResult(int $id)
    {
        $campaign = (new AdCampaignModel())->find($id);
        if (! $campaign || $campaign['status'] === 'archived') {
            return $this->fail('Ad campaign not found.', 404);
        }
        $body = $this->input();
        $date = AdsConventions::date((string) ($body['result_date'] ?? ''));
        if ($date === null) {
            return $this->fail('result_date is required (YYYY-MM-DD).', 422);
        }
        if ($date > date('Y-m-d')) {
            return $this->fail('Results cannot be entered for a future day.', 422);
        }
        $values = [];
        foreach (['spend_inr' => 'money', 'impressions' => 'int', 'clicks' => 'int', 'conversions' => 'money', 'signups' => 'int'] as $field => $kind) {
            if (! array_key_exists($field, $body)) {
                continue;
            }
            $v = $body[$field];
            if ($v === null || $v === '') {
                $values[$field] = null;
                continue;
            }
            if (! is_numeric($v) || (float) $v < 0 || ($kind === 'int' && (float) $v !== floor((float) $v))) {
                return $this->fail("{$field} must be " . ($kind === 'int' ? 'a whole number' : 'a number') . ' of 0 or more.', 422);
            }
            $values[$field] = $kind === 'int' ? (int) $v : number_format((float) $v, 2, '.', '');
        }
        if ($values === []) {
            return $this->fail('Enter at least one figure.', 422);
        }
        if (array_intersect(array_keys($values), ['spend_inr', 'impressions', 'clicks', 'conversions']) !== []) {
            $values['metrics_source'] = 'manual';
        }
        if (array_key_exists('signups', $values)) {
            $values['signups_source'] = 'manual';
        }

        $m = new AdResultModel();
        $existing = $m->where('ad_campaign_id', $id)->where('result_date', $date)->first();
        if ($existing) {
            $m->update((int) $existing['id'], $values);
            $rowId = (int) $existing['id'];
        } else {
            $m->insert($values + ['ad_campaign_id' => $id, 'result_date' => $date, 'created_by' => $this->userId()]);
            $rowId = (int) $m->getInsertID();
        }
        $this->audit('ads.results.save', 'ad_campaign', $id, $existing ?: null, $values + ['result_date' => $date]);

        return $this->ok((new AdResultModel())->find($rowId), $existing ? 200 : 201);
    }

    public function deleteResult(int $resultId)
    {
        $m = new AdResultModel();
        $row = $m->find($resultId);
        if (! $row) {
            return $this->fail('Result not found.', 404);
        }
        $m->delete($resultId);
        $this->audit('ads.results.delete', 'ad_campaign', (int) $row['ad_campaign_id'], $row, null);

        return $this->ok(['message' => 'Deleted.']);
    }

    /**
     * POST v1/ads/import — multipart `file` (or JSON `csv`), `format`
     * (google_ads | meta | linkedin | aicountly_signups | auto), `dry_run`.
     */
    public function import()
    {
        $file = $this->request->getFile('file');
        $body = $this->input();
        if ($file !== null && $file->isValid()) {
            if ($file->getSize() > AdsCsvImporter::MAX_BYTES) {
                return $this->fail('The file is larger than 2 MB. Export a shorter date range.', 413);
            }
            $csv = (string) file_get_contents($file->getTempName());
        } else {
            $csv = is_string($body['csv'] ?? null) ? $body['csv'] : '';
        }
        if (trim($csv) === '') {
            return $this->fail('Attach the CSV exported from the ads manager.', 422);
        }
        $format = (string) ($body['format'] ?? $this->request->getPost('format') ?? 'auto');
        if ($format !== 'auto' && ! isset(AdsCsvImporter::FORMATS[$format])) {
            return $this->fail('Unknown format.', 422);
        }
        $dry = filter_var($body['dry_run'] ?? $this->request->getPost('dry_run') ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $report = (new AdsCsvImporter(Database::connect()))->import($csv, $format, $dry, $this->userId());
        } catch (\RuntimeException $e) {
            log_message('error', '[ads.import] ' . $e->getMessage());

            return $this->fail($e->getMessage(), 500);
        }
        if ($report['error'] !== null) {
            return $this->fail($report['error'], 422, $report);
        }
        if (! $dry) {
            $this->audit('ads.results.import', 'ad_campaign', null, null, [
                'format' => $report['format'], 'stored_days' => $report['stored_days'], 'rows_used' => $report['rows_used'],
                'unmatched' => $report['unmatched_total'] ?? 0, 'rejected' => $report['rejected_total'],
            ]);
        }

        return $this->ok($report);
    }

    /** POST v1/ads/utm-link — build a tracking link under Reach's conventions. */
    public function utmLink()
    {
        $b = $this->input();
        try {
            $url = AdsConventions::buildLink(
                trim((string) ($b['landing_url'] ?? AdsConventions::DEFAULT_LANDING)),
                (string) ($b['platform'] ?? ''),
                [
                    'utm_campaign' => strtolower(trim((string) ($b['utm_campaign'] ?? ''))),
                    'utm_content'  => strtolower(trim((string) ($b['creative_id'] ?? $b['utm_content'] ?? ''))),
                    'utm_term'     => strtolower(trim((string) ($b['utm_term'] ?? ''))),
                ],
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(['url' => $url]);
    }

    /** A campaign row with its tracking links. */
    private function present(array $row): array
    {
        $row['daily_budget_inr'] = $row['daily_budget_inr'] !== null ? (float) $row['daily_budget_inr'] : null;
        $row['tracking_links'] = AdsConventions::trackingLinks($row);
        $row['platform_label'] = AdsConventions::PLATFORMS[$row['platform']]['label'] ?? $row['platform'];

        return $row;
    }
}
