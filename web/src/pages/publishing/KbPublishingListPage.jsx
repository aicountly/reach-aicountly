import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { formatDate } from '../../utils/formatDate';

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
    api.get('v1/publishing/knowledge-bases')
      .then((r) => {
        const list = Array.isArray(r) ? r : (Array.isArray(r?.items) ? r.items : (Array.isArray(r?.data) ? r.data : []));
        setDeployments(list);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading KB deployments…</p>;
  if (error) return <p className="text-error">{error}</p>;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Knowledge Base Publishing</h1>
          <p className="page-header__subtitle">KB article deployments queued for the public site.</p>
        </div>
      </div>

      {deployments.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>No knowledge-base deployments yet.</p>
            <Link to="/publishing/deployments" className="stat-card__link">Open all deployments →</Link>
          </div>
        </div>
      ) : (
        <div className="table-wrap">
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
    </div>
  );
}
