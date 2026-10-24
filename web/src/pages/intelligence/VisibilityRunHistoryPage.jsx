import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { History, CheckCircle, XCircle } from 'lucide-react';
import { listVisibilityRunRecords } from '../../services/growthIntelService.js';
import { formatDateTime } from '../../utils/formatDate';
import { DataTable } from '../../components/common/DataTable';
import { FilterChips, UrlPagination } from '../seo-centre/RecordFilters';
import { useParamsPatch, useUrlPage } from '../seo-centre/recordLinks';
import { PROVIDER_LABEL, RUN_FILTER_LABELS, formatRunFilter } from './visibilityLabels';

const PER_PAGE = 50;
const FILTER_KEYS = Object.keys(RUN_FILTER_LABELS);
const STATUSES = ['completed', 'failed', 'queued', 'running', 'cancelled'];
const STATUS_BADGE = { completed: 'badge-success', failed: 'badge-danger', running: 'badge-info', queued: 'badge-secondary' };

/**
 * Every AI-visibility answer (one run = one question asked of one
 * assistant), filtered from the URL with the same rules the overview counts
 * with — so "Gemini: 12 answers" opens exactly those 12. A row opens the
 * answer with its text, observations and citations.
 */
export default function VisibilityRunHistoryPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [page] = useUrlPage();
  const patch = useParamsPatch();
  const [res, setRes] = useState(null);
  const [error, setError] = useState(null);

  const query = Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) || '']).filter(([, v]) => v));
  const queryKey = JSON.stringify(query);

  useEffect(() => {
    setError(null);
    listVisibilityRunRecords({ ...JSON.parse(queryKey), page, per_page: PER_PAGE })
      .then(setRes)
      .catch((e) => setError(e.message || 'Failed to load runs'));
  }, [queryKey, page]);

  const rows = res?.data ?? [];
  const total = res?.meta?.total ?? 0;
  const narrow = (key, value) => (params.get(key) === String(value) ? null : { onClick: () => patch({ [key]: value }, { push: true }), title: 'Only these answers' });

  return (
    <div>
      <div className="flex items-center gap-3 mb-3">
        <History size={26} style={{ color: 'var(--color-primary)' }} aria-hidden="true" />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Visibility Run History</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            Every question asked of each assistant, with what the answer named. Raw answers are stored unchanged.
          </p>
        </div>
      </div>

      <div className="flex items-center gap-2 flex-wrap mb-3">
        <label className="text-sm text-muted" htmlFor="run-days">Period</label>
        <select id="run-days" className="form-select form-select--sm" value={params.get('days') || ''}
                onChange={(e) => patch({ days: e.target.value })}>
          <option value="">All time</option>
          <option value="7">Last 7 days</option>
          <option value="28">Last 28 days</option>
          <option value="90">Last 90 days</option>
        </select>
        <label className="text-sm text-muted" htmlFor="run-status">Status</label>
        <select id="run-status" className="form-select form-select--sm" value={params.get('status') || ''}
                onChange={(e) => patch({ status: e.target.value })}>
          <option value="">Any</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <input key={params.get('q') || ''} type="search" placeholder="Question contains…" aria-label="Question contains"
               defaultValue={params.get('q') || ''} style={{ maxWidth: 260 }}
               onKeyDown={(e) => { if (e.key === 'Enter') patch({ q: e.currentTarget.value.trim() }); }} />
      </div>

      <FilterChips labels={RUN_FILTER_LABELS} format={formatRunFilter} />
      {error && <div className="alert alert-danger">{error}</div>}
      {!res && !error && <p className="text-sm text-muted">Loading runs…</p>}

      {res && (
        <div className="card">
          <div className="card-header">{total} answer{total === 1 ? '' : 's'}</div>
          <div className="card-body" style={{ padding: 0 }}>
            <DataTable
              rowKey={(r) => r.run_id}
              columns={[
                { key: 'question', label: 'Question', render: (r) => <span title={r.question}>{r.prompt_name || r.question}</span> },
                { key: 'provider', label: 'Assistant', render: (r) => PROVIDER_LABEL[r.provider] || r.provider, drill: (r) => narrow('provider', r.provider) },
                { key: 'market', label: 'Market', render: (r) => r.market || 'Unspecified', drill: (r) => narrow('market', r.market || 'Unspecified') },
                { key: 'status', label: 'Status', render: (r) => <span className={`badge ${STATUS_BADGE[r.status] || 'badge-secondary'}`}>{r.status}</span>,
                  drill: (r) => narrow('status', r.status) },
                { key: 'brand', label: 'Brand', render: (r) => {
                  if (r.brand_mentioned === true) return <span><CheckCircle size={14} style={{ color: 'var(--color-success)', verticalAlign: '-2px' }} aria-label="Brand named" />{r.brand_rank ? ` #${r.brand_rank}` : ''}</span>;
                  if (r.brand_counted === false) return <span className="text-xs text-muted" title="The question names the brand, so it is not counted">in question</span>;
                  if (r.brand_mentioned === false) return <XCircle size={14} style={{ color: 'var(--color-danger)', verticalAlign: '-2px' }} aria-label="Brand not named" />;
                  return <span className="text-muted">—</span>;
                } },
                { key: 'competitors', label: 'Named', render: (r) => (r.competitors?.length ? r.competitors.slice(0, 4).join(', ') : '—') },
                { key: 'asked_at', label: 'Asked', render: (r) => <span className="text-xs text-muted">{formatDateTime(r.asked_at)}</span> },
              ]}
              rows={rows}
              onRowClick={(r) => navigate(`/intelligence/visibility/runs/${r.run_id}`)}
              rowLabel={(r) => `Open answer ${r.run_id}: ${r.prompt_name || r.question}`}
              emptyMessage={Object.keys(query).length ? 'No answers match these filters.' : 'No AI-visibility runs recorded yet.'}
            />
          </div>
        </div>
      )}
      <UrlPagination total={total} perPage={PER_PAGE} />
    </div>
  );
}
