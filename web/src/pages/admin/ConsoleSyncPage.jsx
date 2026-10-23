import { useCallback, useEffect, useState } from 'react';
import { RefreshCcw } from 'lucide-react';
import { adminService } from '../../services/adminService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { useDrillFilters } from '../../components/drill';
import { formatDateTime } from '../../utils/formatDate';

/** ?result=ok|error&since_hours=1 — the hour tiles' predicates (ConsoleSyncStatusController). */
const FILTER_KEYS = ['result', 'since_hours'];
const OK_LAST_HOUR = { result: 'ok', since_hours: '1' };
const ERRORS_LAST_HOUR = { result: 'error', since_hours: '1' };

function CountTile({ label, value, patch, filters, onDrill }) {
  const active = filters.result === patch.result && filters.since_hours === patch.since_hours;
  const body = (
    <>
      <div className="stat-tile__label">{label}</div>
      <div className="stat-tile__value">{value ?? 0}</div>
    </>
  );
  if (!value) return <div className="stat-tile">{body}</div>;
  return (
    <button type="button" className={`stat-tile${active ? ' is-active' : ''}`} aria-pressed={active}
            onClick={() => onDrill(patch)} title="Show these events">
      {body}
    </button>
  );
}

export function ConsoleSyncPage() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const drill = useDrillFilters(FILTER_KEYS);
  const { result, since_hours: sinceHours } = drill.filters;

  const load = useCallback(() => {
    setLoading(true);
    adminService.consoleSync({ result, since_hours: sinceHours })
      .then(setData)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [result, sinceHours]);
  useEffect(load, [load]);

  const columns = [
    { key: 'attempted_at', label: 'When', render: (r) => r.attempted_at ? formatDateTime(r.attempted_at) : '—' },
    { key: 'event_type', label: 'Event' },
    { key: 'response_status', label: 'HTTP' },
    { key: 'ok', label: 'Status', render: (r) => <StatusBadge status={r.ok ? 'ok' : 'failed'} /> },
    { key: 'error_message', label: 'Error', render: (r) => r.error_message ? <span className="text-danger text-xs">{r.error_message}</span> : '—' },
  ];

  const filtered = Boolean(result || sinceHours);
  const shown = (data?.recent_events || []).length;

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
            <CountTile label="Ok (last hour)" value={data.ok_last_hour} patch={OK_LAST_HOUR} filters={drill.filters} onDrill={drill.drill} />
            <CountTile label="Errors (last hour)" value={data.errors_last_hour} patch={ERRORS_LAST_HOUR} filters={drill.filters} onDrill={drill.drill} />
            <div className="stat-tile"><div className="stat-tile__label">Last ok</div><div className="text-sm">{data.last_ok_at ? formatDateTime(data.last_ok_at) : '—'}</div></div>
          </div>
          {filtered && (
            <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
              {result && (
                <button type="button" className="badge badge-secondary" onClick={() => drill.clear('result')}
                        aria-label={`Clear ${result} filter`}>
                  {result === 'ok' ? 'Delivered' : 'Errors'} ✕
                </button>
              )}
              {sinceHours && (
                <button type="button" className="badge badge-secondary" onClick={() => drill.clear('since_hours')}
                        aria-label={`Clear last ${sinceHours} hours filter`}>
                  Last {sinceHours} h ✕
                </button>
              )}
              {data.events_total != null && (
                <span className="text-xs text-muted">
                  {data.events_total > shown ? `latest ${shown} of ${data.events_total}` : `${data.events_total}`} matching event{data.events_total === 1 ? '' : 's'}
                </span>
              )}
            </div>
          )}
          <Card title="Recent events" padding={false}>
            <DataTable columns={columns} rows={data.recent_events || []}
                       emptyMessage={filtered ? 'No sync attempts match this filter.' : 'No sync attempts recorded.'} />
          </Card>
        </>
      )}
    </div>
  );
}
