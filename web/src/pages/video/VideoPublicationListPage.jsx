import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { normalizeVideoList, normalizeVideoTotal } from './videoListUtils';
import { formatDate } from '../../utils/formatDate';

export default function VideoPublicationListPage() {
  const [pubs, setPubs]       = useState([]);
  const [total, setTotal]     = useState(0);
  const [page, setPage]       = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get('v1/video/publications', { page, per_page: perPage })
      .then((r) => {
        const rows = normalizeVideoList(r);
        setPubs(rows);
        setTotal(normalizeVideoTotal(r, rows));
      })
      .catch((e) => setError(e?.message || 'Failed to load publications.'))
      .finally(() => setLoading(false));
  }, [page]);

  useEffect(() => { load(); }, [load]);

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Video Publications</h1>
        <p className="page-header__subtitle">YouTube publication history</p>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading publications…</p>}

      {!loading && pubs.length === 0 && (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>
              No publications yet. Render and publish a video project to see it here.
            </p>
            <Link to="/video/projects" className="stat-card__link">View projects →</Link>
          </div>
        </div>
      )}

      {!loading && pubs.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Project</th>
                <th>Status</th>
                <th>Published</th>
              </tr>
            </thead>
            <tbody>
              {pubs.map((p, i) => (
                <tr key={p.id ?? i}>
                  <td>
                    {p.project_uuid
                      ? <Link to={`/video/projects/${p.project_uuid}`}>{p.project_title ?? p.project_uuid}</Link>
                      : '—'}
                  </td>
                  <td><span className="badge badge--neutral">{p.status}</span></td>
                  <td>{p.completed_at ? formatDate(p.completed_at) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {total > perPage && (
        <div className="pagination mt-4">
          <button type="button" disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="btn btn--sm btn--secondary">Previous</button>
          <span className="pagination__info">Page {page} of {Math.ceil(total / perPage)}</span>
          <button type="button" disabled={page * perPage >= total} onClick={() => setPage(p => p + 1)} className="btn btn--sm btn--secondary">Next</button>
        </div>
      )}
    </div>
  );
}
