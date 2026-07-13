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

  if (loading) return <div className="text-sm text-gray-500 p-4">Loading models…</div>;
  if (error)   return <div className="text-sm text-red-600 p-4">Error: {error}</div>;

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold text-gray-900">AI Models</h1>
      {models.length === 0 ? (
        <p className="text-sm text-gray-500">No models configured.</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                {['Model Key', 'Provider', 'Enabled', 'Approval', 'Context', 'Input Cost', 'Output Cost'].map(h => (
                  <th key={h} className="px-3 py-2 text-left font-medium text-gray-600">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {models.map(m => (
                <tr key={m.id} className="hover:bg-gray-50">
                  <td className="px-3 py-2 font-mono text-xs">{m.model_key}</td>
                  <td className="px-3 py-2 text-gray-600">{m.provider_id}</td>
                  <td className="px-3 py-2">
                    <span className={`text-xs px-1 rounded ${m.enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                      {m.enabled ? 'Yes' : 'No'}
                    </span>
                  </td>
                  <td className="px-3 py-2 text-gray-600">{m.approval_status}</td>
                  <td className="px-3 py-2 text-gray-600">{m.context_limit?.toLocaleString()}</td>
                  <td className="px-3 py-2 text-gray-600">${m.input_cost_per_unit}</td>
                  <td className="px-3 py-2 text-gray-600">${m.output_cost_per_unit}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
