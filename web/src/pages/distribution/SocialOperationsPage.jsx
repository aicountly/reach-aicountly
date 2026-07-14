import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';

export default function SocialOperationsPage() {
  const [posts, setPosts]     = useState([]);
  const [total, setTotal]     = useState(0);
  const [page, setPage]       = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [filter, setFilter]   = useState('approved');
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    api.get(`/social-posts?status=${filter}&page=${page}&per_page=${perPage}`)
      .then(r => {
        setPosts(r.data?.data?.data ?? r.data?.data ?? []);
        setTotal(r.data?.data?.total ?? 0);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, filter]);

  useEffect(() => { load(); }, [load]);

  const handleDispatch = async (postId) => {
    if (!confirm('Dispatch this post to the social provider?')) return;
    try {
      const r = await api.post(`/distribution/social/dispatch/${postId}`);
      const status = r.data?.data?.status ?? 'dispatched';
      alert(`Dispatch result: ${status}`);
      load();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    }
  };

  const FILTERS = ['all', 'approved', 'posted', 'failed', 'scheduled'];

  return (
    <div>
      <div className="page-header">
        <h1>Social Distribution</h1>
        <p className="page-header__subtitle">Dispatch approved posts to social channels via provider</p>
      </div>

      <div className="filter-bar mb-4">
        {FILTERS.map(f => (
          <button
            key={f}
            className={`btn btn--sm mr-1 ${filter === f ? 'btn--primary' : 'btn--outline'}`}
            onClick={() => { setFilter(f); setPage(1); }}
          >
            {f.charAt(0).toUpperCase() + f.slice(1)}
          </button>
        ))}
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading social posts…</p>}

      {!loading && posts.length === 0 && (
        <p className="muted">No social posts found for the selected filter.</p>
      )}

      {!loading && posts.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Channel</th>
              <th>Content</th>
              <th>Status</th>
              <th>Provider</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {posts.map(p => (
              <tr key={p.id}>
                <td><span className="badge badge--neutral">{p.channel}</span></td>
                <td className="text-truncate" style={{maxWidth:'300px'}}>{p.content?.slice(0, 80)}…</td>
                <td><span className="badge badge--neutral">{p.status}</span></td>
                <td>{p.provider ?? '—'}</td>
                <td>
                  {p.status === 'approved' && (
                    <button className="btn btn--sm btn--primary" onClick={() => handleDispatch(p.id)}>
                      Dispatch
                    </button>
                  )}
                  {p.remote_url && (
                    <a href={p.remote_url} target="_blank" rel="noreferrer" className="btn btn--sm btn--outline ml-1">
                      View
                    </a>
                  )}
                </td>
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
