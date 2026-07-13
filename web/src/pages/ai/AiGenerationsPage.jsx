import React, { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { listGenerations } from '../../services/aiService.js';
import AiGenerationBadge from '../../components/ai/AiGenerationBadge.jsx';

export default function AiGenerationsPage() {
  const [requests, setRequests] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    listGenerations(page)
      .then(d => { setRequests(d.requests || []); setTotal(d.total || 0); })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [page]);

  useEffect(() => { load(); }, [load]);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold text-gray-900">Generation Requests</h1>
        <span className="text-sm text-gray-500">{total} total</span>
      </div>

      {error && <div className="text-sm text-red-600">Error: {error}</div>}
      {loading && <div className="text-sm text-gray-500">Loading…</div>}

      {!loading && requests.length === 0 && (
        <p className="text-sm text-gray-500">No generation requests found.</p>
      )}

      {!loading && requests.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                {['UUID', 'Task', 'Content Type', 'Status', 'Requested By', 'Created'].map(h => (
                  <th key={h} className="px-3 py-2 text-left font-medium text-gray-600">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {requests.map(r => (
                <tr key={r.uuid} className="hover:bg-gray-50">
                  <td className="px-3 py-2">
                    <Link to={`/ai/generations/${r.uuid}`} className="text-blue-600 hover:underline font-mono text-xs">
                      {r.uuid?.slice(0, 8)}…
                    </Link>
                  </td>
                  <td className="px-3 py-2 text-gray-700">{r.task_type}</td>
                  <td className="px-3 py-2 text-gray-600">{r.content_type}</td>
                  <td className="px-3 py-2"><AiGenerationBadge status={r.status} /></td>
                  <td className="px-3 py-2 text-gray-500 text-xs">{r.requested_actor_type}</td>
                  <td className="px-3 py-2 text-gray-500 text-xs">{r.created_at?.slice(0, 10)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="flex gap-2">
        <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1} className="px-3 py-1 text-sm rounded border border-gray-200 disabled:opacity-50">Prev</button>
        <span className="text-sm text-gray-600 self-center">Page {page}</span>
        <button onClick={() => setPage(p => p + 1)} disabled={requests.length < 20} className="px-3 py-1 text-sm rounded border border-gray-200 disabled:opacity-50">Next</button>
      </div>
    </div>
  );
}
