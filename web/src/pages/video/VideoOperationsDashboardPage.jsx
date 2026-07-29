import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { ROUTES } from '../../constants/routes';

export default function VideoOperationsDashboardPage() {
  const [ops, setOps]         = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  useEffect(() => {
    api.get('v1/video/operations')
      .then(r => setOps(r ?? {}))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Video Operations</h1>
        <p className="page-header__subtitle">System health and operational status for the video automation pipeline</p>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading operations data…</p>}

      {!loading && (
        <div className="operations-panels">
          <div className="card">
            <h3 className="card__title">Render pipeline</h3>
            <div className="card__body">
              <dl className="definition-list" style={{ margin: 0 }}>
                <dt>Provider</dt>
                <dd>{ops?.render_provider ?? 'mock (CI default)'}</dd>
                <dt>Status</dt>
                <dd><span className="badge badge--success">operational</span></dd>
              </dl>
            </div>
          </div>

          <div className="card">
            <h3 className="card__title">YouTube publishing</h3>
            <div className="card__body">
              <dl className="definition-list" style={{ margin: 0 }}>
                <dt>Publisher</dt>
                <dd>{ops?.youtube_publisher ?? 'mock (CI default)'}</dd>
                <dt>Status</dt>
                <dd><span className="badge badge--neutral">not configured</span></dd>
              </dl>
            </div>
          </div>

          <div className="card">
            <h3 className="card__title">Job queue</h3>
            <div className="card__body">
              <p className="text-muted" style={{ margin: 0, padding: 0 }}>
                Job queue metrics are available in the global Job Monitor.
              </p>
              <Link to={ROUTES.JOBS} className="stat-card__link">Open Job Monitor →</Link>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
