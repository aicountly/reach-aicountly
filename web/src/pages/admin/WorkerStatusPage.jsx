import { useEffect, useState } from 'react';
import { RefreshCcw, Zap } from 'lucide-react';
import { adminService } from '../../services/adminService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';

export function WorkerStatusPage() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [pinging, setPinging] = useState(false);

  const load = () => {
    setLoading(true);
    adminService.workerStatus()
      .then(setData)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  const ping = async () => {
    setPinging(true);
    try { await adminService.pingWorker(); load(); }
    catch (e) { setError(e.message); }
    finally { setPinging(false); }
  };

  const columns = [
    { key: 'checked_at', label: 'When', render: (r) => r.checked_at ? new Date(r.checked_at).toLocaleString() : '—' },
    { key: 'ok', label: 'Status', render: (r) => <StatusBadge status={r.ok ? 'ok' : 'failed'} /> },
    { key: 'http_status', label: 'HTTP' },
    { key: 'latency_ms', label: 'Latency (ms)', render: (r) => r.latency_ms ?? '—' },
    { key: 'error_message', label: 'Error', render: (r) => r.error_message ? <span className="text-danger text-xs">{r.error_message}</span> : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Playwright worker status</h1>
          <p className="text-sm text-muted">worker.apis.aicountly.com — Playwright UI/screenshot/review jobs only.</p>
        </div>
        <div className="flex gap-2">
          <button className="btn btn-secondary" onClick={load} disabled={loading}><RefreshCcw size={14}/> Refresh</button>
          <button className="btn btn-primary" onClick={ping} disabled={pinging}><Zap size={14}/> {pinging ? 'Pinging…' : 'Ping worker'}</button>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading || !data ? <Loader /> : (
        <>
          <div className="grid grid-4 mb-4">
            <div className="stat-tile"><div className="stat-tile__label">Configured</div><div className="mt-1"><StatusBadge status={data.configured ? 'ready' : 'not_configured'} /></div></div>
            <div className="stat-tile"><div className="stat-tile__label">Last ok</div><div className="text-sm">{data.last_ok?.checked_at ? new Date(data.last_ok.checked_at).toLocaleString() : '—'}</div></div>
            <div className="stat-tile"><div className="stat-tile__label">Last error</div><div className="text-sm">{data.last_error?.checked_at ? new Date(data.last_error.checked_at).toLocaleString() : '—'}</div></div>
            <div className="stat-tile"><div className="stat-tile__label">Latest latency</div><div className="stat-tile__value">{data.last_ok?.latency_ms ?? '—'}<span className="text-xs text-muted"> ms</span></div></div>
          </div>
          <Card title="Recent snapshots" padding={false}>
            <DataTable columns={columns} rows={data.recent || []} emptyMessage="No worker health snapshots yet." />
          </Card>
        </>
      )}
    </div>
  );
}
