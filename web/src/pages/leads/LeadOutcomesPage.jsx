import { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { leadsService } from '../../services/leadsService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { formatDateTime } from '../../utils/formatDate';
import { formatInr } from '../../utils/formatInr';
import { OutcomesNotice } from '../../components/outcomes/OutcomesNotice';
import { localDay } from '../../utils/localDay';
import { ROUTES } from '../../constants/routes';
import { leadHref } from './leadLinks';
import { DrillLink, drillHref, useUrlFilter } from '../../components/drill';

const STAGES = ['', 'qualified', 'demo', 'proposal_sent', 'converted', 'lost', 'payment_confirmed'];
const MATCHES = ['', 'matched', 'reach_lead_id', 'engage_lead_code', 'email_sha256', 'unmatched'];
const WINDOWS = ['7', '30', '90', 'all'];
// Drill filters with no control of their own — shown as removable chips.
const CHIP_FILTERS = {
  lead_id: 'Lead #', campaign_id: 'Campaign #', content_identity_id: 'Content #',
  source: 'Source', seller_code: 'Seller', paid: 'Payments only',
};

const label = (s) => s.replace(/_/g, ' ');

/** from/to for a window: `days` days ending today, or everything for "all". */
function periodOf(days) {
  const to = new Date();
  if (days === 'all') return { from: '2000-01-01', to: localDay(to) };
  const from = new Date(to.getTime() - (Number(days) - 1) * 86400000);
  return { from: localDay(from), to: localDay(to) };
}

/** A period-total tile; a link to the events behind it when there are any. */
function StatTile({ label: title, value, to }) {
  const body = (
    <>
      <div className="stat-tile__label">{title}</div>
      <div className="stat-tile__value">{value}</div>
    </>
  );
  return to ? <Link className="stat-tile" to={to}>{body}</Link> : <div className="stat-tile">{body}</div>;
}

/**
 * Leads → Outcomes: what Engage reported back (stage changes, payments) for
 * leads Reach sent, and how each event was matched and attributed.
 */
export function LeadOutcomesPage() {
  // Every filter lives in the URL so Analytics, Campaign and Leads pages can
  // link straight to the events behind a number (?campaign_id=7&stage=converted).
  const [params] = useSearchParams();
  const [days, setDays] = useUrlFilter('days', '30');
  const [stage, setStage] = useUrlFilter('stage');
  const [match, setMatch] = useUrlFilter('match');
  const [search, setSearch] = useUrlFilter('search');
  const chipValues = Object.fromEntries(Object.keys(CHIP_FILTERS).map((k) => [k, params.get(k) || '']));
  const chipKey = JSON.stringify(chipValues);
  const [summary, setSummary] = useState(null);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(() => {
    const period = periodOf(days);
    // "All time" lists without bounds; the summary needs a period, so it gets 2000-01-01 → today.
    const listPeriod = days === 'all' ? {} : period;
    setLoading(true);
    setError(null);
    Promise.all([
      leadsService.outcomeSummary(period),
      leadsService.outcomes({ ...listPeriod, stage, match, search, ...JSON.parse(chipKey), limit: 100 }),
    ])
      .then(([s, list]) => {
        setSummary(s);
        setRows(list.items || []);
        setTotal(list.total ?? 0);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [days, stage, match, search, chipKey]);
  useEffect(() => { load(); }, [load]);

  // An in-page drill step: the current filters plus `patch` (a Link, so Back steps up again).
  const here = (patch) => drillHref(ROUTES.LEAD_OUTCOMES, { ...Object.fromEntries(params), ...patch });
  // The period totals ignore the list filters, so their drills keep only the window.
  const ifAny = (n, patch) => (Number(n) > 0 ? drillHref(ROUTES.LEAD_OUTCOMES, { days: days === '30' ? '' : days, ...patch }) : null);
  const clearChip = (k) => here({ [k]: '' });
  const activeChips = Object.entries(CHIP_FILTERS).filter(([k]) => chipValues[k]);
  const narrowed = activeChips.length > 0 || stage || match || search;
  const campaignName = chipValues.campaign_id && rows.find((r) => String(r.campaign_id) === chipValues.campaign_id)?.campaign_name;

  const columns = [
    { key: 'occurred_at', label: 'When', render: (r) => formatDateTime(r.occurred_at) },
    // A matched lead opens its detail page; an unmatched one narrows this list to its Engage lead code.
    { key: 'lead', label: 'Lead', render: (r) => (
      <div>
        <div className="font-semibold">
          {r.lead_id
            ? <DrillLink to={leadHref(r.lead_id)} title="Open this lead">#{r.lead_id} · {r.lead_name || '—'}</DrillLink>
            : 'Not matched'}
        </div>
        <div className="text-xs text-muted">
          {r.engage_lead_code
            ? <DrillLink to={here({ search: r.engage_lead_code })} title="All outcomes for this Engage lead">{r.engage_lead_code}</DrillLink>
            : ''}
          {r.lead_email ? ` · ${r.lead_email}` : ''}
        </div>
      </div>
    )},
    { key: 'stage', label: 'Stage', render: (r) => (
      <div>
        <div><DrillLink to={here({ stage: r.stage })}>{label(r.stage)}</DrillLink></div>
        {r.sales_status && <div className="text-xs text-muted">{label(r.sales_status)}</div>}
      </div>
    )},
    { key: 'value_inr', label: 'Value', render: (r) => formatInr(r.value_inr) },
    { key: 'product', label: 'Product', render: (r) => r.product || '—' },
    { key: 'campaign', label: 'Attributed to', render: (r) => (
      <div>
        <div>
          {r.campaign_id
            ? <DrillLink to={ROUTES.CAMPAIGN_DETAIL.replace(':id', r.campaign_id)}>{r.campaign_name || `Campaign #${r.campaign_id}`}</DrillLink>
            : r.source ? <DrillLink to={here({ source: r.source })}>{r.source}</DrillLink> : '—'}
        </div>
        <div className="text-xs text-muted">
          {label(r.attribution_method)}
          {r.seller_code && <> · seller <DrillLink to={here({ seller_code: r.seller_code })}>{r.seller_code}</DrillLink></>}
        </div>
      </div>
    )},
    { key: 'match_method', label: 'Matched by', render: (r) => <DrillLink to={here({ match: r.match_method })}>{label(r.match_method)}</DrillLink> },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Lead outcomes</h1>
          <p className="text-sm text-muted">
            Stage changes and confirmed payments Engage reported for leads. Totals count only events Engage actually sent.
            Per-campaign revenue is also on <Link to={ROUTES.ANALYTICS}>Analytics</Link>.
          </p>
        </div>
      </div>

      <FilterBar>
        <select aria-label="Window" value={days} onChange={(e) => setDays(e.target.value)}>
          {WINDOWS.map((d) => <option key={d} value={d}>{d === 'all' ? 'All time' : `Last ${d} days`}</option>)}
        </select>
        <select aria-label="Stage" value={stage} onChange={(e) => setStage(e.target.value)}>
          {STAGES.map((s) => <option key={s} value={s}>{s ? label(s) : 'All stages'}</option>)}
        </select>
        <select aria-label="Match" value={match} onChange={(e) => setMatch(e.target.value)}>
          {MATCHES.map((s) => <option key={s} value={s}>{s ? label(s) : 'Any match'}</option>)}
        </select>
        <input aria-label="Search" placeholder="Lead code, seller, name, e-mail" value={search} onChange={(e) => setSearch(e.target.value)} />
      </FilterBar>

      {activeChips.length > 0 && (
        <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
          {activeChips.map(([k, chip]) => (
            <Link key={k} to={clearChip(k)} className="badge badge-secondary" aria-label={`Clear ${chip.replace(' #', '')} filter`}>
              {k === 'paid' ? chip
                : k === 'campaign_id' && campaignName ? `Campaign: ${campaignName}`
                  : chip.endsWith('#') ? `${chip}${chipValues[k]}` : `${chip}: ${chipValues[k]}`} ✕
            </Link>
          ))}
        </div>
      )}

      {error && <Alert variant="danger">{error}</Alert>}
      {loading && !summary ? <Loader /> : summary && (
        <>
          <OutcomesNotice summary={summary} />
          <Card title="Period totals">
            {narrowed && (
              <p className="text-xs text-muted" style={{ marginTop: 0 }}>
                Totals cover the whole window; the event list below is narrowed by your filters.
              </p>
            )}
            <div className="grid grid-4">
              <StatTile label="Conversions" value={summary.conversions} to={ifAny(summary.conversions, { stage: 'converted' })} />
              <StatTile label="Revenue (payments)" value={formatInr(summary.revenue_inr)} to={ifAny(summary.revenue_inr, { paid: '1' })} />
              <StatTile label="Converted value" value={formatInr(summary.converted_value_inr)} to={ifAny(summary.converted_value_inr, { stage: 'converted' })} />
              <div className="stat-tile">
                <div className="stat-tile__label">Matched / unmatched</div>
                <div className="stat-tile__value">
                  <DrillLink to={ifAny(summary.matched, { match: 'matched' })} label={`${summary.matched} matched`}>{summary.matched}</DrillLink>
                  {' / '}
                  <DrillLink to={ifAny(summary.unmatched, { match: 'unmatched' })} label={`${summary.unmatched} unmatched`}>{summary.unmatched}</DrillLink>
                </div>
              </div>
            </div>
            <div className="flex gap-2 mt-3" style={{ flexWrap: 'wrap' }}>
              {Object.entries(summary.by_stage || {}).map(([s, n]) => (
                <span key={s} className="text-xs" data-testid={`stage-${s}`}>
                  <DrillLink to={ifAny(n, { stage: s })}>{label(s)}: <strong>{n}</strong></DrillLink>
                </span>
              ))}
            </div>
          </Card>
          <div className="mt-3">
            <Card padding={false} title={`Events (${total})`}>
              <DataTable columns={columns} rows={rows} emptyMessage="No outcome events for this filter." />
            </Card>
          </div>
        </>
      )}
    </div>
  );
}
