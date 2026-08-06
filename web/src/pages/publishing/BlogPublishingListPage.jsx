import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { formatDate } from '../../utils/formatDate';

const STATUS_BADGES = {
  draft: 'badge--neutral',
  queued: 'badge--info',
  sending: 'badge--info',
  accepted: 'badge--warning',
  published: 'badge--success',
  verified: 'badge--success',
  failed: 'badge--error',
  blocked: 'badge--error',
  cancelled: 'badge--neutral',
  rolled_back: 'badge--error',
};

export default function BlogPublishingListPage() {
  const [deployments, setDeployments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);

    api.get('v1/publishing/blogs')
      .then((r) => {
        if (!cancelled) {
          setDeployments(Array.isArray(r) ? r : (r?.items ?? r?.data ?? []));
        }
      })
      .catch((e) => {
        if (!cancelled) {
          setError(e.message || 'Request failed');
          setDeployments([]);
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => { cancelled = true; };
  }, []);

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Blog Publishing</h1>
          <p className="page-header__subtitle">Blog deployments queued for the public site.</p>
        </div>
      </div>

      {loading && <p className="muted">Loading blog deployments…</p>}
      {error && <p className="text-error" role="alert">{error}</p>}

      {!loading && !error && deployments.length === 0 && (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>No blog deployments yet.</p>
            <Link to="/publishing/deployments" className="stat-card__link">Open all deployments →</Link>
          </div>
        </div>
      )}

      {!loading && deployments.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Content</th>
                <th>Status</th>
                <th>Canonical URL</th>
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
                  <td>
                    <span className={`badge ${STATUS_BADGES[d.status] ?? 'badge--neutral'}`}>{d.status}</span>
                  </td>
                  <td>
                    {d.canonical_url
                      ? <a href={d.canonical_url} target="_blank" rel="noopener noreferrer" className="link-external">{d.canonical_url}</a>
                      : <span className="muted">—</span>}
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
