import React, { useState, useEffect } from 'react';
import { getAiHealth } from '../../services/aiService.js';

export default function AiHealthPage() {
  const [health, setHealth] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = () => {
    setLoading(true);
    getAiHealth()
      .then(setHealth)
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  if (loading) return <div className="text-sm text-gray-500 p-4">Checking AI health…</div>;
  if (error)   return <div className="text-sm text-red-600 p-4">Error: {error}</div>;

  const providers = health?.providers || [];
  const overall = health?.overall_status || 'unknown';

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold text-gray-900">AI Health</h1>
        <button onClick={load} className="text-xs px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-gray-600">
          Refresh
        </button>
      </div>

      <div className={`px-4 py-3 rounded-lg border text-sm font-medium ${overall === 'healthy' ? 'bg-green-50 border-green-200 text-green-700' : overall === 'degraded' ? 'bg-yellow-50 border-yellow-200 text-yellow-700' : 'bg-red-50 border-red-200 text-red-700'}`}>
        System Status: {overall}
      </div>

      {providers.length > 0 && (
        <div className="space-y-2">
          {providers.map(p => (
            <div key={p.provider_key} className="border border-gray-200 rounded-lg p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-gray-800">{p.provider_key}</span>
                <div className="flex items-center gap-2">
                  <span className={`text-xs px-1.5 py-0.5 rounded ${p.healthy ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                    {p.healthy ? 'Healthy' : 'Unhealthy'}
                  </span>
                  {p.circuit_open && (
                    <span className="text-xs px-1.5 py-0.5 rounded bg-red-100 text-red-700">Circuit Open</span>
                  )}
                </div>
              </div>
              {p.response_time_ms !== undefined && (
                <p className="text-xs text-gray-500 mt-1">Response: {p.response_time_ms}ms</p>
              )}
              {p.error_message && (
                <p className="text-xs text-red-600 mt-1">{p.error_message}</p>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
