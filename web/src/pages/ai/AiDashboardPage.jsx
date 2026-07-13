import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { getAiDashboard } from '../../services/aiService.js';
import { ROUTES } from '../../constants/routes.js';
import AiGenerationBadge from '../../components/ai/AiGenerationBadge.jsx';

function StatCard({ label, value, sub, color = 'blue' }) {
  const colors = {
    blue:   'bg-blue-50 border-blue-100 text-blue-700',
    green:  'bg-green-50 border-green-100 text-green-700',
    red:    'bg-red-50 border-red-100 text-red-700',
    purple: 'bg-purple-50 border-purple-100 text-purple-700',
    yellow: 'bg-yellow-50 border-yellow-100 text-yellow-700',
  };
  return (
    <div className={`rounded-lg border p-4 ${colors[color]}`}>
      <p className="text-xs font-medium uppercase tracking-wide opacity-70">{label}</p>
      <p className="text-2xl font-bold mt-1">{value ?? '—'}</p>
      {sub && <p className="text-xs mt-1 opacity-70">{sub}</p>}
    </div>
  );
}

export default function AiDashboardPage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    getAiDashboard()
      .then(setData)
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="text-sm text-gray-500 p-4">Loading AI dashboard…</div>;
  if (error)   return <div className="text-sm text-red-600 p-4">Error: {error}</div>;

  const stats = data?.stats || {};
  const recentRequests = data?.recent_requests || [];

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-bold text-gray-900">AI Dashboard</h1>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <StatCard label="Total Generations" value={stats.total_generations} color="blue" />
        <StatCard label="Completed Today" value={stats.completed_today} color="green" />
        <StatCard label="Failed Today" value={stats.failed_today} color="red" />
        <StatCard label="Today's Cost" value={stats.today_cost ? `$${stats.today_cost}` : '$0.00'} color="purple" />
      </div>

      {recentRequests.length > 0 && (
        <div>
          <h2 className="text-base font-semibold text-gray-800 mb-3">Recent Generation Requests</h2>
          <div className="overflow-x-auto rounded-lg border border-gray-200">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left font-medium text-gray-600">UUID</th>
                  <th className="px-3 py-2 text-left font-medium text-gray-600">Type</th>
                  <th className="px-3 py-2 text-left font-medium text-gray-600">Status</th>
                  <th className="px-3 py-2 text-left font-medium text-gray-600">Created</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {recentRequests.map(r => (
                  <tr key={r.uuid} className="hover:bg-gray-50">
                    <td className="px-3 py-2">
                      <Link to={`/ai/generations/${r.uuid}`} className="text-blue-600 hover:underline font-mono text-xs">
                        {r.uuid?.slice(0, 8)}…
                      </Link>
                    </td>
                    <td className="px-3 py-2 text-gray-700">{r.content_type}</td>
                    <td className="px-3 py-2"><AiGenerationBadge status={r.status} /></td>
                    <td className="px-3 py-2 text-gray-500 text-xs">{r.created_at}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
