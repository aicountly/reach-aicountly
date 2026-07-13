import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

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
    api.get('/publishing/deployments', { params: { page } })
      .then(r => {
        setDeployments(r.data?.data ?? []);
        setTotalPages(r.data?.meta?.last_page ?? 1);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page]);

  if (loading) return <p className="muted">Loading deployments…</p>;
  if (error) return <p className="text-error">{error}</p>;

  return (
    <div>
      <div className="page-header">
        <h1>Deployments</h1>
      </div>

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
          {deployments.length === 0 ? (
            <tr><td colSpan={8} className="muted">No deployments.</td></tr>
          ) : deployments.map(d => (
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
              <td>{d.updated_at ? new Date(d.updated_at).toLocaleDateString() : '—'}</td>
              <td>
                <Link to={`/publishing/deployments/${d.id}`} className="btn btn--sm">View</Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {totalPages > 1 && (
        <div className="pagination">
          <button className="btn btn--sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Prev</button>
          <span className="pagination__info">Page {page} / {totalPages}</span>
          <button className="btn btn--sm" disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}>Next</button>
        </div>
      )}
    </div>
  );
}
