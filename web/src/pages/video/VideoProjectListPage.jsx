import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

function ProjectStatusBadge({ status }) {
  const colorMap = {
    draft:              'badge--neutral',
    script_draft:       'badge--info',
    script_in_review:   'badge--warning',
    script_approved:    'badge--success',
    render_queued:      'badge--info',
    rendering:          'badge--info',
    rendered:           'badge--success',
    publish_queued:     'badge--info',
    publishing:         'badge--info',
    published:          'badge--success',
    generation_failed:  'badge--error',
    render_failed:      'badge--error',
    publish_failed:     'badge--error',
    cancelled:          'badge--muted',
    withdrawn:          'badge--muted',
  };
  return (
    <span className={`badge ${colorMap[status] ?? 'badge--neutral'}`}>
      {status.replace(/_/g, ' ')}
    </span>
  );
}

export default function VideoProjectListPage() {
  const [projects, setProjects] = useState([]);
  const [total, setTotal]       = useState(0);
  const [page, setPage]         = useState(1);
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);
  const [filter, setFilter]     = useState('');
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams({ page, per_page: perPage });
    if (filter) params.set('status', filter);
    api.get(`/video/projects?${params}`)
      .then(r => {
        setProjects(r.data?.data?.data ?? []);
        setTotal(r.data?.data?.total ?? 0);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, filter]);

  useEffect(() => { load(); }, [load]);

  return (
    <div>
      <div className="page-header">
        <h1>Video Projects</h1>
        <p className="page-header__subtitle">Manage video production from script to publication</p>
        <div className="page-header__actions">
          <Link to="/video/ideas" className="btn btn--outline">View ideas</Link>
        </div>
      </div>

      <div className="toolbar mb-4">
        <select
          value={filter}
          onChange={e => { setFilter(e.target.value); setPage(1); }}
          className="select"
          aria-label="Filter by status"
        >
          <option value="">All statuses</option>
          {['draft','script_draft','script_in_review','script_approved','rendering','rendered','published','cancelled'].map(s => (
            <option key={s} value={s}>{s.replace(/_/g,' ')}</option>
          ))}
        </select>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading projects…</p>}

      {!loading && projects.length === 0 && (
        <p className="muted">No video projects found. Convert an accepted idea to get started.</p>
      )}

      {!loading && projects.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Status</th>
              <th>Updated</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {projects.map(p => (
              <tr key={p.uuid}>
                <td>
                  <Link to={`/video/projects/${p.uuid}`}>{p.title}</Link>
                  {p.idea_title && <span className="text-muted ml-2 text-sm">from: {p.idea_title}</span>}
                </td>
                <td><ProjectStatusBadge status={p.status} /></td>
                <td>{p.updated_at ? new Date(p.updated_at).toLocaleDateString() : '—'}</td>
                <td>
                  <Link to={`/video/projects/${p.uuid}`} className="btn btn--sm">Open →</Link>
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
