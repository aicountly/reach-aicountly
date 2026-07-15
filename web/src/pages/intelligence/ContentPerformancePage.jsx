import { BarChart2, TrendingUp, TrendingDown, Clock } from 'lucide-react';

const SAMPLE_CONTENT = [
  { title: 'Complete Guide to Accounting Software', url: '/blog/accounting-guide', sessions: 2340, engagement_rate: 0.68, avg_time: 184, trend: 'up' },
  { title: 'Bookkeeping Tips for SMBs', url: '/blog/bookkeeping-tips', sessions: 1820, engagement_rate: 0.71, avg_time: 203, trend: 'up' },
  { title: 'GST Filing Guide India', url: '/blog/gst-guide', sessions: 1240, engagement_rate: 0.58, avg_time: 147, trend: 'down' },
  { title: 'Invoice Management Features', url: '/features/invoicing', sessions: 980, engagement_rate: 0.82, avg_time: 92, trend: 'stable' },
  { title: 'Pricing Plans Overview', url: '/pricing', sessions: 760, engagement_rate: 0.44, avg_time: 68, trend: 'stable' },
];

export default function ContentPerformancePage() {
  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center gap-3">
        <BarChart2 className="h-7 w-7 text-purple-600" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Content Performance</h1>
          <p className="text-sm text-gray-500">Per-content GA4 engagement metrics</p>
        </div>
      </div>
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Content</th>
              <th className="px-4 py-3 text-right">Sessions</th>
              <th className="px-4 py-3 text-right">Engagement Rate</th>
              <th className="px-4 py-3 text-right">Avg Time</th>
              <th className="px-4 py-3 text-center">Trend</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {SAMPLE_CONTENT.map((c, i) => (
              <tr key={i} className="hover:bg-gray-50">
                <td className="px-4 py-3">
                  <p className="font-medium text-gray-800 text-sm">{c.title}</p>
                  <p className="font-mono text-xs text-gray-400">{c.url}</p>
                </td>
                <td className="px-4 py-3 text-right font-medium text-gray-800">{c.sessions.toLocaleString()}</td>
                <td className="px-4 py-3 text-right">
                  <span className={`font-medium ${c.engagement_rate >= 0.65 ? 'text-green-600' : c.engagement_rate >= 0.5 ? 'text-yellow-600' : 'text-red-600'}`}>
                    {(c.engagement_rate * 100).toFixed(0)}%
                  </span>
                </td>
                <td className="px-4 py-3 text-right text-gray-600 flex items-center justify-end gap-1">
                  <Clock className="h-3 w-3 text-gray-400" />
                  {Math.floor(c.avg_time / 60)}m {c.avg_time % 60}s
                </td>
                <td className="px-4 py-3 text-center">
                  {c.trend === 'up' && <TrendingUp className="h-4 w-4 text-green-500 mx-auto" />}
                  {c.trend === 'down' && <TrendingDown className="h-4 w-4 text-red-500 mx-auto" />}
                  {c.trend === 'stable' && <span className="text-gray-400 text-xs">—</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
