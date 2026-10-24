import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { analyticsService } from '../../services/analyticsService';
import { blogService } from '../../services/blogService';
import { useAuth } from '../../context/AuthContext';
import { DataTable } from '../../components/common/DataTable';
import { Pagination } from '../../components/common/Pagination';
import { StatusBadge } from '../../components/common/StatusBadge';
import { DrillPath, useDrillFilters } from '../../components/drill';
import { ROUTES } from '../../constants/routes';

const BUCKETS = [
  { key: 'drafts', label: 'Blog drafts', hint: 'draft, SEO review, internal review' },
  { key: 'published', label: 'Published', hint: 'in the window' },
  { key: 'approval_pending', label: 'Approvals pending', hint: 'approval status pending' },
];
const BUCKET_NAMES = Object.fromEntries(BUCKETS.map((b) => [b.key, b.label]));
const STATUSES = ['idea', 'draft', 'seo_review', 'internal_review', 'approved', 'scheduled', 'published', 'rejected', 'archived'];
const APPROVALS = ['pending', 'approved', 'rejected', 'not_required'];
const LABELS = { bucket: 'Tile', status: 'Status', approval_status: 'Approval', days: 'Published in the last', q: 'Search' };
const PAGE_SIZE = 25;

const formatFilter = (key, value) => {
  if (key === 'bucket') return BUCKET_NAMES[value] || value;
  if (key === 'days') return `${value} days`;
  return String(value).replace(/_/g, ' ');
};

function when(value) {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString();
}

/**
 * Posts in the legacy reach_blog_posts table — the records behind the
 * Analytics page's Blog drafts / Blog published / Approvals pending tiles.
 * The table is still written by the bot's "Generate blog draft", but its old
 * list (/blog) now redirects to Blog Command Centre, so this is where those
 * rows can be found and acted on. Filters live in the URL.
 */
