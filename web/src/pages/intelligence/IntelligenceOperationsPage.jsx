import { useEffect, useState } from 'react';
import { Activity, RefreshCw, CheckCircle, AlertCircle, XCircle, Settings } from 'lucide-react';
import { listConnectors } from '../../services/intelligenceService.js';
import { formatDateTime } from '../../utils/formatDate';

const StatusIcon = ({ status }) => {
  if (status === 'healthy' || status === 'completed' || status === 'ok') {
    return <CheckCircle size={16} style={{ color: 'var(--color-success)' }} />;
  }
  if (status === 'failed' || status === 'unhealthy') {
    return <XCircle size={16} style={{ color: 'var(--color-danger)' }} />;
  }
  return <AlertCircle size={16} style={{ color: 'var(--color-warning)' }} />;
};

export default function IntelligenceOperationsPage() {
  const [connectors, setConnectors] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = () => {
    setLoading(true);
    setError(null);
    listConnectors()
      .then((data) => {
        const rows = Array.isArray(data) ? data : (data?.connectors || data?.data || []);
        setConnectors(rows);
      })
      .catch((e) => setError(e.message || 'Failed to load operations data'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const healthy = connectors.filter((c) => ['healthy', 'ok'].includes((c.health_status || c.status || '').toLowerCase())).length;
  const failed = connectors.filter((c) => ['failed', 'unhealthy'].includes((c.health_status || c.status || '').toLowerCase())).length;

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap', marginBottom: '1.25rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
          <Activity size={26} />
          <div>
            <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Intelligence Operations</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              Connector health and operational monitoring
            </p>
          </div>
        </div>
        <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={loading}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}

      <div className="grid grid-3" style={{ marginBottom: '1.25rem' }}>
        {[
          { label: 'Connectors', value: loading ? '…' : String(connectors.length), icon: Settings },
          { label: 'Healthy', value: loading ? '…' : String(healthy), icon: CheckCircle },
          { label: 'Unhealthy', value: loading ? '…' : String(failed), icon: XCircle },
        ].map((s) => (
          <div key={s.label} className="stat-tile" style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <s.icon size={22} style={{ color: 'var(--color-primary)', flexShrink: 0 }} />
            <div>
              <div className="stat-tile__value">{s.value}</div>
              <div className="stat-tile__label">{s.label}</div>
            </div>
          </div>
        ))}
      </div>

      <div className="card">
        <div className="card-header">Connector Status</div>
        <div className="card-body" style={{ padding: 0 }}>
          {!loading && connectors.length === 0 ? (
            <p className="text-sm text-muted" style={{ margin: 0, padding: '1.25rem', textAlign: 'center' }}>
              No connectors configured. Job history will appear here once intelligence jobs are running against live connectors.
            </p>
          ) : (
            <div className="table-wrap" style={{ border: 'none', borderRadius: 0 }}>
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Connector</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Last Checked</th>
                  </tr>
                </thead>
                <tbody>
                  {connectors.map((c) => {
                    const status = (c.health_status || c.status || 'unknown').toLowerCase();
                    return (
                      <tr key={c.id || c.connector_key}>
                        <td style={{ fontWeight: 600 }}>{c.name || c.connector_key || `Connector #${c.id}`}</td>
                        <td style={{ fontFamily: 'monospace', fontSize: '0.75rem' }}>{c.provider || c.type || '—'}</td>
                        <td>
                          <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                            <StatusIcon status={status} />
                            <span className="text-sm" style={{ textTransform: 'capitalize' }}>{status}</span>
                          </div>
                        </td>
                        <td className="text-xs text-muted">
                          {c.last_checked_at || c.updated_at
                            ? formatDateTime(c.last_checked_at || c.updated_at)
                            : '—'}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
