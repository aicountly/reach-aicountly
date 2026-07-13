import React, { useState, useEffect } from 'react';
import { listAiUsage } from '../../services/aiService.js';

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

  if (loading) return <div className="text-sm text-gray-500 p-4">Loading usage data…</div>;
  if (error)   return <div className="text-sm text-red-600 p-4">Error: {error}</div>;

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold text-gray-900">AI Usage Ledger</h1>
      <p className="text-xs text-gray-500">All AI generation costs are tracked per provider, model, user, and content type.</p>
      {usage.length === 0 ? (
        <p className="text-sm text-gray-500">No usage records found.</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                {['Date', 'Task', 'Content Type', 'Input Tokens', 'Output Tokens', 'Cost', 'Currency'].map(h => (
                  <th key={h} className="px-3 py-2 text-left font-medium text-gray-600">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {usage.map((u, i) => (
                <tr key={u.id || i} className="hover:bg-gray-50">
                  <td className="px-3 py-2 text-gray-600">{u.usage_date}</td>
                  <td className="px-3 py-2 text-gray-700">{u.task_type}</td>
                  <td className="px-3 py-2 text-gray-600">{u.content_type}</td>
                  <td className="px-3 py-2 text-gray-600">{u.input_tokens?.toLocaleString()}</td>
                  <td className="px-3 py-2 text-gray-600">{u.output_tokens?.toLocaleString()}</td>
                  <td className="px-3 py-2 text-gray-700 font-medium">{parseFloat(u.estimated_cost || 0).toFixed(6)}</td>
                  <td className="px-3 py-2 text-gray-500">{u.currency || 'USD'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
