import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { ROUTES } from '../../constants/routes';

/** Pipeline health exactly as the API reports it — nothing shows "operational" unless it is. */
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

  const ready = ops?.status === 'ready';

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Video Operations</h1>
        <p className="page-header__subtitle">What the video pipeline can actually do right now</p>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading operations data…</p>}

      {!loading && ops && (
        <div className="operations-panels">
          <div className="card">
            <h3 className="card__title">Pipeline</h3>
            <div className="card__body">
              <dl className="definition-list" style={{ margin: 0 }}>
                <dt>Status</dt>
                <dd><span className={`badge ${ready ? 'badge--success' : 'badge--warning'}`}>{ready ? 'ready' : 'not ready'}</span></dd>
                {!ready && ops.missing?.length > 0 && (<><dt>Missing</dt><dd>{ops.missing.join(', ').replace(/_/g, ' ')}</dd></>)}
                {ops.mock_providers && (<><dt>Providers</dt><dd className="text-error">development mocks (refuse in production)</dd></>)}
              </dl>
            </div>
          </div>

          <div className="card">
            <h3 className="card__title">Render</h3>
            <div className="card__body">
              <dl className="definition-list" style={{ margin: 0 }}>
                <dt>Provider</dt>
                <dd>{ops.render_provider}</dd>
                <dt>API key</dt>
                <dd>{ops.checks?.rendering ? 'set' : 'not set'}</dd>
              </dl>
            </div>
          </div>

          <div className="card">
            <h3 className="card__title">YouTube publishing</h3>
            <div className="card__body">
              <dl className="definition-list" style={{ margin: 0 }}>
                <dt>Channel</dt>
                <dd>{ops.youtube?.connected ? (ops.youtube.channel_title || 'connected') : 'not connected'}</dd>
                <dt>Privacy</dt>
                <dd>{ops.youtube?.privacy}</dd>
              </dl>
              <Link to="/video" className="stat-card__link">Connect or manage →</Link>
            </div>
          </div>

          <div className="card">
            <h3 className="card__title">Job queue</h3>
            <div className="card__body">
              <p className="text-muted" style={{ margin: 0, padding: 0 }}>
                Pipeline steps run as <code>reach.video_pipeline_step</code> jobs on the <code>video</code> queue.
              </p>
              <Link to={ROUTES.JOBS} className="stat-card__link">Open Job Monitor →</Link>
            </div>
          </div>

          {(ops.notes || []).map((n) => <p key={n} className="muted">{n}</p>)}
        </div>
      )}
    </div>
  );
}
