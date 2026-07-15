import { useState } from 'react';
import { Map, RefreshCw, CheckCircle, XCircle, AlertTriangle } from 'lucide-react';

export default function SitemapOverviewPage() {
  const [generating, setGenerating] = useState(false);
  const [snapshot] = useState({
    status: 'generated',
    generated_at: '2026-07-15T08:00:00Z',
    total_entries: 142,
    included_entries: 138,
    excluded_noindex: 2,
    excluded_withdrawn: 1,
    excluded_other: 1,
    generation_secs: 0.42,
  });

  const statusIcon = (status) => {
    if (status === 'validated' || status === 'generated') return <CheckCircle className="h-5 w-5 text-green-500" />;
    if (status === 'failed') return <XCircle className="h-5 w-5 text-red-500" />;
    return <AlertTriangle className="h-5 w-5 text-yellow-500" />;
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Map className="h-7 w-7 text-blue-600" />
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Sitemap Intelligence</h1>
            <p className="text-sm text-gray-500">Internal sitemap snapshots from canonical content identities</p>
          </div>
        </div>
        <button
          onClick={() => setGenerating(true)}
          disabled={generating}
          className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          <RefreshCw className={`h-4 w-4 ${generating ? 'animate-spin' : ''}`} />
          {generating ? 'Generating…' : 'Generate Snapshot'}
        </button>
      </div>

      {/* Latest snapshot card */}
      <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-900">Latest Snapshot</h2>
          <div className="flex items-center gap-2">
            {statusIcon(snapshot.status)}
            <span className="text-sm font-medium capitalize">{snapshot.status}</span>
          </div>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Metric label="Total Entries" value={snapshot.total_entries} color="blue" />
          <Metric label="Included" value={snapshot.included_entries} color="green" />
          <Metric label="Excluded (noindex)" value={snapshot.excluded_noindex} color="yellow" />
          <Metric label="Excluded (withdrawn)" value={snapshot.excluded_withdrawn} color="red" />
        </div>
        <p className="mt-4 text-xs text-gray-400">
          Generated {new Date(snapshot.generated_at).toLocaleString()} · {snapshot.generation_secs}s
        </p>
      </div>

      {/* Exclusion policy note */}
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 className="text-sm font-semibold text-blue-800 mb-1">Exclusion Policy</h3>
        <ul className="text-sm text-blue-700 space-y-1 list-disc list-inside">
          <li>Withdrawn content is always excluded</li>
          <li>Content with noindex publication status is excluded</li>
          <li>Unpublished and private content is excluded</li>
          <li>Analytics-ineligible identities are excluded</li>
        </ul>
      </div>
    </div>
  );
}

function Metric({ label, value, color }) {
  const colorMap = { blue: 'text-blue-600', green: 'text-green-600', yellow: 'text-yellow-600', red: 'text-red-600' };
  return (
    <div className="text-center">
      <p className={`text-2xl font-bold ${colorMap[color]}`}>{value}</p>
      <p className="text-xs text-gray-500 mt-1">{label}</p>
    </div>
  );
}
