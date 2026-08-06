import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
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

export default function DeploymentListPage() {
  const [deployments, setDeployments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  useEffect(() => {
    setLoading(true);
    api.get('v1/publishing/deployments', { page })
      .then((r) => {
        const list = Array.isArray(r) ? r : (Array.isArray(r?.items) ? r.items : (Array.isArray(r?.data) ? r.data : []));
        setDeployments(list);
        setTotalPages(r?.last_page ?? r?.meta?.last_page ?? 1);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page]);

  if (loading) return <p className="muted">Loading deployments…</p>;
  if (error) return <p className="text-error">{error}</p>;

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

      {deployments.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>
              No deployments yet. Publish blog or knowledge-base content to see jobs here.
            </p>
            <div className="btn-group mt-3">
              <Link to="/publishing/blogs" className="btn btn--sm btn--secondary">Open blog publishing</Link>
              <Link to="/publishing/readiness" className="btn btn--sm btn--secondary">Check readiness</Link>
            </div>
          </div>
        </div>
      ) : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Content</th>
                <th>Type</th>
                <th>Operation</th>
                <th>Status</th>
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
                  <td>{d.attempt_count}</td>
                  <td>{d.updated_at ? formatDate(d.updated_at) : '—'}</td>
                  <td>
                    <Link to={`/publishing/deployments/${d.id}`} className="btn btn--sm btn--secondary">View</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
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
