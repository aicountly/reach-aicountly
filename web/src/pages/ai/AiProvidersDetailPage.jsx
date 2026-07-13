import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { getAiProvider } from '../../services/aiService.js';

export default function AiProvidersDetailPage() {
  const { id } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    getAiProvider(id)
      .then(d => setData(d.provider || d))
      .catch(e => setError(e.message));
  }, [id]);

  if (error) return <div className="text-sm text-red-600 p-4">Error: {error}</div>;
  if (!data)  return <div className="text-sm text-gray-500 p-4">Loading…</div>;

  return (
    <div className="space-y-4 max-w-2xl">
      <h1 className="text-xl font-bold text-gray-900">{data.display_name}</h1>
      <dl className="grid grid-cols-2 gap-3 text-sm">
        <div><dt className="text-gray-500">Provider Key</dt><dd className="font-mono">{data.provider_key}</dd></div>
        <div><dt className="text-gray-500">Status</dt><dd>{data.status}</dd></div>
        <div><dt className="text-gray-500">Config Status</dt><dd>{data.configuration_status}</dd></div>
        <div><dt className="text-gray-500">Last Health</dt><dd>{data.last_health_status || '—'}</dd></div>
        <div><dt className="text-gray-500">Structured Output</dt><dd>{data.supports_structured_output ? 'Yes' : 'No'}</dd></div>
        <div><dt className="text-gray-500">Tool Calls</dt><dd>{data.supports_tool_calls ? 'Yes' : 'No'}</dd></div>
      </dl>
      <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
        API keys are configured via environment variables and are never shown in this interface.
      </p>
    </div>
  );
}
