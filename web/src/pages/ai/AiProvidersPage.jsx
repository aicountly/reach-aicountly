import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { listAiProviders } from '../../services/aiService.js';

const STATUS_BADGE = {
  enabled:   'badge-success',
  disabled:  'badge-secondary',
  draft:     'badge-warning',
  unhealthy: 'badge-danger',
  deprecated:'badge-warning',
};

export default function AiProvidersPage() {
  const [providers, setProviders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    listAiProviders()
      .then((d) => setProviders(d.providers || []))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="text-sm text-muted">Loading providers…</p>;
  if (error) {
    return (
      <div>
        <h1 style={{ fontSize: '1.2rem', fontWeight: 700, marginBottom: '0.75rem' }}>AI Providers</h1>
        <div className="alert alert-danger">Error: {error}</div>
      </div>
    );
  }

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.85rem' }}>
        <h1 style={{ fontSize: '1.2rem', fontWeight: 700, margin: 0 }}>AI Providers</h1>
      </div>
      <div className="alert alert-warning">
        Provider API keys are never stored in the database. Configure keys via environment variables only.
      </div>

      {providers.length === 0 ? (
        <div className="card">
          <div className="card-body" style={{ textAlign: 'center' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No providers configured in the database yet.
            </p>
          </div>
        </div>
      ) : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Key</th>
                <th>Display Name</th>
                <th>Status</th>
                <th>Config</th>
                <th>Health</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {providers.map((p) => (
                <tr key={p.id}>
                  <td style={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{p.provider_key}</td>
                  <td>{p.display_name}</td>
                  <td>
                    <span className={`badge ${STATUS_BADGE[p.status] || 'badge-secondary'}`}>
                      {p.status}
                    </span>
                  </td>
                  <td>
                    <span className={`badge ${p.configuration_status === 'configured' ? 'badge-success' : 'badge-danger'}`}>
                      {p.configuration_status}
                    </span>
                  </td>
                  <td className="text-xs text-muted">{p.last_health_status || '—'}</td>
                  <td>
                    <Link to={`/ai/providers/${p.id}`} className="text-sm">View</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
