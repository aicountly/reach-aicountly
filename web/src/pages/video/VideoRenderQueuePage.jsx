import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';

function RenderJobStatusBadge({ status }) {
  const map = {
    queued:      'badge--neutral',
    reserved:    'badge--info',
    rendering:   'badge--info',
    rendered:    'badge--success',
    failed:      'badge--error',
    cancelled:   'badge--muted',
    dead_letter: 'badge--error',
  };
  return <span className={`badge ${map[status] ?? 'badge--neutral'}`}>{status?.replace(/_/g, ' ')}</span>;
}

export default function VideoRenderQueuePage() {
  const [jobs, setJobs]       = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    api.get('v1/video/render-jobs', { per_page: 50 })
      .then(r => setJobs(r?.data ?? []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const handleAction = async (uuid, action) => {
    try {
      if (action === 'cancel') {
        await api.delete(`v1/video/render-jobs/${uuid}`);
      } else {
        await api.post(`v1/video/render-jobs/${uuid}/${action}`);
      }
      load();
    } catch (e) {
      alert(e.message);
    }
  };

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Render Queue</h1>
        <p className="page-header__subtitle">AI video render jobs across all projects</p>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading render queue…</p>}

      {!loading && jobs.length === 0 && (
        <p className="muted">No render jobs found. Approve a script and queue a render.</p>
      )}

      {!loading && jobs.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Job UUID</th>
              <th>Provider</th>
              <th>Status</th>
              <th>Attempts</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {jobs.map(job => (
              <tr key={job.uuid}>
                <td className="font-mono text-sm">{job.uuid?.slice(0, 12)}…</td>
                <td>{job.provider ?? '—'}</td>
                <td><RenderJobStatusBadge status={job.status} /></td>
                <td>{job.attempt_count ?? 0}/{job.max_attempts ?? 3}</td>
                <td>
                  {['failed', 'dead_letter'].includes(job.status) && (
                    <button className="btn btn--sm btn--primary" onClick={() => handleAction(job.uuid, 'retry')}>
                      Retry
                    </button>
                  )}
                  {['queued', 'reserved', 'rendering'].includes(job.status) && (
                    <button className="btn btn--sm btn--error ml-2" onClick={() => handleAction(job.uuid, 'cancel')}>
                      Cancel
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
