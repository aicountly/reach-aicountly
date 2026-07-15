import { useState, useEffect } from 'react';

function StatCard({ label, value, variant = 'neutral' }) {
  const colours = {
    neutral: 'text-gray-900',
    warning: 'text-amber-600',
    danger:  'text-red-600',
    success: 'text-green-600',
  };
  return (
    <div className="bg-white border border-gray-200 rounded-lg p-4">
      <p className="text-sm text-gray-500 mb-1">{label}</p>
      <p className={`text-2xl font-semibold ${colours[variant]}`}>{value}</p>
    </div>
  );
}

export default function OperationsDashboardPage() {
  const [summary, _setSummary] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // TODO: fetch from /api/readiness/operations/summary
    setLoading(false);
  }, []);

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-semibold text-gray-900">Operations Dashboard</h1>
        <button
          onClick={() => setLoading(true)}
          className="text-sm text-blue-600 hover:underline"
        >
          Refresh
        </button>
      </div>

      {loading ? (
        <div className="text-gray-500 text-sm">Loading…</div>
      ) : !summary ? (
        <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
          <StatCard label="Recommendation Backlog" value="—" />
          <StatCard label="Active Workflows" value="—" />
          <StatCard label="Failed Publications" value="—" variant="danger" />
          <StatCard label="Pending Outcomes" value="—" />
          <StatCard label="Open Critical" value="—" variant="danger" />
          <StatCard label="Open High" value="—" variant="warning" />
        </div>
      ) : (
        <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
          <StatCard label="Recommendation Backlog" value={summary.recommendation_backlog} />
          <StatCard label="Active Workflows" value={summary.active_workflows} />
          <StatCard label="Failed Publications" value={summary.failed_publications} variant={summary.failed_publications > 0 ? 'danger' : 'success'} />
          <StatCard label="Pending Outcomes" value={summary.pending_outcome_windows} />
          <StatCard label="Open Critical" value={summary.open_critical_findings} variant={summary.open_critical_findings > 0 ? 'danger' : 'success'} />
          <StatCard label="Open High" value={summary.open_high_findings} variant={summary.open_high_findings > 0 ? 'warning' : 'success'} />
        </div>
      )}

      <div className="mt-6 bg-white border border-gray-200 rounded-lg p-6">
        <h2 className="font-medium text-gray-900 mb-3">Job Reliability (7 days)</h2>
        <p className="text-sm text-gray-500">Job reliability data will appear here once connected to the API.</p>
      </div>
    </div>
  );
}
