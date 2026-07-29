import React, { useState, useEffect } from 'react';
import { getAiHealth } from '../../services/aiService.js';

export default function AiHealthPage() {
  const [health, setHealth] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = () => {
    setLoading(true);
    getAiHealth()
      .then(setHealth)
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  if (loading) return <p className="muted">Checking AI health…</p>;
  if (error)   return <p className="text-error">Error: {error}</p>;

  const providers = health?.providers || [];
  const overall = health?.overall_status || 'unknown';
  const overallClass = overall === 'healthy'
    ? 'alert alert--success'
    : overall === 'degraded'
      ? 'alert alert--warning'
      : 'alert alert--danger';

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>AI Health</h1>
          <p className="page-header__subtitle">Live provider connectivity and circuit status.</p>
        </div>
        <button type="button" className="btn btn--sm btn--secondary" onClick={load}>
          Refresh
        </button>
      </div>

      <div className={`${overallClass} mb-4`}>
        System Status: {overall}
      </div>

      {providers.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>No providers reported health status.</p>
          </div>
        </div>
      ) : (
        <div className="operations-panels">
          {providers.map(p => (
            <div key={p.provider_key} className="card">
              <div className="card__body">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-semibold">{p.provider_key}</span>
                  <div className="btn-group">
                    <span className={`badge ${p.healthy ? 'badge--success' : 'badge--danger'}`}>
                      {p.healthy ? 'Healthy' : 'Unhealthy'}
                    </span>
                    {p.circuit_open && (
                      <span className="badge badge--danger">Circuit Open</span>
                    )}
                  </div>
                </div>
                {p.response_time_ms !== undefined && p.response_time_ms !== null && (
                  <p className="muted text-xs mt-1">Response: {p.response_time_ms}ms</p>
                )}
                {p.error_message && (
                  <p className="text-error text-xs mt-1">{p.error_message}</p>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
