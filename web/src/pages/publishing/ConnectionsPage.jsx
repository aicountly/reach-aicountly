import { useState, useEffect } from 'react';
import api from '../../services/api';

export default function ConnectionsPage() {
  const [connections, setConnections] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [healthMsg, setHealthMsg] = useState({});

  useEffect(() => {
    api.get('/publishing/connections')
      .then(r => setConnections(r.data?.data ?? []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  const checkHealth = async (connectionKey) => {
    setHealthMsg(m => ({ ...m, [connectionKey]: 'Checking…' }));
    try {
      const r = await api.post(`/publishing/connections/${connectionKey}/health-check`);
      const status = r.data?.data?.health_status ?? 'unknown';
      setHealthMsg(m => ({ ...m, [connectionKey]: `Health: ${status}` }));
    } catch {
      setHealthMsg(m => ({ ...m, [connectionKey]: 'Health check failed' }));
    }
  };

  if (loading) return <p className="muted">Loading connections…</p>;
  if (error) return <p className="text-error">{error}</p>;

  const healthClass = h => ({
    healthy: 'badge--success',
    degraded: 'badge--warning',
    unhealthy: 'badge--error',
    unknown: 'badge--neutral',
  }[h] ?? 'badge--neutral');

  return (
    <div>
      <div className="page-header">
        <h1>Publication Connections</h1>
        <p className="muted">Service-to-service connections to the public website. Credentials are from environment variables only.</p>
      </div>

      {connections.length === 0 ? (
        <p className="muted">No connections configured.</p>
      ) : (
        <div className="card-grid">
          {connections.map(c => (
            <div key={c.id} className="card">
              <div className="card__header">
                <strong>{c.display_name}</strong>
                <span className={`badge ${c.enabled ? 'badge--success' : 'badge--neutral'}`}>
                  {c.enabled ? 'Enabled' : 'Disabled'}
                </span>
              </div>
              <div className="card__body">
                <p><span className="label">Key:</span> <code>{c.connection_key}</code></p>
                <p><span className="label">API Version:</span> v{c.api_version}</p>
                <p><span className="label">Auth:</span> {c.authentication_type}</p>
                <p>
                  <span className="label">Health:</span>
                  <span className={`badge ${healthClass(c.health_status)}`}>{c.health_status}</span>
                </p>
                {c.last_health_error && (
                  <p className="text-error small">{c.last_health_error}</p>
                )}
              </div>
              <div className="card__footer">
                <button className="btn btn--sm" onClick={() => checkHealth(c.connection_key)}>
                  Check Health
                </button>
                {healthMsg[c.connection_key] && (
                  <span className="muted small">&nbsp;{healthMsg[c.connection_key]}</span>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
