import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { seoService } from '../../services/seoService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { DataTable } from '../../components/common/DataTable';
import { Breadcrumbs } from '../../components/common/Breadcrumbs';
import { formatDateTime } from '../../utils/formatDate';
import { useUrlFilter } from '../../components/drill';
import { FilterChips, UrlPagination } from './RecordFilters';
import { useParamsPatch, useUrlPage } from './recordLinks';

const PER_PAGE = 50;
const STATUSES = ['submitted', 'pending', 'retrying', 'failed', 'cancelled'];
const BADGE = { submitted: 'badge-success', retrying: 'badge-warning', pending: 'badge-info', failed: 'badge-danger' };

/**
 * Every IndexNow submission, filterable by status (one or a comma list, as
 * the "Retrying" tile sends) and URL. A row opens its attempts.
 */
export function IndexNowSubmissionsPage() {
  const navigate = useNavigate();
  const [status] = useUrlFilter('status');
  const [url] = useUrlFilter('url');
  const [page] = useUrlPage();
  const patch = useParamsPatch();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    seoService.listIndexNowSubmissions({ status, url, page, per_page: PER_PAGE })
      .then((res) => { setData(res); setError(''); })
      .catch((err) => setError(err?.message || 'Unable to load IndexNow submissions.'));
  }, [status, url, page]);

  const counts = data?.counts || {};
  const total = data?.meta?.total ?? 0;
  const pick = (s) => patch({ status: s === status ? '' : s }, { push: true });

  return (
    <div>
      <Breadcrumbs items={[{ label: 'IndexNow', to: '/seo-centre/indexnow' }, { label: 'Submissions' }]} />
      {error && <Alert variant="error">{error}</Alert>}

      <div className="stat-grid mb-3">
        {[['', 'All', counts.total], ...STATUSES.map((s) => [s, s, counts[s]])].filter(([, , n]) => n > 0).map(([s, label, n]) => (
          <button key={label} type="button" className="stat-tile" aria-pressed={status === s}
                  onClick={() => pick(s)} aria-label={`${label}: ${n}`}>
            <div className="stat-tile__value">{n}</div>
            <div className="stat-tile__label" style={{ textTransform: 'capitalize' }}>{label}</div>
          </button>
        ))}
      </div>

      <Card>
        <div className="flex items-center gap-2 flex-wrap" style={{ marginBottom: '0.75rem' }}>
          <label className="text-sm text-muted" htmlFor="inx-status">Status</label>
          <select id="inx-status" className="form-select form-select--sm" value={STATUSES.includes(status) ? status : ''}
                  onChange={(e) => patch({ status: e.target.value })}>
            <option value="">All</option>
            {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
          <input key={url} type="search" placeholder="URL contains…" aria-label="URL contains" defaultValue={url}
                 onKeyDown={(e) => { if (e.key === 'Enter') patch({ url: e.currentTarget.value.trim() }); }}
                 style={{ maxWidth: 280 }} />
        </div>
        <FilterChips labels={{ status: 'Status', url: 'URL contains' }} format={(k, v) => (k === 'status' ? v.replace(/,/g, ' or ') : v)} />
        {!data && !error ? <Loader label="Loading submissions…" /> : (
          <>
            <p className="text-sm text-muted" style={{ marginTop: 0 }}>{total} submission{total === 1 ? '' : 's'}</p>
            <DataTable
              columns={[
                { key: 'url', label: 'URL', render: (r) => <span style={{ wordBreak: 'break-all' }}>{r.url}</span> },
                { key: 'status', label: 'Status', render: (r) => <span className={`badge ${BADGE[r.status] || 'badge-secondary'}`}>{r.status}</span> },
                { key: 'attempt_count', label: 'Attempts', render: (r) => `${r.attempt_count}/${r.max_attempts}` },
                { key: 'last_error', label: 'Last result', render: (r) => (r.last_error
                  ? <span className="text-sm" style={{ color: 'var(--color-danger)' }}>{r.last_http_status ? `HTTP ${r.last_http_status} · ` : ''}{r.last_error}</span>
                  : (r.last_http_status ? `HTTP ${r.last_http_status}` : '—')) },
                { key: 'submitted_at', label: 'Submitted', render: (r) => (r.submitted_at ? formatDateTime(r.submitted_at) : '—') },
                { key: 'created_at', label: 'Created', render: (r) => formatDateTime(r.created_at) },
              ]}
              rows={data?.submissions || []}
              onRowClick={(r) => navigate(`/seo-centre/indexnow/submissions/${r.id}`)}
              rowLabel={(r) => `Open submission ${r.url}`}
              emptyMessage={status || url ? 'No submissions match these filters.' : 'No IndexNow submissions yet.'}
            />
            <UrlPagination total={total} perPage={PER_PAGE} />
          </>
        )}
      </Card>
    </div>
  );
}

export default IndexNowSubmissionsPage;
