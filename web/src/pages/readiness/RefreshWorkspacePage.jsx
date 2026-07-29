import { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';

const STATUS_ORDER = [
  'accepted', 'brief_prepared', 'draft_generating', 'draft_ready',
  'in_review', 'approved', 'publish_queued', 'published', 'monitoring', 'outcome_recorded',
];

function StatusBadge({ status }) {
  const terminal = ['rejected', 'cancelled', 'withdrawn', 'outcome_recorded', 'failed'].includes(status);
  const active = ['in_review', 'approved'].includes(status);
  const cls = terminal ? 'badge badge--muted' : active ? 'badge badge--info' : 'badge badge--success';
  return (
    <span className={cls}>
      {status.replace(/_/g, ' ')}
    </span>
  );
}

function ProgressSteps({ currentStatus }) {
  const idx = STATUS_ORDER.indexOf(currentStatus);
  return (
    <div className="btn-group mb-4" style={{ gap: '0.5rem' }} aria-label="Workflow progress">
      {STATUS_ORDER.map((s, i) => (
        <span
          key={s}
          className="text-sm"
          style={{
            color: i === idx ? 'var(--color-primary-hover)' : i < idx ? 'var(--color-success)' : 'var(--color-text-muted)',
            fontWeight: i === idx ? 600 : 400,
          }}
        >
          {i > 0 && <span style={{ color: 'var(--color-border)', marginRight: '0.5rem' }}>›</span>}
          {s.replace(/_/g, ' ')}
        </span>
      ))}
    </div>
  );
}

export default function RefreshWorkspacePage() {
  const { id } = useParams();
  const [workflow, _setWorkflow] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // TODO: fetch from /api/refresh/workflows/:id
    setLoading(false);
  }, [id]);

  if (loading) return <p className="muted">Loading…</p>;
  if (!workflow) {
    return (
      <div>
        <div className="page-header">
          <h1>Refresh Workspace</h1>
        </div>
        <div className="card">
          <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              Enter a workflow ID in the URL to view a refresh workspace, or select a
              recommendation from the backlog to create a workflow.
            </p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div>
      <div className="page-header">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
          <h1>Refresh Workspace</h1>
          <StatusBadge status={workflow.status} />
        </div>
      </div>

      <ProgressSteps currentStatus={workflow.status} />

      <div className="grid grid-2">
        <div className="card">
          <div className="card__header">Objective</div>
          <div className="card__body">
            <p className="text-sm" style={{ margin: 0 }}>{workflow.refresh_objective}</p>
          </div>
        </div>

        <div className="card">
          <div className="card__header">Evidence Snapshot</div>
          <div className="card__body">
            <p className="text-sm text-muted" style={{ margin: 0 }}>Completeness: —</p>
          </div>
        </div>

        <div className="card" style={{ gridColumn: '1 / -1' }}>
          <div className="card__header">Brief</div>
          <div className="card__body">
            <p className="text-sm text-muted" style={{ margin: 0 }}>No brief prepared yet.</p>
          </div>
        </div>
      </div>
    </div>
  );
}
