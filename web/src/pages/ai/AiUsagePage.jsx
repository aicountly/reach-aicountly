import React, { useState, useEffect } from 'react';
import { listAiUsage } from '../../services/aiService.js';
import { formatDate } from '../../utils/formatDate';

export default function AiUsagePage() {
  const [usage, setUsage] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    listAiUsage({ page: 1 })
      .then(d => setUsage(d.usage || d.ledger || []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading usage data…</p>;
  if (error)   return <p className="text-error">Error: {error}</p>;

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>AI Usage Ledger</h1>
        <p className="page-header__subtitle">
          All AI generation costs are tracked per provider, model, user, and content type.
        </p>
      </div>

      {usage.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>No usage records found.</p>
          </div>
        </div>
      ) : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                {['Date', 'Task', 'Content Type', 'Input Tokens', 'Output Tokens', 'Cost', 'Currency'].map(h => (
                  <th key={h}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {usage.map((u, i) => (
                <tr key={u.id || i}>
                  <td className="text-muted">{formatDate(u.usage_date)}</td>
                  <td>{u.task_type}</td>
                  <td className="text-muted">{u.content_type}</td>
                  <td className="text-muted">{u.input_tokens?.toLocaleString()}</td>
                  <td className="text-muted">{u.output_tokens?.toLocaleString()}</td>
                  <td className="font-semibold">{parseFloat(u.estimated_cost || 0).toFixed(6)}</td>
                  <td className="text-muted">{u.currency || 'USD'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
