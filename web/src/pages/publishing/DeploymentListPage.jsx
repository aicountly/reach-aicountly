import { useState, useEffect, useCallback } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../services/api';
import { usePermission } from '../../hooks/usePermission';
import { formatDate } from '../../utils/formatDate';

const STATUS_LABELS = {
  draft: 'Draft',
  ready: 'Ready',
  queued: 'Queued',
  sending: 'Sending',
  accepted: 'Accepted',
  scheduled: 'Scheduled',
  published: 'Published',
  verification_pending: 'Verification Pending',
  verified: 'Verified',
  failed: 'Failed',
  blocked: 'Blocked',
  cancelled: 'Cancelled',
  unpublished: 'Unpublished',
  rolled_back: 'Rolled Back',
};

const STATUS_CLASS = {
  published: 'badge--success',
  verified: 'badge--success',
  failed: 'badge--error',
  blocked: 'badge--error',
  queued: 'badge--info',
  sending: 'badge--info',
  cancelled: 'badge--neutral',
  rolled_back: 'badge--error',
};

/** Saved views — the dashboard's failure card deep-links into "Failed". */
const STATUS_FILTERS = [
  { key: '', label: 'All' },
  { key: 'failed,blocked', label: 'Failed' },
  { key: 'queued,sending,accepted,scheduled,verification_pending', label: 'In flight' },
  { key: 'published,verified', label: 'Live' },
];

const TYPE_FILTERS = [
  { key: '', label: 'All content' },
  { key: 'blog', label: 'Blog' },
  { key: 'knowledge_base', label: 'Knowledge base' },
];

const RETRYABLE = ['failed', 'blocked'];

export default function DeploymentListPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [deployments, setDeployments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [retryingId, setRetryingId] = useState(null);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const { has } = usePermission();
  const canPublish = has('publishing.publish');

  const status = searchParams.get('status') ?? '';
  const contentType = searchParams.get('content_type') ?? '';

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    const params = { page };
    if (status) params.status = status;
    if (contentType) params.content_type = contentType;

    api.get('v1/publishing/deployments', params)
      .then((r) => {
        const list = Array.isArray(r) ? r : (Array.isArray(r?.items) ? r.items : (Array.isArray(r?.data) ? r.data : []));
        setDeployments(list);
        setTotalPages(r?.last_page ?? r?.meta?.last_page ?? 1);
        setTotal(r?.total ?? r?.meta?.total ?? list.length);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, status, contentType]);

  useEffect(() => { load(); }, [load]);

  const setFilter = (key, value) => {
    const next = new URLSearchParams(searchParams);
    if (value) next.set(key, value); else next.delete(key);
    setSearchParams(next, { replace: true });
    setPage(1);
  };

  const retry = async (deployment) => {
    setRetryingId(deployment.id);
    setNotice(null);
    setError(null);
    try {
      await api.post(`v1/publishing/deployments/${deployment.id}/retry`);
      setNotice(`Re-queued deployment #${deployment.id}. It will run on the next worker pass.`);
      load();
    } catch (e) {
      setError(`Retry failed for #${deployment.id}: ${e.message}`);
    } finally {
      setRetryingId(null);
    }
  };

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Deployments</h1>
          <p className="page-header__subtitle">
            Track publish, unpublish, and rollback jobs across blogs and knowledge base.
          </p>
        </div>
        <div className="page-header__actions">
          <Link to="/publishing/blogs" className="btn btn--sm btn--secondary">View blogs</Link>
          <Link to="/publishing/knowledge-bases" className="btn btn--sm btn--secondary">View KB</Link>
        </div>
      </div>

      <div className="filter-bar" style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginBottom: 16 }}>
        <label>
          <span className="text-sm text-muted" style={{ display: 'block', marginBottom: 4 }}>Status</span>
          <select value={status} onChange={e => setFilter('status', e.target.value)}>
            {STATUS_FILTERS.map(f => <option key={f.key} value={f.key}>{f.label}</option>)}
          </select>
        </label>
        <label>
          <span className="text-sm text-muted" style={{ display: 'block', marginBottom: 4 }}>Content</span>
          <select value={contentType} onChange={e => setFilter('content_type', e.target.value)}>
            {TYPE_FILTERS.map(f => <option key={f.key} value={f.key}>{f.label}</option>)}
          </select>
        </label>
        <div style={{ alignSelf: 'flex-end' }}>
          <button type="button" className="btn btn--sm btn--secondary" onClick={load}>Refresh</button>
        </div>
      </div>

      {error && <p className="text-error">{error}</p>}
      {notice && <p className="text-success">{notice}</p>}

      {loading ? <p className="muted">Loading deployments…</p> : deployments.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>
              {status || contentType
                ? 'No deployments match this filter.'
                : 'No deployments yet. Publish blog or knowledge-base content to see jobs here.'}
            </p>
            <div className="btn-group mt-3">
              <Link to="/publishing/blogs" className="btn btn--sm btn--secondary">Open blog publishing</Link>
              <Link to="/publishing/readiness" className="btn btn--sm btn--secondary">Check readiness</Link>
            </div>
          </div>
        </div>
      ) : (
        <>
          <p className="text-sm text-muted" style={{ marginBottom: 8 }}>{total} deployment{total === 1 ? '' : 's'}</p>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Content</th>
                  <th>Type</th>
                  <th>Operation</th>
                  <th>Status</th>
                  <th>Reason</th>
                  <th>Attempts</th>
                  <th>Updated</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {deployments.map(d => (
                  <tr key={d.id}>
                    <td>{d.id}</td>
                    <td>
                      <Link to={`/content/${d.content_item_id}`}>{d.content_title ?? `#${d.content_item_id}`}</Link>
                    </td>
                    <td>{d.content_type}</td>
                    <td><code>{d.operation}</code></td>
                    <td>
                      <span className={`badge ${STATUS_CLASS[d.status] ?? 'badge--neutral'}`}>
                        {STATUS_LABELS[d.status] ?? d.status}
                      </span>
                    </td>
                    {/* Without this the only way to learn why a deployment
                        failed was to open each row one at a time. */}
                    <td style={{ maxWidth: 280 }}>
                      {d.error_category || d.redacted_error ? (
                        <span className="text-sm" title={d.redacted_error ?? ''}>
                          {d.error_category && <code>{d.error_category}</code>}
                          {d.error_category && d.redacted_error ? ' — ' : ''}
                          {d.redacted_error
                            ? (d.redacted_error.length > 90 ? `${d.redacted_error.slice(0, 90)}…` : d.redacted_error)
                            : ''}
                        </span>
                      ) : <span className="muted">—</span>}
                    </td>
                    <td>{d.attempt_count}</td>
                    <td>{formatDate(d.updated_at)}</td>
                    <td>
                      <div className="btn-group">
                        {canPublish && RETRYABLE.includes(d.status) && (
                          <button
                            type="button"
                            className="btn btn--sm btn--primary"
                            disabled={retryingId === d.id}
                            onClick={() => retry(d)}
                          >
                            {retryingId === d.id ? 'Retrying…' : 'Retry'}
                          </button>
                        )}
                        <Link to={`/publishing/deployments/${d.id}`} className="btn btn--sm btn--secondary">View</Link>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      {totalPages > 1 && (
        <div className="pagination">
          <button type="button" className="btn btn--sm btn--secondary" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Prev</button>
          <span className="pagination__info">Page {page} / {totalPages}</span>
          <button type="button" className="btn btn--sm btn--secondary" disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}>Next</button>
        </div>
      )}
    </div>
  );
}
