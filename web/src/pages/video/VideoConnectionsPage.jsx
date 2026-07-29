import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';
import { normalizeVideoList } from './videoListUtils';

function ConnectionCard({ connection, onRevoke, onHealthCheck }) {
  const [health, setHealth] = useState(null);
  const [checking, setChecking] = useState(false);

  const checkHealth = async () => {
    setChecking(true);
    const result = await onHealthCheck(connection.uuid);
    setHealth(result);
    setChecking(false);
  };

  return (
    <div className="card">
      <h3 className="card__title">{connection.name ?? 'YouTube Connection'}</h3>
      <div className="card__body">
        <dl className="definition-list" style={{ margin: 0 }}>
          <dt>Type</dt>
          <dd>{connection.connection_type}</dd>
          <dt>Auth</dt>
          <dd>{connection.authentication_type}</dd>
          <dt>Created</dt>
          <dd>{connection.created_at ? new Date(connection.created_at).toLocaleDateString() : '—'}</dd>
        </dl>
        <div className="btn-group mt-3">
          <button className="btn btn--sm btn--outline" onClick={checkHealth} disabled={checking}>
            {checking ? 'Checking…' : 'Check health'}
          </button>
          <button className="btn btn--sm btn--error" onClick={() => onRevoke(connection.uuid)}>
            Revoke
          </button>
        </div>
        {health && (
          <div className={`alert mt-2 ${health.healthy ? 'alert--success' : 'alert--error'}`}>
            {health.healthy ? 'Connection healthy' : `Unhealthy: ${health.detail?.error ?? 'unknown'}`}
          </div>
        )}
      </div>
    </div>
  );
}

export default function VideoConnectionsPage() {
  const [connections, setConnections] = useState([]);
  const [loading, setLoading]         = useState(true);
  const [error, setError]             = useState(null);
  const [showAdd, setShowAdd]         = useState(false);
  const [newName, setNewName]         = useState('');

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get('v1/video/connections')
      .then((r) => setConnections(normalizeVideoList(r)))
      .catch((e) => setError(e?.message || 'Failed to load connections.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const handleAdd = async () => {
    try {
      await api.post('v1/video/connections', { name: newName });
      setShowAdd(false);
      setNewName('');
      load();
    } catch (e) {
      alert(e.message);
    }
  };

  const handleRevoke = async (uuid) => {
    if (!confirm('Revoke this YouTube connection?')) return;
    try {
      await api.delete(`v1/video/connections/${uuid}`);
      load();
    } catch (e) {
      alert(e.message);
    }
  };

  const handleHealthCheck = async (uuid) => {
    try {
      const res = await api.get(`v1/video/connections/${uuid}/health`);
      return res ?? { healthy: false };
    } catch {
      return { healthy: false, detail: { error: 'Request failed' } };
    }
  };

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>YouTube Connections</h1>
          <p className="page-header__subtitle">Manage OAuth2 connections for video publication</p>
        </div>
        <div className="page-header__actions">
          <button className="btn btn--primary" onClick={() => setShowAdd(s => !s)}>
            {showAdd ? 'Cancel' : '+ Add connection'}
          </button>
        </div>
      </div>

      {showAdd && (
        <div className="card mb-4" style={{ maxWidth: 480 }}>
          <h3 className="card__title">Add YouTube connection</h3>
          <div className="card__body">
            <p className="text-muted" style={{ margin: '0 0 0.75rem', padding: 0 }}>
              Enter a name for this connection. In production, OAuth2 credentials must be configured server-side.
            </p>
            <label className="form-label">
              Connection name
              <input
                className="form-input"
                placeholder="e.g. Primary YouTube channel"
                value={newName}
                onChange={e => setNewName(e.target.value)}
              />
            </label>
            <div className="form-actions mt-3">
              <button className="btn btn--primary" onClick={handleAdd} disabled={!newName.trim()}>
                Save connection
              </button>
            </div>
          </div>
        </div>
      )}

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading connections…</p>}

      {!loading && connections.length === 0 && !showAdd && (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>
              No YouTube connections configured. Add one to enable video publication.
            </p>
          </div>
        </div>
      )}

      {!loading && connections.length > 0 && (
        <div className="cards-grid">
          {connections.map((conn) => (
            <ConnectionCard
              key={conn.uuid ?? conn.id}
              connection={conn}
              onRevoke={handleRevoke}
              onHealthCheck={handleHealthCheck}
            />
          ))}
        </div>
      )}
    </div>
  );
}
