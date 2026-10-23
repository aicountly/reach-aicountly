import { useCallback, useEffect, useMemo, useState } from 'react';
import { Info } from 'lucide-react';
import { adsService } from '../../../services/adsService';
import { Card } from '../../../components/common/Card';
import { Alert } from '../../../components/common/Alert';
import { Loader } from '../../../components/common/Loader';
import { EmptyState } from '../../../components/common/EmptyState';
import { usePermission } from '../../../hooks/usePermission';
import { formatInr } from '../../../utils/formatInr';
import { formatDate, todayInputValue } from '../../../utils/formatDate';
import { ADS_PLATFORM_LIST, ADS_PLATFORMS, DEFAULT_CAMPAIGN, DEFAULT_LANDING, buildAdsLink } from './adsUtm';

/**
 * Social & Ads → Ads (/social/ads).
 *
 * A register of the paid campaigns placed by hand in Google Ads, Meta Ads
 * Manager and LinkedIn Campaign Manager, a UTM link builder under Reach's
 * conventions, daily results typed in or imported from each ads manager's CSV
 * export, sign-ups from the my.aicountly.com report, and leads attributed by
 * UTM from Reach's lead capture. Reach is not connected to any ads API and
 * never shows a figure nobody entered: an unknown value is "—", not 0.
 */

const CSV_FORMATS = [
  { key: 'auto', label: 'Detect from the file' },
  { key: 'google_ads', label: 'Google Ads — campaign report by Day' },
  { key: 'meta', label: 'Meta Ads Manager — Breakdown by Day' },
  { key: 'linkedin', label: 'LinkedIn Campaign Manager — daily campaign performance' },
  { key: 'aicountly_signups', label: 'Aicountly sign-ups report (my.aicountly.com)' },
];
const STATUSES = ['planned', 'live', 'paused', 'ended'];
const OBJECTIVES = ['signups', 'leads', 'traffic', 'video_views', 'awareness'];

const num = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('en-IN'));
const inr = (v) => (v === null || v === undefined ? '—' : formatInr(Number(v).toFixed(2)));
const pct = (v) => (v === null || v === undefined ? '—' : `${(Number(v) * 100).toFixed(2)}%`);

function emptyForm(platform = 'google_search') {
  const p = ADS_PLATFORMS[platform];
  return {
    id: null, platform, name: '', external_ref: '', objective: 'signups',
    daily_budget_inr: String(p.suggestedBudget), start_date: todayInputValue(), end_date: '',
    landing_url: DEFAULT_LANDING, utm_campaign: DEFAULT_CAMPAIGN, creatives: `${p.prefix}-01`,
    status: 'planned', notes: '',
  };
}

function Tile({ label, value, hint, testId }) {
  return (
    <div className="stat-tile" data-testid={testId}>
      <div className="stat-tile__label">{label}</div>
      <div className="stat-tile__value">{value}</div>
      {hint && <div className="stat-tile__hint">{hint}</div>}
    </div>
  );
}