export function LegacyBlogPostsPage() {
  const drill = useDrillFilters(['bucket', 'status', 'approval_status', 'days', 'q']);
  const { filters, params } = drill;
  const page = Math.max(1, Number(params.get('page')) || 1);
  const { hasPermission } = useAuth();
  const canApprove = hasPermission?.('blog.approve');
  const [state, setState] = useState({ loading: true, error: null, data: null });
  const [expanded, setExpanded] = useState(null);
  const [search, setSearch] = useState(filters.q);
  const [busy, setBusy] = useState(null);
  const [reload, setReload] = useState(0);
  const filterKey = JSON.stringify(filters);

  useEffect(() => { setSearch(filters.q); }, [filters.q]);

  useEffect(() => {
    let cancelled = false;
    setState((s) => ({ ...s, loading: true, error: null }));
    analyticsService.legacyBlogPosts({ ...filters, page, limit: PAGE_SIZE })
      .then((data) => { if (!cancelled) setState({ loading: false, error: null, data }); })
      .catch((e) => { if (!cancelled) setState({ loading: false, error: e.message, data: null }); });
    return () => { cancelled = true; };
    // filterKey captures every filter value.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filterKey, page, reload]);

  const { loading, error, data } = state;
  const items = data?.items || [];
  const counts = data?.counts || {};
  const days = data?.days || 30;
  const setFilter = (patch) => drill.drill({ ...patch, page: '' });

  const decide = async (post, action) => {
    setBusy(post.id);
    try {
      if (action === 'approve') await blogService.approve(post.id);
      else await blogService.reject(post.id, 'Rejected from Analytics › legacy blog posts');
      setReload((n) => n + 1);
    } catch (e) {
      setState((s) => ({ ...s, error: e.message }));
    } finally {
      setBusy(null);
    }
  };

  const columns = [
    {
      key: 'title',
      label: 'Post',
      render: (r) => (
        <div>
          <div style={{ fontWeight: 500 }}>{r.title || '(untitled)'}</div>
          <div className="text-xs text-muted">{r.slug}{r.bot_generated ? ' · bot draft' : ''}</div>
        </div>
      ),
    },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'approval_status', label: 'Approval', render: (r) => <StatusBadge status={r.approval_status || 'none'} /> },
    { key: 'updated_at', label: 'Updated', render: (r) => when(r.updated_at) },
    { key: 'published_at', label: 'Published', render: (r) => when(r.published_at) },
    {
      key: 'content_item_id',
      label: 'Content Studio',
      render: (r) => (r.content_item_id ? 'Open ›' : <span className="text-muted">Not migrated</span>),
      drill: (r) => (r.content_item_id ? `/content/${r.content_item_id}` : null),
    },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Legacy blog posts</h1>
          <p className="text-sm text-muted">
            <Link to={ROUTES.ANALYTICS}>Analytics</Link> › rows of the legacy blog table (bot drafts and pre-Content Studio posts).
          </p>
        </div>
      </div>

      <div className="grid grid-3" style={{ marginBottom: '1rem' }}>
        {BUCKETS.map((b) => (
          <button
            key={b.key}
            type="button"
            className="stat-tile"
            aria-pressed={filters.bucket === b.key}
            onClick={() => setFilter({ bucket: filters.bucket === b.key ? '' : b.key, days: b.key === 'published' ? (filters.days || '30') : filters.days })}
          >
            <div className="stat-tile__label">{b.label}</div>
            <div className="stat-tile__value">{data ? (counts[b.key] ?? 0) : '…'}</div>
            <div className="stat-tile__hint">{b.key === 'published' ? `last ${days} days` : b.hint}</div>
          </button>
        ))}
      </div>

      <DrillPath drill={drill} rootLabel="All legacy posts" labels={LABELS} format={formatFilter} />

      <div className="flex gap-2" style={{ flexWrap: 'wrap', margin: '0.75rem 0' }}>
        <select className="input" aria-label="Status" value={filters.status} onChange={(e) => setFilter({ status: e.target.value })} style={{ width: 'auto' }}>
          <option value="">Any status</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
        </select>
        <select className="input" aria-label="Approval" value={filters.approval_status} onChange={(e) => setFilter({ approval_status: e.target.value })} style={{ width: 'auto' }}>
          <option value="">Any approval</option>
          {APPROVALS.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
        </select>
        <form onSubmit={(e) => { e.preventDefault(); drill.set({ q: search.trim(), page: '' }); }}>
          <input
            type="search"
            aria-label="Search title or slug"
            placeholder="Search title or slug…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            style={{ minWidth: 220 }}
          />
        </form>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}

      {loading && !data ? (
        <p className="text-sm text-muted">Loading posts…</p>
      ) : (
        <>
          <p className="text-xs text-muted" style={{ margin: '0 0 0.5rem' }}>
            {(data?.total ?? 0).toLocaleString()} post{data?.total === 1 ? '' : 's'} · click a row for its content and actions
          </p>
          <DataTable
            columns={columns}
            rows={items}
            onRowClick={(r) => setExpanded(expanded === r.id ? null : r.id)}
            rowLabel={(r) => `Legacy blog post ${r.title || r.id}`}
            expandedRowId={expanded}
            renderExpanded={(r) => (
              <div style={{ padding: '0.75rem 1rem' }} data-testid={`legacy-post-${r.id}`}>
                <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.5rem 1.25rem', margin: '0 0 0.75rem' }}>
                  <div><dt className="text-xs text-secondary">Post #</dt><dd style={{ margin: 0 }}>{r.id}</dd></div>
                  <div><dt className="text-xs text-secondary">Created</dt><dd style={{ margin: 0 }}>{when(r.created_at)}</dd></div>
                  <div><dt className="text-xs text-secondary">Focus keyword</dt><dd style={{ margin: 0 }}>{r.focus_keyword || '—'}</dd></div>
                  <div><dt className="text-xs text-secondary">Publishing</dt><dd style={{ margin: 0 }}>{r.publishing_status || '—'}{r.publishing_error ? ` (${r.publishing_error})` : ''}</dd></div>
                  <div><dt className="text-xs text-secondary">Approved</dt><dd style={{ margin: 0 }}>{when(r.approved_at)}</dd></div>
                  {r.canonical_url && (
                    <div><dt className="text-xs text-secondary">Canonical URL</dt><dd style={{ margin: 0, wordBreak: 'break-all' }}><a href={r.canonical_url} target="_blank" rel="noreferrer">{r.canonical_url}</a></dd></div>
                  )}
                </dl>
                {r.excerpt && <p className="text-sm" style={{ margin: '0 0 0.5rem' }}><strong>Excerpt:</strong> {r.excerpt}</p>}
                <p className="text-sm" style={{ margin: '0 0 0.75rem', whiteSpace: 'pre-wrap' }}>
                  {r.content_preview || <span className="text-muted">(no body)</span>}
                  {r.content_length > (r.content_preview || '').length && <span className="text-muted"> … ({r.content_length.toLocaleString()} characters)</span>}
                </p>
                <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
                  {r.content_item_id ? (
                    <Link className="btn btn-secondary btn-sm" to={`/content/${r.content_item_id}`}>Open in Content Studio ›</Link>
                  ) : (
                    <span className="text-xs text-muted">Not in Content Studio yet — <code>php spark reach:blog-migrate-legacy</code> moves it across.</span>
                  )}
                  {canApprove && r.approval_status === 'pending' && (
                    <>
                      <button type="button" className="btn btn-primary btn-sm" disabled={busy === r.id} onClick={() => decide(r, 'approve')}>Approve</button>
                      <button type="button" className="btn btn-secondary btn-sm" disabled={busy === r.id} onClick={() => decide(r, 'reject')}>Reject</button>
                    </>
                  )}
                </div>
              </div>
            )}
            emptyMessage={Object.values(filters).some(Boolean) ? 'No legacy posts match these filters.' : 'The legacy blog table is empty.'}
          />
          <Pagination page={page} limit={PAGE_SIZE} total={data?.total ?? 0} onPage={(p) => drill.set({ page: p > 1 ? p : '' })} />
        </>
      )}
    </div>
  );
}

export default LegacyBlogPostsPage;
