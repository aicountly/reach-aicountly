import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { listPrompts } from '../../services/aiService.js';

const STATUS_BADGE = {
  approved:     'badge badge--success',
  draft:        'badge badge--warning',
  needs_review: 'badge badge--info',
  rejected:     'badge badge--danger',
  deprecated:   'badge badge--muted',
};

export default function AiPromptsPage() {
  const [templates, setTemplates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    listPrompts()
      .then(d => setTemplates(d.templates || []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading prompts…</p>;
  if (error)   return <p className="text-error">Error: {error}</p>;

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Prompt Templates</h1>
        <p className="page-header__subtitle">
          Prompt versions are immutable after creation. Only approved versions are used for generation.
          Approval requires <code>ai_prompt.approve</code> permission.
        </p>
      </div>

      {templates.length === 0 ? (
        <p className="muted">No prompt templates found.</p>
      ) : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                {['Name', 'Slug', 'Task Type', 'Content Type', 'Status', 'Actions'].map(h => (
                  <th key={h}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {templates.map(t => (
                <tr key={t.id}>
                  <td>{t.name}</td>
                  <td className="text-muted text-xs">{t.slug}</td>
                  <td>{t.task_type}</td>
                  <td>{t.content_type || '—'}</td>
                  <td>
                    <span className={STATUS_BADGE[t.status] || 'badge badge--muted'}>
                      {t.status}
                    </span>
                  </td>
                  <td>
                    <Link to={`/ai/prompts/${t.id}`} className="text-sm">View</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
