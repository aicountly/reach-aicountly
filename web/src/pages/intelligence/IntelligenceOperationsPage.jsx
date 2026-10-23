import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useUrlFilter } from '../../components/drill';
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

  const healthOf = (c) => {
    const st = (c.health_status || c.status || '').toLowerCase();
    if (['healthy', 'ok'].includes(st)) return 'healthy';
    if (['failed', 'unhealthy'].includes(st)) return 'unhealthy';
    return 'other';
  };
  const healthy = connectors.filter((c) => healthOf(c) === 'healthy').length;
  const failed = connectors.filter((c) => healthOf(c) === 'unhealthy').length;
  // The Healthy / Unhealthy tiles narrow the table below (?health=), using
  // the same test that counted them.
  const [health, setHealth] = useUrlFilter('health');
  const shown = health ? connectors.filter((c) => healthOf(c) === health) : connectors;

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
          { label: 'Connectors', value: loading ? '…' : String(connectors.length), icon: Settings, count: connectors.length, filter: '' },
          { label: 'Healthy', value: loading ? '…' : String(healthy), icon: CheckCircle, count: healthy, filter: 'healthy' },
          { label: 'Unhealthy', value: loading ? '…' : String(failed), icon: XCircle, count: failed, filter: 'unhealthy' },
        ].map((s) => {
          const style = { display: 'flex', alignItems: 'center', gap: '0.75rem' };
          const body = (
            <>
              <s.icon size={22} style={{ color: 'var(--color-primary)', flexShrink: 0 }} />
              <div>
                <div className="stat-tile__value">{s.value}</div>
                <div className="stat-tile__label">{s.label}</div>
              </div>
            </>
          );
          const active = health === s.filter;
          return !loading && s.count > 0 && !active ? (
            <button key={s.label} type="button" className="stat-tile" style={style} onClick={() => setHealth(s.filter)}
                    aria-label={`${s.label}: ${s.value} — show in table`}>
              {body}
            </button>
          ) : (
            <div key={s.label} className="stat-tile" style={style}>{body}</div>
          );
        })}
      </div>

      {health && (
        <div className="flex items-center gap-2 mb-2">
          <button type="button" className="badge badge-secondary" onClick={() => setHealth('')}
                  aria-label={`Clear ${health} filter`}>
            {health} ✕
          </button>
          <span className="text-xs text-muted">{shown.length} of {connectors.length} connectors</span>
        </div>
      )}

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
                  {shown.map((c) => {
                    const status = (c.health_status || c.status || 'unknown').toLowerCase();
                    return (
                      <tr key={c.id || c.connector_key}>
                        <td style={{ fontWeight: 600 }}>
                          <Link to="/intelligence/connectors" className="drill-link" title="Configure in Connectors">
                            {c.name || c.connector_key || `Connector #${c.id}`}
                          </Link>
                        </td>
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
