import { useCallback, useEffect, useState } from 'react';
import { leadsService } from '../../services/leadsService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { FilterBar } from '../../components/common/FilterBar';

const STATES = ['','pending_push','pushed','failed','duplicate','rejected','retry_scheduled'];

export function EngagePushPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [status, setStatus] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    leadsService.pushHistory({ limit: 100, status })
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [status]);
  useEffect(() => { load(); }, [load]);

  const retry = async (row) => {
    setBusyId(row.id);
    try { await leadsService.retry(row.id); load(); }
    catch (e) { setError(e.message); }
    finally { setBusyId(null); }
  };

  const columns = [
    { key: 'id', label: 'Lead', render: (r) => (
      <div>
        <div className="font-semibold">#{r.id} · {r.name || '—'}</div>
        <div className="text-xs text-muted">{r.email || r.mobile || ''}</div>
      </div>
    )},
    { key: 'engage_push_status', label: 'Status', render: (r) => <StatusBadge status={r.engage_push_status} /> },
    { key: 'engage_lead_code',  label: 'Engage code', render: (r) => r.engage_lead_code || '—' },
    { key: 'engage_push_attempts', label: 'Attempts', render: (r) => r.engage_push_attempts ?? 0 },
    { key: 'last_push_at', label: 'Last push', render: (r) => r.last_push_at ? new Date(r.last_push_at).toLocaleString() : '—' },
    { key: 'last_push_error', label: 'Error', render: (r) => r.last_push_error ? <span className="text-danger text-xs" style={{ overflowWrap: 'anywhere' }}>{r.last_push_error}</span> : '—' },
    { key: 'actions', label: '', render: (r) => (
      ['pending_push','failed','retry_scheduled'].includes(r.engage_push_status)
        ? <button className="btn btn-secondary btn-sm" disabled={busyId === r.id} onClick={() => retry(r)}>Push now</button>
        : null
    )},
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Engage lead push</h1>
          <p className="text-sm text-muted">Leads with their most recent Engage push status.</p>
        </div>
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => setStatus(e.target.value)}>
          {STATES.map((s) => <option key={s} value={s}>{s ? s.replace(/_/g,' ') : 'All statuses'}</option>)}
        </select>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No leads found for this filter." />
        </Card>
      )}
    </div>
  );
}
