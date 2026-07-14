import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

export default function VideoPublicationListPage() {
  const [pubs, setPubs]       = useState([]);
  const [total, setTotal]     = useState(0);
  const [page, setPage]       = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    api.get(`/video/publications?page=${page}&per_page=${perPage}`)
      .then(r => {
        setPubs(r.data?.data?.data ?? []);
        setTotal(r.data?.data?.total ?? 0);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page]);

  useEffect(() => { load(); }, [load]);

  return (
    <div>
      <div className="page-header">
        <h1>Video Publications</h1>
        <p className="page-header__subtitle">YouTube publication history</p>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading publications…</p>}

      {!loading && pubs.length === 0 && (
        <p className="muted">No publications yet. Render and publish a video project to see it here.</p>
      )}

      {!loading && pubs.length > 0 && (
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
                <td>{p.completed_at ? new Date(p.completed_at).toLocaleDateString() : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {total > perPage && (
        <div className="pagination mt-4">
          <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="btn btn--sm">Previous</button>
          <span className="mx-2">Page {page} of {Math.ceil(total / perPage)}</span>
          <button disabled={page * perPage >= total} onClick={() => setPage(p => p + 1)} className="btn btn--sm">Next</button>
        </div>
      )}
    </div>
  );
}
