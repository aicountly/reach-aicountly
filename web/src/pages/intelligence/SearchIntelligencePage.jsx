import { Search, TrendingUp, TrendingDown, Minus, RefreshCw } from 'lucide-react';

export default function SearchIntelligencePage() {
  const stats = [
    { label: 'Total Queries', value: '3,847', trend: '+12%', up: true },
    { label: 'Avg Position', value: '8.4', trend: '-0.6', up: true },
    { label: 'Total Clicks', value: '12,340', trend: '+8%', up: true },
    { label: 'Impressions', value: '284,000', trend: '+15%', up: true },
    { label: 'Avg CTR', value: '4.3%', trend: '-0.2%', up: false },
    { label: 'Indexed Pages', value: '138', trend: '+3', up: true },
  ];

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Search className="h-7 w-7 text-blue-600" />
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Search Console Intelligence</h1>
            <p className="text-sm text-gray-500">Google Search Console performance analytics</p>
          </div>
        </div>
        <button className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">
          <RefreshCw className="h-4 w-4" /> Ingest Now
        </button>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
        {stats.map(s => (
          <div key={s.label} className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
            <p className="text-sm text-gray-500">{s.label}</p>
            <p className="text-2xl font-bold text-gray-900 mt-1">{s.value}</p>
            <div className="flex items-center gap-1 mt-1">
              {s.up ? <TrendingUp className="h-3 w-3 text-green-500" /> : <TrendingDown className="h-3 w-3 text-red-500" />}
              <span className={`text-xs font-medium ${s.up ? 'text-green-600' : 'text-red-600'}`}>{s.trend}</span>
              <span className="text-xs text-gray-400">vs last period</span>
            </div>
          </div>
        ))}
      </div>

      <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800">
        Connect a Google Search Console property via <strong>Settings → Connectors</strong> to populate live data.
      </div>
    </div>
  );
}
