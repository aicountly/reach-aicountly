import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { adsService } from '../../../services/adsService';
import { Card } from '../../../components/common/Card';
import { Alert } from '../../../components/common/Alert';
import { Loader } from '../../../components/common/Loader';
import { EmptyState } from '../../../components/common/EmptyState';
import { DrillLink, drillHref, useDrillFilters, useUrlFilter } from '../../../components/drill';
import { usePermission } from '../../../hooks/usePermission';
import { ROUTES } from '../../../constants/routes';
import { formatDate, formatDateTime, todayInputValue } from '../../../utils/formatDate';
import { ADS_PLATFORMS } from './adsUtm';
import {
  ADS_METRICS, DAY_SORT, dayLeadsHref, dayRows, dayTotals, daysBefore, inr, num, pct, ratios, sortDays,
} from './adsDrill';

const COLUMNS = [
  { key: 'day', label: 'Day' },
  { key: 'spend', label: 'Spend', field: 'spend_inr', fmt: inr },
  { key: 'impressions', label: 'Impressions', field: 'impressions', fmt: num },
  { key: 'clicks', label: 'Clicks', field: 'clicks', fmt: num },
  { key: 'conversions', label: 'Conversions', field: 'conversions', fmt: num },
  { key: 'signups', label: 'Sign-ups', field: 'signups', fmt: num },
  { key: 'leads', label: 'Leads', field: 'leads', fmt: num },
];

/**
 * Social & Ads → Ads → one campaign (/social/ads/:id?from=&to=&metric=&day=).
 *
 * The campaign's daily results for the summary period — the rows the Ads
 * tiles summed for it — sorted by the figure that was drilled, with the leads
 * Reach attributed to it per day. A day opens its results row (where the
 * figures came from, and the leads that day). Default period: the summary's
 * last 30 days.
 */
