import React, { useState, useEffect } from 'react';
import { listAiBudgets } from '../../services/aiService.js';

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

  if (loading) return <div className="text-sm text-gray-500 p-4">Loading budgets…</div>;
  if (error)   return <div className="text-sm text-red-600 p-4">Error: {error}</div>;

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold text-gray-900">AI Budgets</h1>
      <p className="text-xs text-gray-500">
        Budgets enforce daily and monthly cost limits. Hard limits block generation; warning limits send alerts.
        Requires <code>ai_provider.manage</code> permission to modify.
      </p>
      {budgets.length === 0 ? (
        <p className="text-sm text-gray-500">No budgets configured. Default: unlimited.</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                {['Scope', 'Reference', 'Period', 'Warning Limit', 'Hard Limit', 'Used', 'Currency', 'Status'].map(h => (
                  <th key={h} className="px-3 py-2 text-left font-medium text-gray-600">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {budgets.map(b => {
                const pct = b.hard_limit > 0 ? Math.min(100, (b.used_amount / b.hard_limit) * 100) : 0;
                const barColor = pct >= 90 ? 'bg-red-500' : pct >= 70 ? 'bg-yellow-500' : 'bg-green-500';
                return (
                  <tr key={b.id} className="hover:bg-gray-50">
                    <td className="px-3 py-2 text-gray-700">{b.scope_type}</td>
                    <td className="px-3 py-2 font-mono text-xs text-gray-600">{b.scope_reference}</td>
                    <td className="px-3 py-2 text-gray-600">{b.period_type}</td>
                    <td className="px-3 py-2 text-gray-600">${parseFloat(b.warning_limit).toFixed(2)}</td>
                    <td className="px-3 py-2 text-gray-600">${parseFloat(b.hard_limit).toFixed(2)}</td>
                    <td className="px-3 py-2">
                      <div className="flex items-center gap-2">
                        <span className="text-gray-700">${parseFloat(b.used_amount).toFixed(4)}</span>
                        {b.hard_limit > 0 && (
                          <div className="w-16 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                            <div className={`h-full rounded-full ${barColor}`} style={{ width: `${pct}%` }} />
                          </div>
                        )}
                      </div>
                    </td>
                    <td className="px-3 py-2 text-gray-500">{b.currency}</td>
                    <td className="px-3 py-2">
                      <span className={`text-xs px-1.5 py-0.5 rounded ${b.enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                        {b.enabled ? 'Active' : 'Disabled'}
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
