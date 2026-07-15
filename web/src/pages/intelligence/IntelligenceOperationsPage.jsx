import { Settings, CheckCircle, AlertCircle, XCircle, RefreshCw, Activity } from 'lucide-react';

const JOBS = [
  { name: 'GSC Incremental Ingest', type: 'intelligence.search_console.ingest', last_run: '2026-07-15T07:00:00Z', status: 'completed', duration_ms: 1240 },
  { name: 'GA4 Content Ingest', type: 'intelligence.content_analytics.ingest', last_run: '2026-07-15T07:05:00Z', status: 'completed', duration_ms: 890 },
  { name: 'Sitemap Snapshot', type: 'intelligence.sitemap.snapshot', last_run: '2026-07-15T06:00:00Z', status: 'completed', duration_ms: 420 },
  { name: 'Attribution Calculate', type: 'intelligence.attribution.calculate', last_run: '2026-07-14T23:00:00Z', status: 'completed', duration_ms: 3200 },
  { name: 'Connector Health Check', type: 'intelligence.connector.health_check', last_run: '2026-07-15T07:30:00Z', status: 'completed', duration_ms: 280 },
  { name: 'Visibility Run Execute', type: 'intelligence.visibility.execute', last_run: '2026-07-15T06:30:00Z', status: 'failed', duration_ms: 0 },
];

const StatusIcon = ({ status }) => {
  if (status === 'completed') return <CheckCircle className="h-4 w-4 text-green-500" />;
  if (status === 'failed') return <XCircle className="h-4 w-4 text-red-500" />;
  return <AlertCircle className="h-4 w-4 text-yellow-500" />;
};

export default function IntelligenceOperationsPage() {
  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Activity className="h-7 w-7 text-gray-700" />
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Intelligence Operations</h1>
            <p className="text-sm text-gray-500">Background jobs, reconciliation and health monitoring</p>
          </div>
        </div>
        <button className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">
          <RefreshCw className="h-4 w-4" /> Refresh
        </button>
      </div>

      <div className="grid grid-cols-3 gap-4">
        {[
          { label: 'Jobs (24h)', value: '48', icon: Settings, color: 'text-blue-600' },
          { label: 'Succeeded', value: '46', icon: CheckCircle, color: 'text-green-600' },
          { label: 'Failed', value: '2', icon: XCircle, color: 'text-red-600' },
        ].map(s => (
          <div key={s.label} className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm flex items-center gap-3">
            <s.icon className={`h-8 w-8 ${s.color}`} />
            <div>
              <p className={`text-2xl font-bold ${s.color}`}>{s.value}</p>
              <p className="text-xs text-gray-500">{s.label}</p>
            </div>
          </div>
        ))}
      </div>

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div className="px-4 py-3 border-b border-gray-100">
          <h2 className="text-base font-semibold text-gray-800">Scheduled Intelligence Jobs</h2>
        </div>
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Job</th>
              <th className="px-4 py-3 text-left">Type</th>
              <th className="px-4 py-3 text-left">Status</th>
              <th className="px-4 py-3 text-right">Duration</th>
              <th className="px-4 py-3 text-left">Last Run</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {JOBS.map((j, i) => (
              <tr key={i} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium text-gray-800">{j.name}</td>
                <td className="px-4 py-3 font-mono text-xs text-gray-500">{j.type}</td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5">
                    <StatusIcon status={j.status} />
                    <span className="text-sm text-gray-600 capitalize">{j.status}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-right text-gray-500">{j.duration_ms > 0 ? `${j.duration_ms}ms` : '—'}</td>
                <td className="px-4 py-3 text-gray-400 text-xs">{new Date(j.last_run).toLocaleString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
