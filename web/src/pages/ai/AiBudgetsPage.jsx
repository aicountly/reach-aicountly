import React, { useState, useEffect } from 'react';
import { listAiBudgets } from '../../services/aiService.js';

function BudgetBar({ used, hard }) {
  const hardLimit = parseFloat(hard) || 0;
  const usedAmount = parseFloat(used) || 0;
  const pct = hardLimit > 0 ? Math.min(100, (usedAmount / hardLimit) * 100) : 0;
  const barColor = pct >= 90
    ? 'var(--color-danger)'
    : pct >= 70
      ? 'var(--color-warning)'
      : 'var(--color-success)';

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', minWidth: 0 }}>
      <span>${usedAmount.toFixed(4)}</span>
      {hardLimit > 0 && (
        <div
          style={{
            width: 64, height: 6, borderRadius: 999,
            background: 'var(--color-border)', overflow: 'hidden', flexShrink: 0,
          }}
          aria-hidden="true"
        >
          <div style={{ width: `${pct}%`, height: '100%', background: barColor }} />
        </div>
      )}
    </div>
  );
}

export default function AiBudgetsPage() {
  const [budgets, setBudgets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    listAiBudgets()
      .then(d => setBudgets(d.budgets || []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading budgets…</p>;
  if (error)   return <p className="text-error">Error: {error}</p>;

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>AI Budgets</h1>
        <p className="page-header__subtitle">
          Budgets enforce daily and monthly cost limits. Hard limits block generation; warning limits send alerts.
          Requires <code>ai_provider.manage</code> to modify.
        </p>
      </div>

      {budgets.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>
              No budgets configured. Default: unlimited.
            </p>
          </div>
        </div>
      ) : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                {['Scope', 'Reference', 'Period', 'Warning Limit', 'Hard Limit', 'Used', 'Currency', 'Status'].map(h => (
                  <th key={h}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {budgets.map(b => (
                <tr key={b.id}>
                  <td>{b.scope_type}</td>
                  <td><code className="text-xs">{b.scope_reference}</code></td>
                  <td className="text-muted">{b.period_type}</td>
                  <td className="text-muted">${parseFloat(b.warning_limit).toFixed(2)}</td>
                  <td className="text-muted">${parseFloat(b.hard_limit).toFixed(2)}</td>
                  <td><BudgetBar used={b.used_amount} hard={b.hard_limit} /></td>
                  <td className="text-muted">{b.currency}</td>
                  <td>
                    <span className={`badge ${b.enabled ? 'badge--success' : 'badge--neutral'}`}>
                      {b.enabled ? 'Active' : 'Disabled'}
                    </span>
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
