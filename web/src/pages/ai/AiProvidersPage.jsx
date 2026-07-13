import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { listAiProviders } from '../../services/aiService.js';

const STATUS_BADGE = {
  enabled:  'bg-green-100 text-green-700',
  disabled: 'bg-gray-100 text-gray-600',
  draft:    'bg-yellow-100 text-yellow-700',
  unhealthy:'bg-red-100 text-red-700',
  deprecated:'bg-orange-100 text-orange-700',
};

export default function AiProvidersPage() {
  const [providers, setProviders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    listAiProviders()
      .then(d => setProviders(d.providers || []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="text-sm text-gray-500 p-4">Loading providers…</div>;
  if (error)   return <div className="text-sm text-red-600 p-4">Error: {error}</div>;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold text-gray-900">AI Providers</h1>
      </div>
      <div className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
        Provider API keys are never stored in the database. Configure keys via environment variables only.
      </div>

      {providers.length === 0 ? (
        <p className="text-sm text-gray-500">No providers configured.</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-gray-600">Key</th>
                <th className="px-3 py-2 text-left font-medium text-gray-600">Display Name</th>
                <th className="px-3 py-2 text-left font-medium text-gray-600">Status</th>
                <th className="px-3 py-2 text-left font-medium text-gray-600">Config</th>
                <th className="px-3 py-2 text-left font-medium text-gray-600">Health</th>
                <th className="px-3 py-2 text-left font-medium text-gray-600">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {providers.map(p => (
                <tr key={p.id} className="hover:bg-gray-50">
                  <td className="px-3 py-2 font-mono text-xs text-gray-700">{p.provider_key}</td>
                  <td className="px-3 py-2 text-gray-800">{p.display_name}</td>
                  <td className="px-3 py-2">
                    <span className={`text-xs px-1.5 py-0.5 rounded ${STATUS_BADGE[p.status] || 'bg-gray-100 text-gray-600'}`}>
                      {p.status}
                    </span>
                  </td>
                  <td className="px-3 py-2">
                    <span className={`text-xs px-1.5 py-0.5 rounded ${p.configuration_status === 'configured' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                      {p.configuration_status}
                    </span>
                  </td>
                  <td className="px-3 py-2 text-xs text-gray-500">{p.last_health_status || '—'}</td>
                  <td className="px-3 py-2">
                    <Link to={`/ai/providers/${p.id}`} className="text-xs text-blue-600 hover:underline">View</Link>
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
