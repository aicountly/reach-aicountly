import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
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
import { formatDateTime } from '../../utils/formatDate';

const STATUS_OPTIONS = ['', 'pending', 'processing', 'completed', 'skipped', 'dead_letter,failed', 'dead_letter', 'failed', 'cancelled'];

const STATUS_LABELS = { 'dead_letter,failed': 'failed (dead letter)' };

/**
 * Narrowing filters the dashboard deep-links with. The API applies the same
 * predicates the dashboard counted with (JobQueueInsights), so a tile's number
 * and this list agree.
 */
const FLAG_FILTERS = {
  ready:     'Ready to run now',
  scheduled: 'Scheduled for later',
  running:   'Running (live lease)',
  stalled:   'Stalled (lease expired)',
  retrying:  'Retrying after a failure',
};

function fmt(dt) { return dt ? formatDateTime(dt) : '—'; }

export function JobMonitorPage() {
  const [rows, setRows]   = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage]   = useState(1);
  const [limit]           = useState(25);
  // Filters live in the URL, not local state: the dashboard's queue tiles
  // link straight to their own rows (?status=failed), and a filtered view
  // stays shareable and survives the back button.
  const [searchParams, setSearchParams] = useSearchParams();
  const status = searchParams.get('status') ?? '';
  const queue  = searchParams.get('queue') ?? '';
  const jobType = searchParams.get('job_type') ?? '';
  const sinceHours = searchParams.get('since_hours') ?? '';
  // One worker's / one request's jobs (Bot queue rows link with request_id).
  const workerId = searchParams.get('worker_id') ?? '';
  const requestId = searchParams.get('request_id') ?? '';
  const flags = Object.keys(FLAG_FILTERS).filter((k) => searchParams.get(k) === '1');
  const flagKey = flags.join(',');

  const setFilter = (key, value) => {
    const next = new URLSearchParams(searchParams);
    if (value) next.set(key, value); else next.delete(key);
    setSearchParams(next, { replace: true });
    setPage(1);
  };
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [notice, setNotice]   = useState(null);
  const { has } = usePermission();
  const canRetry  = has('job.retry');
  const canCancel = has('job.cancel');

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    const params = { page, limit, status, queue };
    if (jobType) params.job_type = jobType;
    if (sinceHours) params.since_hours = sinceHours;
    if (workerId) params.worker_id = workerId;
    if (requestId) params.request_id = requestId;
    flagKey.split(',').filter(Boolean).forEach((k) => { params[k] = '1'; });
    jobService.list(params)
      .then((d) => { setRows(d.items || []); setTotal(d.total ?? 0); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, status, queue, jobType, sinceHours, workerId, requestId, flagKey]);
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
    // Narrow to this row's type / queue in place (already narrowed: plain text).
    { key: 'job_type', label: 'Type', render: (r) => <code style={{ fontSize: '0.75rem' }}>{r.job_type}</code>,
      drill: (r) => (r.job_type && r.job_type !== jobType ? { onClick: () => setFilter('job_type', r.job_type), title: 'Only jobs of this type' } : null) },
    { key: 'queue', label: 'Queue',
      drill: (r) => (r.queue && r.queue !== queue ? { onClick: () => setFilter('queue', r.queue), title: 'Only jobs on this queue' } : null) },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'attempts', label: 'Attempts', render: (r) => `${r.attempts} / ${r.max_attempts}` },
    { key: 'progress', label: 'Progress', render: (r) => r.progress != null ? `${r.progress}%` : '—' },
    { key: 'enqueued_actor_type', label: 'Actor', render: (r) => r.enqueued_actor_type || '—' },
    { key: 'scheduled_at', label: 'Scheduled', render: (r) => fmt(r.scheduled_at || r.available_at) },
    { key: 'started_at', label: 'Started', render: (r) => fmt(r.started_at) },
    { key: 'completed_at', label: 'Completed', render: (r) => fmt(r.completed_at) },
    { key: 'error', label: 'Error / reason', render: (r) => r.error_message ? (
      <span className={r.status === 'skipped' ? 'text-muted text-xs' : 'text-danger text-xs'} title={r.error_message}>
        {r.error_message.length > 60 ? r.error_message.slice(0, 60) + '…' : r.error_message}
      </span>
    ) : '—' },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-2">
        {canRetry && ['failed', 'dead_letter', 'cancelled', 'skipped'].includes(r.status) && (
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
            Reach async job queue (Postgres). Reserve → process → complete / skip / retry / dead-letter.
            Skipped means the integration is not configured — not a failure.
          </p>
        </div>
        <button className="btn btn-secondary" onClick={load}><RefreshCw size={13}/> Refresh</button>
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => setFilter('status', e.target.value)}>
          {STATUS_OPTIONS.map((s) => (
            <option key={s} value={s}>{s ? (STATUS_LABELS[s] || s.replace(/_/g, ' ')) : 'All statuses'}</option>
          ))}
          {status && !STATUS_OPTIONS.includes(status) && <option value={status}>{status}</option>}
        </select>
        <input
          value={jobType}
          onChange={(e) => setFilter('job_type', e.target.value)}
          placeholder="Job type (e.g. reach.seo_tracker)"
          aria-label="Job type"
          style={{ minWidth: 220 }}
        />
        <input
          value={queue}
          onChange={(e) => setFilter('queue', e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { setPage(1); load(); } }}
          placeholder="Queue name (e.g. default, blog)"
          style={{ minWidth: 220 }}
        />
      </FilterBar>

      {(sinceHours || workerId || requestId || flags.length > 0) && (
        <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
          {sinceHours && (
            <button type="button" className="badge badge-secondary" onClick={() => setFilter('since_hours', '')}
                    aria-label={`Clear last ${sinceHours} hours filter`}>
              Last {sinceHours} h ✕
            </button>
          )}
          {workerId && (
            <button type="button" className="badge badge-secondary" onClick={() => setFilter('worker_id', '')}
                    aria-label="Clear worker filter">
              Worker {workerId} ✕
            </button>
          )}
          {requestId && (
            <button type="button" className="badge badge-secondary" onClick={() => setFilter('request_id', '')}
                    aria-label="Clear request filter">
              Request {requestId} ✕
            </button>
          )}
          {flags.map((k) => (
            <button key={k} type="button" className="badge badge-secondary" onClick={() => setFilter(k, '')}
                    aria-label={`Clear ${FLAG_FILTERS[k]} filter`}>
              {FLAG_FILTERS[k]} ✕
            </button>
          ))}
          <span className="text-xs text-muted">{total} matching job{total === 1 ? '' : 's'}</span>
        </div>
      )}

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
