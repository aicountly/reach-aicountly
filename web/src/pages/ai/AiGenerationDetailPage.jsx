import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import { getGeneration, cancelGeneration } from '../../services/aiService.js';
import AiGenerationBadge from '../../components/ai/AiGenerationBadge.jsx';
import ValidationFindings from '../../components/ai/ValidationFindings.jsx';

export default function AiGenerationDetailPage() {
  const { uuid } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [cancelling, setCancelling] = useState(false);

  useEffect(() => {
    getGeneration(uuid).then(setData).catch(e => setError(e.message));
  }, [uuid]);

  const handleCancel = async () => {
    setCancelling(true);
    try {
      const result = await cancelGeneration(uuid, 'User requested cancellation');
      setData(prev => ({ ...prev, request: result.request }));
    } catch (e) {
      setError(e.message);
    } finally {
      setCancelling(false);
    }
  };

  if (error) return <div className="text-sm text-red-600 p-4">Error: {error}</div>;
  if (!data)  return <div className="text-sm text-gray-500 p-4">Loading…</div>;

  const { request, runs = [], artifact } = data;

  return (
    <div className="space-y-6 max-w-4xl">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Generation Request</h1>
          <p className="text-xs font-mono text-gray-500">{request?.uuid}</p>
        </div>
        <div className="flex items-center gap-2">
          {request && <AiGenerationBadge status={request.status} />}
          {request && ['pending', 'grounding', 'queued'].includes(request.status) && (
            <button onClick={handleCancel} disabled={cancelling} className="text-xs px-2 py-1 border border-red-200 text-red-600 rounded hover:bg-red-50 disabled:opacity-50">
              {cancelling ? 'Cancelling…' : 'Cancel'}
            </button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
        <div><span className="text-gray-500">Task Type</span><p>{request?.task_type}</p></div>
        <div><span className="text-gray-500">Content Type</span><p>{request?.content_type}</p></div>
        <div><span className="text-gray-500">Status</span><p>{request?.status}</p></div>
        <div><span className="text-gray-500">Requested By</span><p>{request?.requested_actor_type}</p></div>
        <div><span className="text-gray-500">Created</span><p>{request?.created_at?.slice(0, 19)}</p></div>
        {request?.completed_at && <div><span className="text-gray-500">Completed</span><p>{request.completed_at?.slice(0, 19)}</p></div>}
      </div>

      {runs.length > 0 && (
        <div>
          <h2 className="text-base font-semibold text-gray-800 mb-2">Provider Attempts ({runs.length})</h2>
          <div className="space-y-2">
            {runs.map(run => (
              <div key={run.id} className="border border-gray-200 rounded p-3 text-xs">
                <div className="flex items-center justify-between">
                  <span className="font-medium">Attempt #{run.attempt_number}</span>
                  <span className={`px-1.5 py-0.5 rounded ${run.status === 'completed' ? 'bg-green-100 text-green-700' : run.status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600'}`}>
                    {run.status}
                  </span>
                </div>
                <div className="mt-1 text-gray-500">
                  {run.total_tokens && <span>Tokens: {run.total_tokens} • </span>}
                  {run.duration_ms && <span>Duration: {run.duration_ms}ms • </span>}
                  {run.error_category && <span className="text-red-600">Error: {run.error_category}</span>}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {artifact && (
        <div>
          <h2 className="text-base font-semibold text-gray-800 mb-2">Artifact</h2>
          <div className="border border-gray-200 rounded-lg p-3 text-xs space-y-2">
            <div className="flex items-center gap-2">
              <span className="text-gray-500">Schema Validation:</span>
              <span className={`px-1.5 py-0.5 rounded ${artifact.schema_validation_status === 'passed' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                {artifact.schema_validation_status}
              </span>
            </div>
            {artifact.sanitised_output_json && (
              <details>
                <summary className="cursor-pointer text-gray-600">View sanitised output</summary>
                <pre className="mt-2 bg-gray-50 rounded p-2 overflow-auto max-h-64 text-gray-700">
                  {JSON.stringify(JSON.parse(artifact.sanitised_output_json), null, 2)}
                </pre>
              </details>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
