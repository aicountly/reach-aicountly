import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
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

const STAGES = ['', 'qualified', 'demo', 'proposal_sent', 'converted', 'lost', 'payment_confirmed'];
const MATCHES = ['', 'reach_lead_id', 'engage_lead_code', 'email_sha256', 'unmatched'];
const WINDOWS = [7, 30, 90];

const label = (s) => s.replace(/_/g, ' ');

/**
 * Leads → Outcomes: what Engage reported back (stage changes, payments) for
 * leads Reach sent, and how each event was matched and attributed.
 */
export function LeadOutcomesPage() {
  const [days, setDays] = useState(30);
  const [stage, setStage] = useState('');
  const [match, setMatch] = useState('');
  const [search, setSearch] = useState('');
  const [summary, setSummary] = useState(null);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(() => {
    const to = new Date();
    const from = new Date(to.getTime() - (days - 1) * 86400000);
    const period = { from: localDay(from), to: localDay(to) };
    setLoading(true);
    setError(null);
    Promise.all([
      leadsService.outcomeSummary(period),
      leadsService.outcomes({ ...period, stage, match, search, limit: 100 }),
    ])
      .then(([s, list]) => {
        setSummary(s);
        setRows(list.items || []);
        setTotal(list.total ?? 0);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [days, stage, match, search]);
  useEffect(() => { load(); }, [load]);

  const columns = [
    { key: 'occurred_at', label: 'When', render: (r) => formatDateTime(r.occurred_at) },
    { key: 'lead', label: 'Lead', render: (r) => (
      <div>
        <div className="font-semibold">{r.lead_id ? `#${r.lead_id} · ${r.lead_name || '—'}` : 'Not matched'}</div>
        <div className="text-xs text-muted">{r.engage_lead_code || ''}{r.lead_email ? ` · ${r.lead_email}` : ''}</div>
      </div>
    )},
    { key: 'stage', label: 'Stage', render: (r) => (
      <div>
        <div>{label(r.stage)}</div>
        {r.sales_status && <div className="text-xs text-muted">{label(r.sales_status)}</div>}
      </div>
    )},
    { key: 'value_inr', label: 'Value', render: (r) => formatInr(r.value_inr) },
    { key: 'product', label: 'Product', render: (r) => r.product || '—' },
    { key: 'campaign', label: 'Attributed to', render: (r) => (
      <div>
        <div>{r.campaign_id ? (r.campaign_name || `Campaign #${r.campaign_id}`) : (r.source || '—')}</div>
        <div className="text-xs text-muted">{label(r.attribution_method)}{r.seller_code ? ` · seller ${r.seller_code}` : ''}</div>
      </div>
    )},
    { key: 'match_method', label: 'Matched by', render: (r) => label(r.match_method) },
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
        <select aria-label="Window" value={days} onChange={(e) => setDays(Number(e.target.value))}>
          {WINDOWS.map((d) => <option key={d} value={d}>Last {d} days</option>)}
        </select>
        <select aria-label="Stage" value={stage} onChange={(e) => setStage(e.target.value)}>
          {STAGES.map((s) => <option key={s} value={s}>{s ? label(s) : 'All stages'}</option>)}
        </select>
        <select aria-label="Match" value={match} onChange={(e) => setMatch(e.target.value)}>
          {MATCHES.map((s) => <option key={s} value={s}>{s ? label(s) : 'Any match'}</option>)}
        </select>
        <input aria-label="Search" placeholder="Lead code, seller, name, e-mail" value={search} onChange={(e) => setSearch(e.target.value)} />
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading && !summary ? <Loader /> : summary && (
        <>
          <OutcomesNotice summary={summary} />
          <Card title="Period totals">
            <div className="grid grid-4">
              <div className="stat-tile"><div className="stat-tile__label">Conversions</div><div className="stat-tile__value">{summary.conversions}</div></div>
              <div className="stat-tile"><div className="stat-tile__label">Revenue (payments)</div><div className="stat-tile__value">{formatInr(summary.revenue_inr)}</div></div>
              <div className="stat-tile"><div className="stat-tile__label">Converted value</div><div className="stat-tile__value">{formatInr(summary.converted_value_inr)}</div></div>
              <div className="stat-tile"><div className="stat-tile__label">Matched / unmatched</div><div className="stat-tile__value">{summary.matched} / {summary.unmatched}</div></div>
            </div>
            <div className="flex gap-2 mt-3" style={{ flexWrap: 'wrap' }}>
              {Object.entries(summary.by_stage || {}).map(([s, n]) => (
                <span key={s} className="text-xs" data-testid={`stage-${s}`}>{label(s)}: <strong>{n}</strong></span>
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
