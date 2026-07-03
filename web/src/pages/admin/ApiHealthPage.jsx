import { useEffect, useState } from 'react';
import { RefreshCcw } from 'lucide-react';
import { adminService } from '../../services/adminService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { StatusBadge } from '../../components/common/StatusBadge';

function Row({ name, ok, hint }) {
  return (
    <div className="flex items-center justify-between" style={{ padding: '0.5rem 0', borderBottom: '1px solid var(--color-border)' }}>
      <div>
        <div className="text-sm font-semibold" style={{ textTransform: 'uppercase' }}>{name.replace(/_/g,' ')}</div>
        {hint && <div className="text-xs text-muted">{hint}</div>}
      </div>
      <StatusBadge status={ok ? 'ok' : 'not_configured'} />
    </div>
  );
}

const CORE_KEYS = ['jwt_secret', 'database'];
const HINTS = {
  jwt_secret:               'Must be at least 32 chars in api/.env.',
  database:                 'PostgreSQL connection using DB_* env vars.',
  console_configured:       'CONSOLE_API_BASE_URL + CONSOLE_API_TOKEN.',
  engage_configured:        'ENGAGE_API_BASE_URL + ENGAGE_INBOUND_TOKEN.',
  worker_configured:        'WORKER_BASE_URL + WORKER_API_TOKEN.',
  site_publisher_configured:'AICOUNTLY_SITE_API_BASE_URL + AICOUNTLY_SITE_API_TOKEN.',
};

export function ApiHealthPage() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);

  const load = () => {
    setLoading(true);
    adminService.health()
      .then(setData)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>API health</h1>
          <p className="text-sm text-muted">Core services + configured integration providers.</p>
        </div>
        <button className="btn btn-secondary" onClick={load} disabled={loading}><RefreshCcw size={14}/> Refresh</button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading || !data ? <Loader /> : (
        <>
          <div className="mb-4">
            <span className="badge badge-secondary" style={{ marginRight: 6 }}>bot mode: {data.bot_mode || 'unknown'}</span>
            <StatusBadge status={data.status || (data.ok ? 'ready' : 'error')} />
            <span className="text-xs text-muted" style={{ marginLeft: 6 }}>Checked: {data.timestamp}</span>
          </div>
          <div className="grid grid-2">
            <Card title="Core">
              {CORE_KEYS.map((k) => (
                <Row key={k} name={k} ok={!!data.checks?.[k]} hint={HINTS[k]} />
              ))}
            </Card>
            <Card title="Integrations">
              {Object.entries(data.checks || {}).filter(([k]) => !CORE_KEYS.includes(k)).map(([k, ok]) => (
                <Row key={k} name={k} ok={!!ok} hint={HINTS[k]} />
              ))}
            </Card>
          </div>
        </>
      )}
    </div>
  );
}
