import { useEffect, useState } from 'react';
import { RefreshCcw } from 'lucide-react';
import { adminService } from '../../services/adminService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';

export function ConsoleSyncPage() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);

  const load = () => {
    setLoading(true);
    adminService.consoleSync()
      .then(setData)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  const columns = [
    { key: 'attempted_at', label: 'When', render: (r) => r.attempted_at ? new Date(r.attempted_at).toLocaleString() : '—' },
    { key: 'event_type', label: 'Event' },
    { key: 'response_status', label: 'HTTP' },
    { key: 'ok', label: 'Status', render: (r) => <StatusBadge status={r.ok ? 'ok' : 'failed'} /> },
    { key: 'error_message', label: 'Error', render: (r) => r.error_message ? <span className="text-danger text-xs">{r.error_message}</span> : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Console sync status</h1>
          <p className="text-sm text-muted">Audit + bot report + lead events fanned out to Console.</p>
        </div>
        <button className="btn btn-secondary" onClick={load} disabled={loading}><RefreshCcw size={14}/> Refresh</button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading || !data ? <Loader /> : (
        <>
          <div className="grid grid-4 mb-4">
            <div className="stat-tile"><div className="stat-tile__label">Configured</div><div className="mt-1"><StatusBadge status={data.configured ? 'ready' : 'not_configured'} /></div></div>
            <div className="stat-tile"><div className="stat-tile__label">Ok (last hour)</div><div className="stat-tile__value">{data.ok_last_hour ?? 0}</div></div>
            <div className="stat-tile"><div className="stat-tile__label">Errors (last hour)</div><div className="stat-tile__value">{data.errors_last_hour ?? 0}</div></div>
            <div className="stat-tile"><div className="stat-tile__label">Last ok</div><div className="text-sm">{data.last_ok_at ? new Date(data.last_ok_at).toLocaleString() : '—'}</div></div>
          </div>
          <Card title="Recent events" padding={false}>
            <DataTable columns={columns} rows={data.recent_events || []} emptyMessage="No sync attempts recorded." />
          </Card>
        </>
      )}
    </div>
  );
}
