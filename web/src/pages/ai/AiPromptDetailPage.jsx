import React, { useState, useEffect } from 'react';
import { Link, useParams } from 'react-router-dom';
import { getPrompt, listPromptVersions, approvePromptVersion } from '../../services/aiService.js';
import { usePermission } from '../../hooks/usePermission.js';
import { ROUTES } from '../../constants/routes.js';

export default function AiPromptDetailPage() {
  const { id } = useParams();
  const { has } = usePermission();
  const [template, setTemplate] = useState(null);
  const [versions, setVersions] = useState([]);
  const [error, setError] = useState(null);
  const [approving, setApproving] = useState(null);

  const canApprove = has('ai_prompt.approve');

  useEffect(() => {
    Promise.all([getPrompt(id), listPromptVersions(id)])
      .then(([tp, vd]) => {
        setTemplate(tp.template || tp);
        setVersions(vd.versions || []);
      })
      .catch(e => setError(e.message));
  }, [id]);

  const handleApprove = async (versionId) => {
    if (!canApprove) return;
    setApproving(versionId);
    try {
      const result = await approvePromptVersion(id, versionId);
      setVersions(prev => prev.map(v => v.id === versionId ? (result.version || v) : v));
      setTemplate(prev => ({ ...prev, status: 'approved', current_version_id: versionId }));
    } catch (e) {
      setError(e.message);
    } finally {
      setApproving(null);
    }
  };

  if (error)     return <p className="text-error">Error: {error}</p>;
  if (!template) return <p className="muted">Loading…</p>;

  return (
    <div>
      <div className="page-header page-header--stack">
        <p className="text-xs text-muted mb-2">
          <Link to={ROUTES.AI_PROMPTS}>← Prompt Templates</Link>
        </p>
        <h1>{template.name}</h1>
        <p className="page-header__subtitle"><code>{template.slug}</code></p>
      </div>

      <div className="card mb-4" style={{ maxWidth: 720 }}>
        <div className="card__body">
          <dl className="definition-list" style={{ margin: 0 }}>
            <dt>Task Type</dt>
            <dd>{template.task_type}</dd>
            <dt>Content Type</dt>
            <dd>{template.content_type || '—'}</dd>
            <dt>Status</dt>
            <dd>
              <span className="badge badge--neutral">{template.status}</span>
            </dd>
          </dl>
        </div>
      </div>

      <section>
        <div className="page-header page-header--stack" style={{ marginBottom: '0.75rem' }}>
          <h2 style={{ fontSize: '1.05rem', margin: 0 }}>Versions</h2>
          <p className="page-header__subtitle">
            Prompt versions are immutable after creation. AI cannot approve versions.
          </p>
        </div>

        {versions.length === 0 ? (
          <div className="card">
            <div className="card__body">
              <p className="muted" style={{ margin: 0, padding: 0 }}>No versions found.</p>
            </div>
          </div>
        ) : (
          <div className="flex flex-col gap-3">
            {versions.map(v => (
              <div key={v.id} className="card">
                <div className="card__body">
                  <div className="flex items-center justify-between gap-2 mb-2">
                    <div className="btn-group">
                      <span className="font-semibold">v{v.version_number}</span>
                      <span className={`badge ${v.status === 'approved' ? 'badge--success' : 'badge--warning'}`}>
                        {v.status}
                      </span>
                      {template.current_version_id === v.id && (
                        <span className="badge badge-primary">current</span>
                      )}
                    </div>
                    {canApprove && v.status !== 'approved' && (
                      <button
                        type="button"
                        className="btn btn--sm btn--primary"
                        onClick={() => handleApprove(v.id)}
                        disabled={approving === v.id}
                      >
                        {approving === v.id ? 'Approving…' : 'Approve'}
                      </button>
                    )}
                  </div>
                  {v.change_summary && <p className="muted text-xs mb-2">{v.change_summary}</p>}
                  <div className="two-col-grid">
                    <div>
                      <p className="text-xs font-semibold text-secondary mb-1">System Prompt</p>
                      <pre className="text-xs" style={{
                        background: 'var(--color-bg)', borderRadius: 'var(--radius)',
                        padding: '0.5rem', overflow: 'auto', maxHeight: 80, margin: 0,
                      }}>
                        {v.system_template?.slice(0, 200)}
                      </pre>
                    </div>
                    <div>
                      <p className="text-xs font-semibold text-secondary mb-1">User Prompt</p>
                      <pre className="text-xs" style={{
                        background: 'var(--color-bg)', borderRadius: 'var(--radius)',
                        padding: '0.5rem', overflow: 'auto', maxHeight: 80, margin: 0,
                      }}>
                        {v.user_template?.slice(0, 200)}
                      </pre>
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
