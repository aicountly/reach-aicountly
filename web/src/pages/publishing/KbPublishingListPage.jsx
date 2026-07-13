import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

const STATUS_BADGES = {
  draft: 'badge--neutral',
  queued: 'badge--info',
  published: 'badge--success',
  verified: 'badge--success',
  failed: 'badge--error',
  blocked: 'badge--error',
};

export default function KbPublishingListPage() {
  const [deployments, setDeployments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/publishing/knowledge-bases')
      .then(r => setDeployments(r.data?.data ?? []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading KB deployments…</p>;
  if (error) return <p className="text-error">{error}</p>;

  return (
    <div>
      <div className="page-header">
        <h1>Knowledge Base Publishing</h1>
      </div>

      {deployments.length === 0 ? (
        <p className="muted">No knowledge-base deployments yet.</p>
      ) : (
        <table className="data-table">
          <thead>
            <tr>
              <th>Content</th>
              <th>Article Type</th>
              <th>Status</th>
              <th>Attempts</th>
              <th>Updated</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {deployments.map(d => (
              <tr key={d.id}>
                <td>
                  <Link to={`/content/${d.content_item_id}`}>{d.content_title ?? `#${d.content_item_id}`}</Link>
                </td>
                <td>{d.article_type ?? '—'}</td>
                <td>
                  <span className={`badge ${STATUS_BADGES[d.status] ?? 'badge--neutral'}`}>{d.status}</span>
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
      )}
    </div>
  );
}
