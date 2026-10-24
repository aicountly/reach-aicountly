import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Users2 } from 'lucide-react';
import { getCompetitor, listCompetitorObservations } from '../../services/growthIntelService.js';
import { formatDateTime } from '../../utils/formatDate';
import { DataTable } from '../../components/common/DataTable';
import { Breadcrumbs } from '../../components/common/Breadcrumbs';
import { drillHref, useUrlFilter } from '../../components/drill';
import { UrlPagination } from '../seo-centre/RecordFilters';
import { useUrlPage } from '../seo-centre/recordLinks';
import { PROVIDER_LABEL, RUNS, pct } from './visibilityLabels';

const PER_PAGE = 50;

/**
 * One competitor: its sample mention rate over the period and the
 * observations behind it — every stored AI answer that named it (names asked
 * about in the question are not counted, as on the overview).
 */
export default function CompetitorDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [daysParam, setDays] = useUrlFilter('days', '28');
  const days = Number(daysParam) || 28;
  const [page] = useUrlPage();
  const [competitor, setCompetitor] = useState(null);
  const [obs, setObs] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    setError(null);
    getCompetitor(id, { days }).then(setCompetitor).catch((e) => setError(e.message || 'Failed to load competitor'));
  }, [id, days]);

  useEffect(() => {
    listCompetitorObservations(id, { days, page, per_page: PER_PAGE }).then(setObs).catch(() => setObs({ data: [], meta: { total: 0 } }));
  }, [id, days, page]);

  if (error) return <div className="alert alert-danger">{error}</div>;
  if (!competitor) return <p className="text-sm text-muted">Loading competitor…</p>;

  const total = obs?.meta?.total ?? 0;
  const aliases = (competitor.aliases || []).map((a) => a.alias_value);

  return (
    <div>
      <Breadcrumbs items={[{ label: 'Competitors', to: '/intelligence/competitors' }, { label: competitor.name }]} />
      <div className="flex items-center justify-between flex-wrap gap-3 mb-3">
        <div className="flex items-center gap-3">
          <Users2 size={24} style={{ color: 'var(--color-primary)' }} aria-hidden="true" />
          <div>
            <h1 style={{ fontSize: '1.3rem', fontWeight: 700, margin: 0 }}>{competitor.name}</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              {competitor.website_domain || 'No domain'} · {String(competitor.category || 'general').replace(/_/g, ' ')}
              {aliases.length > 0 && <> · also matched as {aliases.join(', ')}</>}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <label className="text-sm text-muted" htmlFor="comp-days">Period</label>
          <select id="comp-days" className="form-select form-select--sm" value={String(days)} onChange={(e) => setDays(e.target.value)}>
            <option value="7">Last 7 days</option>
            <option value="28">Last 28 days</option>
            <option value="90">Last 90 days</option>
          </select>
        </div>
      </div>

      <div className="grid grid-3 mb-3">
        <div className="stat-tile" style={{ textAlign: 'center' }}>
          <div className="stat-tile__value">{competitor.mention_rate == null ? '—' : pct(competitor.mention_rate)}</div>
          <div className="stat-tile__label">Sample mention rate</div>
        </div>
        <a href="#competitor-observations" className="stat-tile" style={{ textAlign: 'center', textDecoration: 'none', color: 'inherit' }}
           aria-label={`Answers naming ${competitor.name}: ${competitor.mentions ?? 0}`}>
          <div className="stat-tile__value">{competitor.mentions ?? 0}</div>
          <div className="stat-tile__label">Answers naming it</div>
        </a>
        {Number(competitor.answers) > 0 ? (
          <Link to={drillHref(RUNS, { days, status: 'completed' })} className="stat-tile"
                style={{ textAlign: 'center', textDecoration: 'none', color: 'inherit' }} aria-label={`Answers analysed: ${competitor.answers}`}>
            <div className="stat-tile__value">{competitor.answers}</div>
            <div className="stat-tile__label">Answers analysed</div>
          </Link>
        ) : (
          <div className="stat-tile" style={{ textAlign: 'center' }}>
            <div className="stat-tile__value">0</div>
            <div className="stat-tile__label">Answers analysed</div>
          </div>
        )}
      </div>
      <p className="text-xs text-muted">
        Rate = answers naming {competitor.name} ÷ all answers analysed in the period. A sample of API answers, not market share.
      </p>

      <div className="card" id="competitor-observations">
        <div className="card-header flex items-center justify-between">
          <span>Answers that named {competitor.name} ({total})</span>
          {total > 0 && <Link to={drillHref(RUNS, { days, competitor_id: competitor.id })} className="text-sm">As answer list ›</Link>}
        </div>
        <div className="card-body" style={{ padding: 0 }}>
          {!obs ? <p className="text-sm text-muted" style={{ padding: '1rem' }}>Loading observations…</p> : (
            <DataTable
              columns={[
                { key: 'prompt_name', label: 'Question', render: (o) => <span title={o.prompt_text}>{o.prompt_name}</span> },
                { key: 'ai_provider', label: 'Assistant', render: (o) => PROVIDER_LABEL[o.ai_provider] || o.ai_provider,
                  drill: (o) => drillHref(RUNS, { days, competitor_id: competitor.id, provider: o.ai_provider }) },
                { key: 'market', label: 'Market', render: (o) => o.market || 'Unspecified' },
                { key: 'mention_order', label: 'Rank', render: (o) => (o.mention_order != null ? `#${o.mention_order}` : '—') },
                { key: 'evidence_excerpt', label: 'Excerpt', render: (o) => <span className="text-xs">{o.evidence_excerpt ? String(o.evidence_excerpt).slice(0, 180) : '—'}</span> },
                { key: 'asked_at', label: 'Asked', render: (o) => <span className="text-xs text-muted">{formatDateTime(o.asked_at)}</span> },
              ]}
              rows={obs.data || []}
              onRowClick={(o) => navigate(`${RUNS}/${o.run_id}`)}
              rowLabel={(o) => `Open the answer to ${o.prompt_name}`}
              emptyMessage={`No stored answer named ${competitor.name} in the last ${days} days.`}
            />
          )}
        </div>
      </div>
      <UrlPagination total={total} perPage={PER_PAGE} />
    </div>
  );
}
