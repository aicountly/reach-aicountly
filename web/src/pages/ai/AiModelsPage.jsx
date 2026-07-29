import React, { useState, useEffect } from 'react';
import { listAiModels } from '../../services/aiService.js';

export default function AiModelsPage() {
  const [models, setModels] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    listAiModels()
      .then(d => setModels(d.models || []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading models…</p>;
  if (error)   return <p className="text-error">Error: {error}</p>;

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>AI Models</h1>
        <p className="page-header__subtitle">
          Models available for generation routes, with cost and context limits.
        </p>
      </div>

      {models.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>No models configured.</p>
          </div>
        </div>
      ) : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                {['Model Key', 'Provider', 'Enabled', 'Approval', 'Context', 'Input Cost', 'Output Cost'].map(h => (
                  <th key={h}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {models.map(m => (
                <tr key={m.id}>
                  <td><code className="text-xs">{m.model_key}</code></td>
                  <td className="text-muted">{m.provider_id}</td>
                  <td>
                    <span className={`badge ${m.enabled ? 'badge--success' : 'badge--neutral'}`}>
                      {m.enabled ? 'Yes' : 'No'}
                    </span>
                  </td>
                  <td>{m.approval_status}</td>
                  <td className="text-muted">{m.context_limit?.toLocaleString() ?? '—'}</td>
                  <td className="text-muted">${m.input_cost_per_unit}</td>
                  <td className="text-muted">${m.output_cost_per_unit}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
