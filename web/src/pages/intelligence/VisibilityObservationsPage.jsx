import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Telescope, CheckCircle, XCircle, AlertCircle } from 'lucide-react';
import { listVisibilityObservationRecords } from '../../services/growthIntelService.js';
import { formatDateTime } from '../../utils/formatDate';
import { DataTable } from '../../components/common/DataTable';
import { FilterChips, UrlPagination } from '../seo-centre/RecordFilters';
import { useParamsPatch, useUrlPage } from '../seo-centre/recordLinks';
import { PROVIDER_LABEL } from './visibilityLabels';

const PER_PAGE = 50;
const LABELS = {
  run_id: 'Answer', coverage_state: 'Coverage', mention_type: 'Type', entity: 'Name', competitor_id: 'Competitor',
  provider: 'Assistant', prompt_id: 'Question', days: 'Period', counted: 'Counted only',
};
const format = (k, v) => {
  if (k === 'days') return `last ${v} days`;
  if (k === 'provider') return PROVIDER_LABEL[v] || v;
  if (k === 'counted') return 'not named in the question';
  if (k === 'run_id' || k === 'prompt_id' || k === 'competitor_id') return `#${v}`;
  return String(v).replace(/_/g, ' ');
};

const CoverageIcon = ({ coverage }) => {
  if (coverage === 'mentioned') return <CheckCircle size={14} style={{ color: 'var(--color-success)' }} aria-hidden="true" />;
  if (coverage === 'not_mentioned') return <XCircle size={14} style={{ color: 'var(--color-text-muted)' }} aria-hidden="true" />;
  return <AlertCircle size={14} style={{ color: 'var(--color-warning)' }} aria-hidden="true" />;
};

/**
 * Raw observation records: one row per name the parser looked for in one
 * answer. Filters come from the URL; a row opens its answer.
 */
export default function VisibilityObservationsPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [page] = useUrlPage();
  const patch = useParamsPatch();
  const [res, setRes] = useState(null);
  const [error, setError] = useState(null);

  const query = Object.fromEntries(Object.keys(LABELS).map((k) => [k, params.get(k) || '']).filter(([, v]) => v));
  const queryKey = JSON.stringify(query);

  useEffect(() => {
    setError(null);
    listVisibilityObservationRecords({ ...JSON.parse(queryKey), page, per_page: PER_PAGE })
      .then(setRes)
      .catch((e) => setError(e.message || 'Failed to load observations'));
  }, [queryKey, page]);

  const total = res?.meta?.total ?? 0;
  const narrow = (key, value) => (value == null || params.get(key) === String(value)
    ? null : { onClick: () => patch({ [key]: value }, { push: true }), title: 'Only these observations' });

  return (
    <div>
      <div className="flex items-center gap-3 mb-3">
        <Telescope size={26} style={{ color: 'var(--color-primary)' }} aria-hidden="true" />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>AI Visibility Observations</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>Each name the parser found (or looked for) in each stored answer</p>
        </div>
      </div>

      <div className="alert alert-warning">
        <strong>Sample scope:</strong> observations come from a small sample of API answers (one per prompt per assistant per day).
        They are not query volumes, live rankings or market share.
      </div>

      <div className="flex items-center gap-2 flex-wrap mb-3">
        <label className="text-sm text-muted" htmlFor="obs-coverage">Coverage</label>
        <select id="obs-coverage" className="form-select form-select--sm" value={params.get('coverage_state') || ''}
                onChange={(e) => patch({ coverage_state: e.target.value })}>
          <option value="">Any</option>
          <option value="mentioned">mentioned</option>
          <option value="not_mentioned">not mentioned</option>
        </select>
        <label className="text-sm text-muted" htmlFor="obs-type">Type</label>
        <select id="obs-type" className="form-select form-select--sm" value={params.get('mention_type') || ''}
                onChange={(e) => patch({ mention_type: e.target.value })}>
          <option value="">Any</option>
          <option value="brand">brand</option>
          <option value="competitor">competitor</option>
        </select>
      </div>

      <FilterChips labels={LABELS} format={format} />
      {error && <div className="alert alert-danger">{error}</div>}
      {!res && !error && <p className="text-sm text-muted">Loading observations…</p>}

      {res && (
        <div className="card">
          <div className="card-header">{total} observation{total === 1 ? '' : 's'}</div>
          <div className="card-body" style={{ padding: 0 }}>
            <DataTable
              columns={[
                { key: 'entity_mentioned', label: 'Name', render: (o) => <strong>{o.entity_mentioned}</strong>,
                  drill: (o) => (o.competitor_id ? `/intelligence/competitors/${o.competitor_id}` : narrow('mention_type', o.mention_type)) },
                { key: 'coverage_state', label: 'Coverage', render: (o) => (
                  <span style={{ display: 'inline-flex', gap: 4, alignItems: 'center' }}>
                    <CoverageIcon coverage={o.coverage_state} /> {String(o.coverage_state).replace(/_/g, ' ')}
                    {o.in_question && <span className="text-xs text-muted">(in question)</span>}
                  </span>
                ), drill: (o) => narrow('coverage_state', o.coverage_state) },
                { key: 'mention_order', label: 'Rank', render: (o) => (o.mention_order != null ? `#${o.mention_order}` : '—') },
                { key: 'evidence_excerpt', label: 'Excerpt', render: (o) => <span className="text-xs">{o.evidence_excerpt ? String(o.evidence_excerpt).slice(0, 160) : '—'}</span> },
                { key: 'ai_provider', label: 'Assistant', render: (o) => PROVIDER_LABEL[o.ai_provider] || o.ai_provider, drill: (o) => narrow('provider', o.ai_provider) },
                { key: 'prompt_name', label: 'Question', render: (o) => <span title={o.prompt_text}>{o.prompt_name}</span>, drill: (o) => narrow('prompt_id', o.prompt_id) },
                { key: 'asked_at', label: 'Asked', render: (o) => <span className="text-xs text-muted">{formatDateTime(o.asked_at)}</span> },
              ]}
              rows={res.data ?? []}
              onRowClick={(o) => navigate(`/intelligence/visibility/runs/${o.run_id}`)}
              rowLabel={(o) => `Open the answer for ${o.entity_mentioned}`}
              emptyMessage={Object.keys(query).length ? 'No observations match these filters.' : 'No observations recorded yet.'}
            />
          </div>
        </div>
      )}
      <UrlPagination total={total} perPage={PER_PAGE} />
    </div>
  );
}
