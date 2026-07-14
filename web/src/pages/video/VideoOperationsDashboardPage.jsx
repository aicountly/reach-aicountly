import { useState, useEffect } from 'react';
import api from '../../services/api';

export default function VideoOperationsDashboardPage() {
  const [ops, setOps]         = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  useEffect(() => {
    api.get('/video/operations')
      .then(r => setOps(r.data?.data ?? {}))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div>
      <div className="page-header">
        <h1>Video Operations</h1>
        <p className="page-header__subtitle">System health and operational status for the video automation pipeline</p>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading operations data…</p>}

      {!loading && (
        <div className="operations-panels">
          <div className="card mb-3">
            <h3 className="card__title">Render pipeline</h3>
            <dl className="definition-list">
              <dt>Provider</dt>
              <dd>{ops?.render_provider ?? 'mock (CI default)'}</dd>
              <dt>Status</dt>
              <dd><span className="badge badge--success">operational</span></dd>
            </dl>
          </div>

          <div className="card mb-3">
            <h3 className="card__title">YouTube publishing</h3>
            <dl className="definition-list">
              <dt>Publisher</dt>
              <dd>{ops?.youtube_publisher ?? 'mock (CI default)'}</dd>
              <dt>Status</dt>
              <dd><span className="badge badge--neutral">not configured</span></dd>
            </dl>
          </div>

          <div className="card">
            <h3 className="card__title">Job queue</h3>
            <p className="text-muted">Job queue metrics are available in the global Job Monitor.</p>
          </div>
        </div>
      )}
    </div>
  );
}
