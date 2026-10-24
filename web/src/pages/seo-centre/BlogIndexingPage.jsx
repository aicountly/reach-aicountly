import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { seoService } from '../../services/seoService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { DataTable } from '../../components/common/DataTable';
import { formatDateTime } from '../../utils/formatDate';
import { drillHref, useUrlFilter } from '../../components/drill';
import { FilterChips, UrlPagination } from './RecordFilters';
import { useParamsPatch, useUrlPage } from './recordLinks';

const PER_PAGE = 50;
const STATES = ['not_submitted', 'sitemap_included', 'discovered', 'crawled', 'indexed', 'excluded', 'error', 'unknown'];
const human = (s) => String(s ?? '').replace(/_/g, ' ');

/**
 * Blog posts by indexing status — the rows behind the SEO overview's
 * "Blog indexing status" counts (one publication profile per post).
 */
export function BlogIndexingPage() {
  const [status] = useUrlFilter('status');
  const [q] = useUrlFilter('q');
  const [page] = useUrlPage();
  const patch = useParamsPatch();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    seoService.listBlogIndexing({ status, q, page, per_page: PER_PAGE })
      .then((res) => { setData(res); setError(''); })
      .catch((err) => setError(err?.message || 'Unable to load blog indexing status.'));
  }, [status, q, page]);

  const counts = data?.counts || {};
  const total = data?.meta?.total ?? 0;
  const pick = (s) => patch({ status: s === status ? '' : s }, { push: true });

  return (
    <div>
      <h2 style={{ fontSize: '1.1rem', margin: '0 0 0.75rem' }}>Blog indexing status</h2>
      {error && <Alert variant="error">{error}</Alert>}

      <div className="stat-grid mb-3">
        {STATES.filter((s) => counts[s] > 0).map((s) => (
          <button key={s} type="button" className="stat-tile" aria-pressed={status === s}
                  onClick={() => pick(s)} aria-label={`${human(s)}: ${counts[s]}`}>
            <div className="stat-tile__value">{counts[s]}</div>
            <div className="stat-tile__label">{human(s)}</div>
          </button>
        ))}
      </div>

      <Card>
        <div className="flex items-center gap-2 flex-wrap" style={{ marginBottom: '0.75rem' }}>
          <label className="text-sm text-muted" htmlFor="idx-status">Status</label>
          <select id="idx-status" className="form-select form-select--sm" value={status}
                  onChange={(e) => patch({ status: e.target.value })}>
            <option value="">All</option>
            {STATES.map((s) => <option key={s} value={s}>{human(s)}</option>)}
          </select>
          <input key={q} type="search" placeholder="Title or slug contains…" aria-label="Title or slug contains"
                 defaultValue={q} onKeyDown={(e) => { if (e.key === 'Enter') patch({ q: e.currentTarget.value.trim() }); }}
                 style={{ maxWidth: 260 }} />
        </div>
        <FilterChips labels={{ status: 'Status', q: 'Contains' }} format={(k, v) => (k === 'status' ? human(v) : v)} />
        {!data && !error ? <Loader label="Loading posts…" /> : (
          <>
            <p className="text-sm text-muted" style={{ marginTop: 0 }}>{total} post{total === 1 ? '' : 's'}</p>
            <DataTable
              rowKey={(r) => r.profile_id}
              columns={[
                { key: 'title', label: 'Post', render: (r) => r.title || `#${r.content_item_id}`, drill: (r) => `/content/${r.content_item_id}` },
                { key: 'indexing_status', label: 'Indexing', render: (r) => human(r.indexing_status),
                  drill: (r) => (r.indexing_status !== status ? { onClick: () => pick(r.indexing_status), title: 'Only this status' } : null) },
                { key: 'indexing_checked_at', label: 'Checked', render: (r) => (r.indexing_checked_at ? formatDateTime(r.indexing_checked_at) : '—') },
                { key: 'workflow_status', label: 'Workflow', render: (r) => human(r.workflow_status) },
                { key: 'public_url', label: 'Public URL', render: (r) => (r.public_url
                  ? <a href={r.public_url} target="_blank" rel="noreferrer">{r.public_url}</a> : <span className="text-muted">—</span>) },
                { key: 'search', label: '', render: (r) => (r.public_url
                  ? <Link to={drillHref('/seo-centre/search', { page: r.public_url, by: 'query' })} className="text-sm">Search queries ›</Link> : null) },
              ]}
              rows={data?.posts || []}
              emptyMessage={status ? `No posts are ${human(status)}.` : 'No blog publication profiles yet.'}
            />
            <UrlPagination total={total} perPage={PER_PAGE} />
          </>
        )}
      </Card>
    </div>
  );
}

export default BlogIndexingPage;