export function SocialAdsPage() {
  const { hasAny } = usePermission();
  const canEdit = hasAny(['campaign.edit', 'social.edit', 'campaign.create', 'social.create']);

  const [summary, setSummary] = useState(null);
  const [campaigns, setCampaigns] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    Promise.all([adsService.summary(), adsService.list()])
      .then(([s, list]) => { setSummary(s); setCampaigns(list.items || []); setError(null); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);
  useEffect(load, [load]);

  const figures = useMemo(() => Object.fromEntries((summary?.campaigns || []).map((c) => [c.id, c])), [summary]);

  // --- register form ------------------------------------------------------
  const [form, setForm] = useState(null);
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const choosePlatform = (e) => {
    const next = e.target.value;
    setForm((f) => ({ ...f, platform: next, daily_budget_inr: f.id ? f.daily_budget_inr : String(ADS_PLATFORMS[next].suggestedBudget), creatives: f.id ? f.creatives : `${ADS_PLATFORMS[next].prefix}-01` }));
  };
  const editCampaign = (c) => setForm({
    ...emptyForm(c.platform), ...c,
    daily_budget_inr: c.daily_budget_inr ?? '', end_date: c.end_date || '', notes: c.notes || '',
    creatives: (c.creatives || []).map((x) => x.id).join(', '),
  });
  const saveCampaign = async (e) => {
    e.preventDefault();
    const body = {
      ...form,
      creatives: form.creatives.split(',').map((s) => s.trim()).filter(Boolean).map((id) => ({ id })),
    };
    delete body.id;
    try {
      if (form.id) await adsService.update(form.id, body); else await adsService.create(body);
      setForm(null);
      setNotice(form.id ? 'Campaign updated.' : 'Campaign registered. Place it in the ads manager with the tracking links below.');
      load();
    } catch (err) { setError(err.message); }
  };
  const addLaunchPlan = async () => {
    try {
      const r = await adsService.launchPlan({ start_date: todayInputValue() });
      setNotice(r.created?.length
        ? `${r.created.length} planned campaigns added (₹2,000/day plan). They are plans until you place them and mark them live.`
        : 'The launch plan is already registered.');
      load();
    } catch (err) { setError(err.message); }
  };
  const archive = async (c) => {
    if (!window.confirm(`Archive “${c.name}”? Its results are kept.`)) return;
    try { await adsService.archive(c.id); load(); } catch (err) { setError(err.message); }
  };

  // --- UTM builder --------------------------------------------------------
  const [utm, setUtm] = useState({ platform: 'google_search', landingUrl: DEFAULT_LANDING, utmCampaign: DEFAULT_CAMPAIGN, creativeId: 'gs-rsa-01' });
  const built = useMemo(() => {
    try { return { url: buildAdsLink(utm), error: null }; } catch (e) { return { url: '', error: e.message }; }
  }, [utm]);
  const copy = (text) => { try { navigator.clipboard?.writeText(text); setNotice('Link copied.'); } catch { /* clipboard unavailable */ } };

  // --- daily results ------------------------------------------------------
  const [result, setResult] = useState({ campaignId: '', result_date: todayInputValue(), spend_inr: '', impressions: '', clicks: '', conversions: '', signups: '' });
  const setR = (k) => (e) => setResult((r) => ({ ...r, [k]: e.target.value }));
  const saveResult = async (e) => {
    e.preventDefault();
    const body = { result_date: result.result_date };
    ['spend_inr', 'impressions', 'clicks', 'conversions', 'signups'].forEach((k) => { if (result[k] !== '') body[k] = result[k]; });
    try {
      await adsService.saveResult(result.campaignId, body);
      setNotice(`Results saved for ${formatDate(result.result_date)}.`);
      setResult((r) => ({ ...r, spend_inr: '', impressions: '', clicks: '', conversions: '', signups: '' }));
      load();
    } catch (err) { setError(err.message); }
  };

  // --- CSV import ---------------------------------------------------------
  const [csv, setCsv] = useState({ file: null, format: 'auto', report: null, busy: false });
  const runImport = async (dryRun) => {
    setCsv((c) => ({ ...c, busy: true }));
    try {
      const report = await adsService.importCsv(csv.file, csv.format, dryRun);
      setCsv((c) => ({ ...c, report, busy: false }));
      if (!dryRun) { setNotice(`Imported ${report.stored_days} campaign-day(s).`); load(); }
    } catch (err) {
      setCsv((c) => ({ ...c, busy: false, report: err.details || null }));
      setError(err.message);
    }
  };

  const t = summary?.totals;
  const live = campaigns.filter((c) => c.status === 'live').length;

  return (
    <section aria-labelledby="ads-title" data-testid="ads-register">
      <div className="page-header">
        <div>
          <h2 id="ads-title">Ads</h2>
          <p className="text-sm text-muted">Paid campaigns on Google Search, YouTube, Meta (Facebook &amp; Instagram) and LinkedIn.</p>
        </div>
        {canEdit && (
          <div className="flex gap-2">
            <button type="button" className="btn btn-secondary btn-sm" onClick={addLaunchPlan}>Add launch plan</button>
            <button type="button" className="btn btn-primary btn-sm" onClick={() => setForm(emptyForm())}>Register campaign</button>
          </div>
        )}
      </div>

      <Alert variant="info">
        <span data-testid="ads-not-connected">
          <Info size={14} aria-hidden="true" /> Not connected to the Google Ads, Meta or LinkedIn ads APIs. Campaigns are placed by hand in each
          ads manager; spend and clicks here are typed in or imported from the ads manager&apos;s CSV export, and sign-ups from the my.aicountly.com report.
        </span>
      </Alert>
      {error && <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      {loading && !summary ? <Loader /> : (
        <div className="stat-grid" data-testid="ads-summary">
          <Tile testId="tile-spend" label="Spend (30 days)" value={inr(t?.spend_inr)} hint={summary?.has_results ? 'from entered / imported results' : 'no results entered yet'} />
          <Tile testId="tile-clicks" label="Clicks" value={num(t?.clicks)} hint={`CTR ${pct(t?.ctr)} · CPC ${inr(t?.cpc_inr)}`} />
          <Tile testId="tile-leads" label="Leads (Reach capture)" value={num(t?.leads)} hint={`Cost per lead ${inr(t?.cost_per_lead_inr)}`} />
          <Tile testId="tile-signups" label="Sign-ups (my.aicountly)" value={num(t?.signups)} hint={`Cost per sign-up ${inr(t?.cost_per_signup_inr)}`} />
          <Tile testId="tile-budget" label="Live daily budget" value={inr(summary?.budget?.live_daily_inr)}
            hint={`${live} live · plan ${inr(summary?.budget?.launch_plan?.daily_total_inr)}/day (a plan, not spend)`} />
        </div>
      )}

      {form && (
        <Card title={form.id ? 'Edit campaign' : 'Register a campaign placed in an ads manager'}>
          <form className="form-grid" onSubmit={saveCampaign} aria-label="Ad campaign">
            <label className="form-label">Platform
              <select className="form-select" value={form.platform} onChange={choosePlatform}>
                {ADS_PLATFORM_LIST.map((p) => <option key={p.key} value={p.key}>{p.label}</option>)}
              </select>
            </label>
            <label className="form-label">Name<input className="form-input" value={form.name} onChange={set('name')} required maxLength={150} /></label>
            <label className="form-label">Name in the ads manager (for CSV import)
              <input className="form-input" value={form.external_ref || ''} onChange={set('external_ref')} maxLength={200} placeholder="Same as name" />
            </label>
            <label className="form-label">Objective
              <select className="form-select" value={form.objective} onChange={set('objective')}>{OBJECTIVES.map((o) => <option key={o} value={o}>{o.replace('_', ' ')}</option>)}</select>
            </label>
            <label className="form-label">Daily budget (₹)<input className="form-input" type="number" min="0" step="1" value={form.daily_budget_inr} onChange={set('daily_budget_inr')} /></label>
            <label className="form-label">Start<input className="form-input" type="date" value={form.start_date} onChange={set('start_date')} required /></label>
            <label className="form-label">End<input className="form-input" type="date" value={form.end_date} onChange={set('end_date')} /></label>
            <label className="form-label">Landing URL<input className="form-input" value={form.landing_url} onChange={set('landing_url')} required /></label>
            <label className="form-label">utm_campaign<input className="form-input" value={form.utm_campaign} onChange={set('utm_campaign')} /></label>
            <label className="form-label">Creative ids (utm_content, comma-separated)<input className="form-input" value={form.creatives} onChange={set('creatives')} /></label>
            <label className="form-label">Status
              <select className="form-select" value={form.status} onChange={set('status')}>{STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}</select>
            </label>
            <label className="form-label">Notes<textarea className="form-textarea" value={form.notes} onChange={set('notes')} maxLength={2000} /></label>
            <p className="text-xs text-muted">
              UTM set: utm_source={ADS_PLATFORMS[form.platform].utmSource}, utm_medium={ADS_PLATFORMS[form.platform].utmMedium}.
              Suggested budget from the launch plan: ₹{ADS_PLATFORMS[form.platform].suggestedBudget}/day. Saving here does not place or change anything in {ADS_PLATFORMS[form.platform].manager}.
            </p>
            <div className="flex gap-2">
              <button type="submit" className="btn btn-primary btn-sm">{form.id ? 'Save' : 'Register'}</button>
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => setForm(null)}>Cancel</button>
            </div>
          </form>
        </Card>
      )}

      <Card title="Campaign register">
        {campaigns.length === 0 ? (
          <EmptyState message="No paid campaigns registered. Register each campaign after placing it in its ads manager, or add the launch plan." />
        ) : (
          <div className="table-wrap">
            <table className="data-table" data-testid="ads-campaigns">
              <thead>
                <tr><th>Campaign</th><th>Platform</th><th>Status</th><th>Budget/day</th><th>Dates</th><th>Spend</th><th>Clicks</th><th>Leads</th><th>Sign-ups</th><th>Tracking links</th><th /></tr>
              </thead>
              <tbody>
                {campaigns.map((c) => {
                  const f = figures[c.id] || {};
                  return (
                    <tr key={c.id}>
                      <td>{c.name}{f.attribution_warning && <div className="text-xs text-danger">{f.attribution_warning}</div>}</td>
                      <td>{ADS_PLATFORMS[c.platform]?.label || c.platform}</td>
                      <td><span className={`badge badge-${c.status === 'live' ? 'success' : c.status === 'paused' ? 'warning' : 'secondary'}`}>{c.status}</span>
                        {f.days_missing_results > 0 && <div className="text-xs text-muted">{f.days_missing_results} day(s) without results</div>}</td>
                      <td>{inr(c.daily_budget_inr)}</td>
                      <td>{formatDate(c.start_date)} → {c.end_date ? formatDate(c.end_date) : 'open'}</td>
                      <td>{inr(f.spend_inr)}</td>
                      <td>{num(f.clicks)}</td>
                      <td>{num(f.leads)}</td>
                      <td>{num(f.signups)}</td>
                      <td>{(c.tracking_links || []).map((l) => (
                        <div key={l.url} className="text-xs">
                          <code>{l.creative_id || 'campaign'}</code>{' '}
                          <button type="button" className="link-btn" onClick={() => copy(l.url)} aria-label={`Copy link for ${l.creative_id || c.name}`}>copy</button>
                        </div>
                      ))}</td>
                      <td>{canEdit && (
                        <span className="flex gap-2">
                          <button type="button" className="link-btn" onClick={() => editCampaign(c)}>Edit</button>
                          <button type="button" className="link-btn" onClick={() => archive(c)}>Archive</button>
                        </span>
                      )}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <div className="grid grid-2">
        <Card title="UTM link builder">
          <div className="form-grid" data-testid="utm-builder">
            <label className="form-label">Platform
              <select className="form-select" value={utm.platform} onChange={(e) => setUtm((u) => ({ ...u, platform: e.target.value, creativeId: `${ADS_PLATFORMS[e.target.value].prefix}-01` }))}>
                {ADS_PLATFORM_LIST.map((p) => <option key={p.key} value={p.key}>{p.label}</option>)}
              </select>
            </label>
            <label className="form-label">Landing URL<input className="form-input" value={utm.landingUrl} onChange={(e) => setUtm((u) => ({ ...u, landingUrl: e.target.value }))} /></label>
            <label className="form-label">utm_campaign<input className="form-input" value={utm.utmCampaign} onChange={(e) => setUtm((u) => ({ ...u, utmCampaign: e.target.value }))} /></label>
            <label className="form-label">Creative id (utm_content)<input className="form-input" value={utm.creativeId} onChange={(e) => setUtm((u) => ({ ...u, creativeId: e.target.value }))} /></label>
            {built.error ? <Alert variant="warning">{built.error}</Alert> : (
              <div>
                <code className="text-xs" data-testid="utm-result" style={{ wordBreak: 'break-all' }}>{built.url}</code>{' '}
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => copy(built.url)}>Copy</button>
              </div>
            )}
            <p className="text-xs text-muted">The ads platform adds its own click id (gclid, fbclid, li_fat_id); aicountly.com passes it and the UTM set to sign-up.</p>
          </div>
        </Card>

        {canEdit && (
          <Card title="Enter a day's results">
            <form className="form-grid" onSubmit={saveResult} aria-label="Daily results">
              <label className="form-label">Campaign
                <select className="form-select" value={result.campaignId} onChange={setR('campaignId')} required>
                  <option value="">Choose…</option>
                  {campaigns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </label>
              <label className="form-label">Day<input className="form-input" type="date" value={result.result_date} max={todayInputValue()} onChange={setR('result_date')} required /></label>
              <label className="form-label">Spend (₹)<input className="form-input" type="number" min="0" step="0.01" value={result.spend_inr} onChange={setR('spend_inr')} /></label>
              <label className="form-label">Impressions<input className="form-input" type="number" min="0" step="1" value={result.impressions} onChange={setR('impressions')} /></label>
              <label className="form-label">Clicks<input className="form-input" type="number" min="0" step="1" value={result.clicks} onChange={setR('clicks')} /></label>
              <label className="form-label">Conversions (platform-reported)<input className="form-input" type="number" min="0" step="0.01" value={result.conversions} onChange={setR('conversions')} /></label>
              <label className="form-label">Sign-ups (my.aicountly)<input className="form-input" type="number" min="0" step="1" value={result.signups} onChange={setR('signups')} /></label>
              <p className="text-xs text-muted">Leave a field empty when you do not have the figure — it is stored as unknown, not 0.</p>
              <div><button type="submit" className="btn btn-primary btn-sm">Save day</button></div>
            </form>
          </Card>
        )}
      </div>

      {canEdit && (
        <Card title="Import results from CSV">
          <div className="form-grid" data-testid="csv-import">
            <label className="form-label">Export
              <select className="form-select" value={csv.format} onChange={(e) => setCsv((c) => ({ ...c, format: e.target.value, report: null }))}>
                {CSV_FORMATS.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
              </select>
            </label>
            <label className="form-label">File (CSV, up to 2 MB)
              <input type="file" accept=".csv,.tsv,text/csv,text/tab-separated-values" onChange={(e) => setCsv((c) => ({ ...c, file: e.target.files?.[0] || null, report: null }))} />
            </label>
            <p className="text-xs text-muted">
              Export with a daily breakdown (Google Ads: segment by Day; Meta: Breakdown → By time → Day; LinkedIn: time breakdown Daily), in INR.
              Rows are matched by the campaign&apos;s name in the ads manager; sign-ups by utm_source + utm_campaign. Re-importing the same file changes nothing.
            </p>
            <div className="flex gap-2">
              <button type="button" className="btn btn-secondary btn-sm" disabled={!csv.file || csv.busy} onClick={() => runImport(true)}>Preview</button>
              <button type="button" className="btn btn-primary btn-sm" disabled={!csv.file || csv.busy || !csv.report || csv.report.error} onClick={() => runImport(false)}>Import</button>
            </div>
            {csv.report && (
              <div data-testid="csv-report" className="text-sm">
                <p>
                  {csv.report.dry_run ? 'Preview' : 'Imported'} ({csv.report.format || 'unrecognised'}): {csv.report.rows_used} row(s) matched,
                  {' '}{csv.report.unmatched_total || 0} unmatched, {csv.report.rejected_total || 0} rejected, {csv.report.skipped || 0} skipped
                  {csv.report.dry_run ? '' : ` · ${csv.report.stored_days} campaign-day(s) stored`}.
                  {csv.report.unattributed_signups > 0 && ` ${csv.report.unattributed_signups} sign-up(s) had no campaign (direct / organic).`}
                </p>
                {(csv.report.campaigns || []).map((c) => (
                  <div key={c.ad_campaign_id}>{c.name}: {c.days} day(s), spend {inr(c.spend_inr)}, clicks {num(c.clicks)}, sign-ups {num(c.signups)}</div>
                ))}
                {(csv.report.unmatched || []).map((u) => <div key={`u${u.line}`} className="text-danger">Line {u.line} — {u.campaign}: {u.reason}</div>)}
                {(csv.report.rejected || []).map((r) => <div key={`r${r.line}`} className="text-danger">Line {r.line}: {r.reason}</div>)}
              </div>
            )}
          </div>
        </Card>
      )}
    </section>
  );
}

export default SocialAdsPage;
