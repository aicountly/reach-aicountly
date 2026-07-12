import { useCallback, useEffect, useState } from 'react';
import { RefreshCw, RotateCcw, XCircle } from 'lucide-react';
import { jobService } from '../../services/jobService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { FilterBar } from '../../components/common/FilterBar';
import { Pagination } from '../../components/common/Pagination';
import { usePermission } from '../../hooks/usePermission';

const STATUS_OPTIONS = ['', 'pending', 'processing', 'completed', 'failed', 'dead_letter', 'cancelled'];

function fmt(dt) { return dt ? new Date(dt).toLocaleString() : '—'; }

export function JobMonitorPage() {
  const [rows, setRows]   = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage]   = useState(1);
  const [limit]           = useState(25);
  const [status, setStatus] = useState('');
  const [queue, setQueue]   = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [notice, setNotice]   = useState(null);
  const { has } = usePermission();
  const canRetry  = has('job.retry');
  const canCancel = has('job.cancel');

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    jobService.list({ page, limit, status, queue })
      .then((d) => { setRows(d.items || []); setTotal(d.total ?? 0); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, status, queue]);
  useEffect(load, [load]);

  const retryJob = async (id) => {
    setNotice(null);
    try { await jobService.retry(id); setNotice(`Job #${id} re-enqueued.`); load(); }
    catch (e) { setError(e.message); }
  };
  const cancelJob = async (id) => {
    setNotice(null);
    const reason = window.prompt('Cancel reason (optional):') || '';
    try { await jobService.cancel(id, reason); setNotice(`Job #${id} cancelled.`); load(); }
    catch (e) { setError(e.message); }
  };

  const columns = [
    { key: 'id', label: '#' },
    { key: 'job_type', label: 'Type', render: (r) => <code style={{ fontSize: '0.75rem' }}>{r.job_type}</code> },
    { key: 'queue', label: 'Queue' },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'attempts', label: 'Attempts', render: (r) => `${r.attempts} / ${r.max_attempts}` },
    { key: 'progress', label: 'Progress', render: (r) => r.progress != null ? `${r.progress}%` : '—' },
    { key: 'enqueued_actor_type', label: 'Actor', render: (r) => r.enqueued_actor_type || '—' },
    { key: 'scheduled_at', label: 'Scheduled', render: (r) => fmt(r.scheduled_at || r.available_at) },
    { key: 'started_at', label: 'Started', render: (r) => fmt(r.started_at) },
    { key: 'completed_at', label: 'Completed', render: (r) => fmt(r.completed_at) },
    { key: 'error', label: 'Error', render: (r) => r.error_message ? (
      <span className="text-danger text-xs" title={r.error_message}>
        {r.error_message.length > 60 ? r.error_message.slice(0, 60) + '…' : r.error_message}
      </span>
    ) : '—' },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-2">
        {canRetry && ['failed', 'dead_letter', 'cancelled'].includes(r.status) && (
          <button className="btn btn-secondary btn-sm" onClick={() => retryJob(r.id)}>
            <RotateCcw size={12} /> Retry
          </button>
        )}
        {canCancel && ['pending', 'processing', 'failed'].includes(r.status) && (
          <button className="btn btn-danger btn-sm" onClick={() => cancelJob(r.id)}>
            <XCircle size={12} /> Cancel
          </button>
        )}
      </div>
    ) },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Job monitor</h1>
          <p className="text-sm text-muted">
            Reach async job queue (Postgres). Reserve → process → complete / retry / dead-letter.
          </p>
        </div>
        <button className="btn btn-secondary" onClick={load}><RefreshCw size={13}/> Refresh</button>
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => { setPage(1); setStatus(e.target.value); }}>
          {STATUS_OPTIONS.map((s) => (
            <option key={s} value={s}>{s ? s.replace(/_/g, ' ') : 'All statuses'}</option>
          ))}
        </select>
        <input
          value={queue}
          onChange={(e) => setQueue(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { setPage(1); load(); } }}
          placeholder="Queue name (e.g. default, marketing_bot)"
          style={{ minWidth: 220 }}
        />
      </FilterBar>

      {error &&  <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No jobs match the current filter." />
        </Card>
      )}
      <Pagination page={page} limit={limit} total={total} onPage={setPage} />
    </div>
  );
}
