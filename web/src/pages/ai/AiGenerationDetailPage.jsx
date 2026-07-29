import React, { useState, useEffect } from 'react';
import { Link, useParams } from 'react-router-dom';
import { getGeneration, cancelGeneration } from '../../services/aiService.js';
import AiGenerationBadge from '../../components/ai/AiGenerationBadge.jsx';
import { ROUTES } from '../../constants/routes.js';

export default function AiGenerationDetailPage() {
  const { uuid } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [cancelling, setCancelling] = useState(false);

  useEffect(() => {
    getGeneration(uuid).then(setData).catch(e => setError(e.message));
  }, [uuid]);

  const handleCancel = async () => {
    setCancelling(true);
    try {
      const result = await cancelGeneration(uuid, 'User requested cancellation');
      setData(prev => ({ ...prev, request: result.request }));
    } catch (e) {
      setError(e.message);
    } finally {
      setCancelling(false);
    }
  };

  if (error) return <p className="text-error">Error: {error}</p>;
  if (!data)  return <p className="muted">Loading…</p>;

  const { request, runs = [], artifact } = data;

  return (
    <div>
      <div className="page-header">
        <div>
          <p className="text-xs text-muted mb-2">
            <Link to={ROUTES.AI_GENERATIONS}>← Generation Requests</Link>
          </p>
          <h1>Generation Request</h1>
          <p className="page-header__subtitle"><code>{request?.uuid}</code></p>
        </div>
        <div className="page-header__actions">
          {request && <AiGenerationBadge status={request.status} />}
          {request && ['pending', 'grounding', 'queued'].includes(request.status) && (
            <button
              type="button"
              className="btn btn--sm btn--danger"
              onClick={handleCancel}
              disabled={cancelling}
            >
              {cancelling ? 'Cancelling…' : 'Cancel'}
            </button>
          )}
        </div>
      </div>

      <div className="card mb-4">
        <div className="card__body">
          <dl className="definition-list" style={{ margin: 0 }}>
            <dt>Task Type</dt>
            <dd>{request?.task_type}</dd>
            <dt>Content Type</dt>
            <dd>{request?.content_type}</dd>
            <dt>Status</dt>
            <dd>{request?.status}</dd>
            <dt>Requested By</dt>
            <dd>{request?.requested_actor_type}</dd>
            <dt>Created</dt>
            <dd>{request?.created_at?.slice(0, 19)}</dd>
            {request?.completed_at && (
              <>
                <dt>Completed</dt>
                <dd>{request.completed_at?.slice(0, 19)}</dd>
              </>
            )}
          </dl>
        </div>
      </div>

      {runs.length > 0 && (
        <section className="mb-4">
          <h2 className="card__title mb-2">Provider Attempts ({runs.length})</h2>
          <div className="flex flex-col gap-2">
            {runs.map(run => (
              <div key={run.id} className="card">
                <div className="card__body">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold text-sm">Attempt #{run.attempt_number}</span>
                    <span className={`badge ${
                      run.status === 'completed' ? 'badge--success'
                        : run.status === 'failed' ? 'badge--danger'
                          : 'badge--neutral'
                    }`}>
                      {run.status}
                    </span>
                  </div>
                  <p className="muted text-xs mt-1" style={{ marginBottom: 0 }}>
                    {run.total_tokens != null && <span>Tokens: {run.total_tokens} · </span>}
                    {run.duration_ms != null && <span>Duration: {run.duration_ms}ms · </span>}
                    {run.error_category && <span className="text-error">Error: {run.error_category}</span>}
                  </p>
                </div>
              </div>
            ))}
          </div>
        </section>
      )}

      {artifact && (
        <section className="card">
          <div className="card__header">
            <h2 className="card__title">Artifact</h2>
          </div>
          <div className="card__body">
            <div className="flex items-center gap-2 mb-2">
              <span className="text-muted text-sm">Schema Validation:</span>
              <span className={`badge ${
                artifact.schema_validation_status === 'passed' ? 'badge--success' : 'badge--danger'
              }`}>
                {artifact.schema_validation_status}
              </span>
            </div>
            {artifact.sanitised_output_json && (
              <details>
                <summary className="text-sm text-secondary" style={{ cursor: 'pointer' }}>
                  View sanitised output
                </summary>
                <pre className="text-xs mt-2" style={{
                  background: 'var(--color-bg)', borderRadius: 'var(--radius)',
                  padding: '0.75rem', overflow: 'auto', maxHeight: 256, margin: 0,
                }}>
                  {JSON.stringify(JSON.parse(artifact.sanitised_output_json), null, 2)}
                </pre>
              </details>
            )}
          </div>
        </section>
      )}
    </div>
  );
}