export function AdCampaignDetailPage() {
  const { id } = useParams();
  const { hasAny } = usePermission();
  const canEdit = hasAny(['campaign.edit', 'social.edit']);

  const today = todayInputValue();
  const [toParam, setTo] = useUrlFilter('to');
  const [fromParam, setFrom] = useUrlFilter('from');
  const to = toParam || today;
  const from = fromParam || daysBefore(to, 29);
  const [metricParam, setMetric] = useUrlFilter('metric');
  const metric = (DAY_SORT[metricParam] || ADS_METRICS[metricParam]) ? metricParam : '';
  const dayDrill = useDrillFilters(['day']);
  const selectedDay = dayDrill.filters.day;

  const [campaign, setCampaign] = useState(null);
  const [results, setResults] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    Promise.all([adsService.get(id), adsService.results(id, { from, to })])
      .then(([c, r]) => { setCampaign(c); setResults(r); setError(null); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [id, from, to]);
  useEffect(load, [load]);

  const rows = useMemo(() => sortDays(dayRows(results?.items, results?.leads_by_day), metric), [results, metric]);
  const totals = useMemo(() => dayTotals(rows), [rows]);
  const day = rows.find((r) => r.day === selectedDay) || null;

  const removeDay = async (row) => {
    if (!window.confirm(`Delete the results entered for ${formatDate(row.day)}? Re-import or re-enter them to restore.`)) return;
    try {
      await adsService.deleteResult(row.id);
      setNotice(`Results for ${formatDate(row.day)} deleted.`);
      dayDrill.clear('day');
      load();
    } catch (e) { setError(e.message); }
  };

  if (loading && !campaign) return <Loader />;
  if (error && !campaign) return <Alert variant="danger">{error}</Alert>;
  if (!campaign) return <EmptyState message="Ad campaign not found." />;

  const backHref = drillHref(ROUTES.SOCIAL_ADS, { metric: ADS_METRICS[metric] ? metric : undefined });

  return (
    <section aria-labelledby="ad-campaign-title" data-testid="ad-campaign">
      <div className="page-header">
        <div>
          <p className="text-xs"><Link to={backHref}>‹ Ads</Link></p>
          <h2 id="ad-campaign-title">{campaign.name}</h2>
          <p className="text-sm text-muted">
            {ADS_PLATFORMS[campaign.platform]?.label || campaign.platform_label || campaign.platform} · {campaign.status}
            {' · '}{inr(campaign.daily_budget_inr)}/day · {formatDate(campaign.start_date)} → {campaign.end_date ? formatDate(campaign.end_date) : 'open'}
          </p>
          <p className="text-xs text-muted">
            UTM: utm_source={campaign.utm_source}, utm_medium={campaign.utm_medium}, utm_campaign={campaign.utm_campaign}
          </p>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      <div className="flex gap-2 mb-2" style={{ flexWrap: 'wrap', alignItems: 'end' }}>
        <label className="form-label">From<input className="form-input" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} /></label>
        <label className="form-label">To<input className="form-input" type="date" value={to} max={today} onChange={(e) => setTo(e.target.value)} /></label>
        <span className="text-xs text-muted">{results?.source}</span>
      </div>

      <div className="stat-grid" data-testid="ad-campaign-totals">
        <div className="stat-tile"><div className="stat-tile__label">Spend</div><div className="stat-tile__value">{inr(totals.spend_inr)}</div></div>
        <div className="stat-tile"><div className="stat-tile__label">Clicks</div><div className="stat-tile__value">{num(totals.clicks)}</div>
          <div className="stat-tile__hint">CTR {pct(totals.ctr)} · CPC {inr(totals.cpc_inr)}</div></div>
        <div className="stat-tile"><div className="stat-tile__label">Leads (Reach capture)</div><div className="stat-tile__value">
          <DrillLink to={totals.leads ? drillHref(ROUTES.LEADS, {
            utm_source: campaign.utm_source, utm_medium: campaign.utm_medium, utm_campaign: campaign.utm_campaign,
            created_from: from > campaign.start_date ? from : campaign.start_date,
            created_to: campaign.end_date && campaign.end_date < to ? campaign.end_date : to,
          }) : undefined} label={`${totals.leads} leads for ${campaign.name}`}>{num(totals.leads)}</DrillLink>
        </div></div>
        <div className="stat-tile"><div className="stat-tile__label">Sign-ups (my.aicountly)</div><div className="stat-tile__value">{num(totals.signups)}</div></div>
      </div>

      <Card title={`Days (${rows.length})`}>
        {rows.length === 0 ? (
          <EmptyState message="No results entered and no leads for this campaign in this period." />
        ) : (
          <div className="table-wrap">
            <table className="data-table" data-testid="ad-campaign-days">
              <thead>
                <tr>
                  {COLUMNS.map((c) => (
                    <th key={c.key} className={c.field ? 'is-sortable' : undefined} aria-sort={metric === c.key ? 'descending' : undefined}>
                      {c.field
                        ? <button type="button" className="link-btn" onClick={() => setMetric(metric === c.key ? '' : c.key)}>{c.label}{metric === c.key ? ' ↓' : ''}</button>
                        : c.label}
                    </th>
                  ))}
                  <th>CTR</th><th>CPC</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => {
                  const rr = ratios(r);
                  const open = () => dayDrill.drill({ day: r.day });
                  return (
                    <tr key={r.day} className={`is-drillable${r.day === selectedDay ? ' is-active' : ''}`} tabIndex={0}
                        aria-label={`Results for ${r.day}`} onClick={open}
                        onKeyDown={(e) => { if (e.target === e.currentTarget && e.key === 'Enter') open(); }}>
                      <td>{formatDate(r.day)}{!r.has_result && <div className="text-xs text-muted">no results entered</div>}</td>
                      {COLUMNS.slice(1).map((c) => (
                        <td key={c.key}>
                          {c.key === 'leads'
                            ? <DrillLink to={dayLeadsHref(campaign, r.day, r.leads)} label={`${r.leads} leads on ${r.day}`}>{num(r.leads)}</DrillLink>
                            : c.fmt(r[c.field])}
                        </td>
                      ))}
                      <td>{pct(rr.ctr)}</td><td>{inr(rr.cpc_inr)}</td>
                    </tr>
                  );
                })}
              </tbody>
              <tfoot>
                <tr>
                  <th>Total</th>
                  {COLUMNS.slice(1).map((c) => <th key={c.key}>{c.fmt(totals[c.field])}</th>)}
                  <th>{pct(totals.ctr)}</th><th>{inr(totals.cpc_inr)}</th>
                </tr>
              </tfoot>
            </table>
          </div>
        )}
      </Card>

      {day && (
        <Card title={`${formatDate(day.day)} — results row`}>
          <div data-testid="ad-day" className="text-sm">
            {day.has_result ? (
              <dl className="form-grid">
                <div><dt className="text-muted">Spend</dt><dd>{inr(day.spend_inr)}</dd></div>
                <div><dt className="text-muted">Impressions</dt><dd>{num(day.impressions)}</dd></div>
                <div><dt className="text-muted">Clicks</dt><dd>{num(day.clicks)}</dd></div>
                <div><dt className="text-muted">Platform conversions</dt><dd>{num(day.conversions)}</dd></div>
                <div><dt className="text-muted">Sign-ups</dt><dd>{num(day.signups)}</dd></div>
                <div><dt className="text-muted">Figures from</dt><dd>{day.metrics_source || '—'}</dd></div>
                <div><dt className="text-muted">Sign-ups from</dt><dd>{day.signups_source || '—'}</dd></div>
                <div><dt className="text-muted">Last changed</dt><dd>{formatDateTime(day.updated_at || day.created_at)}</dd></div>
              </dl>
            ) : <p className="text-muted">No results were entered or imported for this day.</p>}
            <p>
              Leads that day:{' '}
              <DrillLink to={dayLeadsHref(campaign, day.day, day.leads)} label={`Open ${day.leads} leads on ${day.day}`}>{num(day.leads)}</DrillLink>
            </p>
            <div className="flex gap-2">
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => dayDrill.clear('day')}>Close</button>
              {canEdit && day.has_result && (
                <button type="button" className="btn btn-danger btn-sm" onClick={() => removeDay(day)}>Delete this day&apos;s results</button>
              )}
            </div>
          </div>
        </Card>
      )}
    </section>
  );
}

export default AdCampaignDetailPage;
