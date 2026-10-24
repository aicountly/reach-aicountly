import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { RefreshCw, RotateCcw, XCircle } from 'lucide-react';
import { jobService } from '../../services/jobService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { DrillLink, drillHref } from '../../components/drill';
import { useAuth } from '../../context/AuthContext';
import { usePermission } from '../../hooks/usePermission';
import { ROUTES } from '../../constants/routes';
import { formatDateTime } from '../../utils/formatDate';

// Same state rules as JobController::retry / ::cancel.
const RETRYABLE = ['failed', 'dead_letter', 'cancelled', 'skipped'];
// dead_letter: closes a job that will never run again, so it leaves the failure counts.
const CANCELLABLE = ['pending', 'processing', 'failed', 'dead_letter'];

const jobHref = (id) => `${ROUTES.JOBS}/${id}`;

function Field({ label, children }) {
  return (<><dt>{label}</dt><dd>{children ?? '—'}</dd></>);
}

/**
 * One reach_jobs row: its fields, payload (keys + hash; the full payload for
 * super_admin on request), the audit trail of retries / cancels and of what
 * it produced, and the other jobs its request enqueued. Retry / cancel use
 * the same permissions as the Job Monitor.
 */
export function JobDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { has } = usePermission();
  const canRetry = has('job.retry');
  const canCancel = has('job.cancel');
  const isSuperAdmin = user?.role === 'super_admin';

  const [job, setJob] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [withPayload, setWithPayload] = useState(false);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    jobService.get(id, withPayload ? { include: 'payload' } : undefined)
      .then((d) => setJob(d))
      .catch((e) => { setError(e.message); setJob(null); })
      .finally(() => setLoading(false));
  }, [id, withPayload]);
  useEffect(load, [load]);

  const act = async (fn, message) => {
    setBusy(true); setNotice(null); setError(null);
    try { await fn(); setNotice(message); load(); }
    catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };
  const retry = () => act(() => jobService.retry(job.id), `Job #${job.id} re-enqueued.`);
  const cancel = () => {
    const reason = window.prompt('Cancel reason (optional):');
    if (reason === null) return;
    act(() => jobService.cancel(job.id, reason), `Job #${job.id} cancelled.`);
  };

  if (loading && !job) return <Loader />;
  if (!job) {
    return (
      <div>
        <Alert variant="danger">{error || 'Job not found.'}</Alert>
        <Link to={ROUTES.JOBS}>← Job monitor</Link>
      </div>
    );
  }

  const siblingColumns = [
    { key: 'id', label: '#' },
    { key: 'job_type', label: 'Type', render: (r) => <code style={{ fontSize: '0.75rem' }}>{r.job_type}</code> },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'attempts', label: 'Attempts', render: (r) => `${r.attempts} / ${r.max_attempts}` },
    { key: 'created_at', label: 'Created', render: (r) => formatDateTime(r.created_at) },
    { key: 'error', label: 'Error', render: (r) => r.error_message || '—' },
  ];
  const historyColumns = [
    { key: 'created_at', label: 'When', render: (r) => formatDateTime(r.created_at) },
    { key: 'action', label: 'Event', render: (r) => <code style={{ fontSize: '0.75rem' }}>{r.action}</code> },
    { key: 'entity', label: 'Record', render: (r) => (r.entity_type === 'job' ? 'this job' : `${r.entity_type} #${r.entity_id ?? '—'}`) },
    { key: 'change', label: 'Change', render: (r) => {
      const from = r.old_value?.status;
      const to = r.new_value?.status;
      const reason = r.new_value?.reason || r.reason;
      if (!from && !to && !reason) return '—';
      return <span className="text-xs">{from || '?'} → {to || '?'}{reason ? ` — ${reason}` : ''}</span>;
    } },
    { key: 'user_id', label: 'By', render: (r) => (r.user_id ? `user #${r.user_id}` : (r.actor_type || 'system')) },
  ];

  return (
    <div>
      <p className="text-sm" style={{ margin: '0 0 0.5rem' }}><Link to={ROUTES.JOBS}>← Job monitor</Link></p>
      <div className="page-header">
        <div>
          <h1 style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            Job #{job.id} <StatusBadge status={job.status} />
          </h1>
          <p className="text-sm text-muted"><code>{job.job_type}</code> on <code>{job.queue}</code></p>
        </div>
        <div className="flex gap-2">
          {canRetry && RETRYABLE.includes(job.status) && (
            <button type="button" className="btn btn-secondary" onClick={retry} disabled={busy}><RotateCcw size={13} /> Retry</button>
          )}
          {canCancel && CANCELLABLE.includes(job.status) && (
            <button type="button" className="btn btn-danger" onClick={cancel} disabled={busy}><XCircle size={13} /> Cancel</button>
          )}
          <button type="button" className="btn btn-secondary" onClick={load}><RefreshCw size={13} /> Refresh</button>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      <div className="grid grid-2" style={{ alignItems: 'start', marginBottom: '1rem' }}>
        <Card title="Job">
          <dl className="definition-list">
            <Field label="Type"><DrillLink to={drillHref(ROUTES.JOBS, { job_type: job.job_type })} title="All jobs of this type">{job.job_type}</DrillLink></Field>
            <Field label="Queue"><DrillLink to={drillHref(ROUTES.JOBS, { queue: job.queue })} title="All jobs on this queue">{job.queue}</DrillLink></Field>
            <Field label="Priority">{job.priority}</Field>
            <Field label="Attempts">{job.attempts} / {job.max_attempts}</Field>
            <Field label="Progress">{job.progress != null ? `${job.progress}%${job.progress_message ? ` — ${job.progress_message}` : ''}` : null}</Field>
            <Field label="Worker">{job.worker_id ? <DrillLink to={drillHref(ROUTES.JOBS, { worker_id: job.worker_id })} title="This worker's jobs">{job.worker_id}</DrillLink> : null}</Field>
            <Field label="Request">{job.request_id ? <DrillLink to={drillHref(ROUTES.JOBS, { request_id: job.request_id })} title="Every job of this request">{job.request_id}</DrillLink> : null}</Field>
            <Field label="Correlation">{job.correlation_id}</Field>
            <Field label="Enqueued by">{job.enqueued_actor_type}{job.enqueued_by_user_id ? ` (user #${job.enqueued_by_user_id})` : ''}</Field>
            <Field label="UUID"><code className="text-xs">{job.job_uuid}</code></Field>
          </dl>
        </Card>
        <Card title="Timeline">
          <dl className="definition-list">
            <Field label="Created">{formatDateTime(job.created_at)}</Field>
            <Field label="Available">{formatDateTime(job.available_at)}</Field>
            <Field label="Scheduled">{formatDateTime(job.scheduled_at)}</Field>
            <Field label="Reserved">{formatDateTime(job.reserved_at)}</Field>
            <Field label="Started">{formatDateTime(job.started_at)}</Field>
            <Field label="Lease expires">{formatDateTime(job.lease_expires_at)}</Field>
            <Field label="Completed">{formatDateTime(job.completed_at)}</Field>
            <Field label="Updated">{formatDateTime(job.updated_at)}</Field>
          </dl>
        </Card>
      </div>

      <Card title="Last error / reason">
        {job.error_message
          ? <pre className={job.status === 'skipped' ? 'text-muted text-xs' : 'text-danger text-xs'} style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{job.error_message}</pre>
          : <p className="text-sm text-muted" style={{ margin: 0 }}>No error recorded.</p>}
        <p className="text-xs text-muted" style={{ margin: '0.5rem 0 0' }}>
          The queue keeps the latest attempt&apos;s error on the job; earlier attempts appear in the history below only when they were retried or cancelled by hand.
        </p>
      </Card>

      <div style={{ height: '1rem' }} />
      <Card
        title="Payload"
        actions={isSuperAdmin && job.payload === undefined ? (
          <button type="button" className="btn btn-secondary btn-sm" onClick={() => setWithPayload(true)}>Show full payload</button>
        ) : null}
      >
        {job.payload !== undefined ? (
          <>
            <pre className="text-xs" style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{JSON.stringify(job.payload, null, 2)}</pre>
            {job.result != null && (
              <>
                <h4 className="text-sm" style={{ margin: '0.75rem 0 0.25rem' }}>Result</h4>
                <pre className="text-xs" style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{JSON.stringify(job.result, null, 2)}</pre>
              </>
            )}
          </>
        ) : (
          <p className="text-sm" style={{ margin: 0 }}>
            Keys: {job.payload_summary?.keys?.length ? job.payload_summary.keys.map((k) => <code key={k} style={{ marginRight: 6 }}>{k}</code>) : '—'}
            {job.payload_summary?.hash && <span className="text-xs text-muted"> · hash {job.payload_summary.hash}</span>}
            {!isSuperAdmin && <span className="text-xs text-muted"> · values are shown to super admins only</span>}
          </p>
        )}
      </Card>

      <div style={{ height: '1rem' }} />
      <Card title={`History (${job.history?.length ?? 0})`} padding={false}>
        <DataTable columns={historyColumns} rows={job.history || []} emptyMessage="No audit events for this job." />
      </Card>

      {job.request_id && (
        <>
          <div style={{ height: '1rem' }} />
          <Card
            title={`Other jobs from request ${job.request_id} (${job.siblings?.length ?? 0})`}
            padding={false}
          >
            <DataTable
              columns={siblingColumns}
              rows={job.siblings || []}
              onRowClick={(r) => navigate(jobHref(r.id))}
              rowLabel={(r) => `Open job ${r.id}`}
              emptyMessage="This request enqueued no other jobs."
            />
          </Card>
        </>
      )}
    </div>
  );
}

export default JobDetailPage;
