import { useParams } from 'react-router-dom';
import { BarChart2, TrendingUp, Clock, Eye } from 'lucide-react';

export default function ContentDetailAnalyticsPage() {
  const { id } = useParams();

  const metrics = [
    { label: '30-Day Sessions', value: '2,340', icon: Eye },
    { label: 'Engagement Rate', value: '68%', icon: TrendingUp },
    { label: 'Avg Session Time', value: '3m 4s', icon: Clock },
    { label: 'Organic Entrances', value: '1,890', icon: BarChart2 },
  ];

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center gap-3">
        <BarChart2 className="h-7 w-7 text-purple-600" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Content Analytics</h1>
          <p className="text-sm text-gray-500">Identity #{id} performance detail</p>
        </div>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {metrics.map(m => (
          <div key={m.label} className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
            <div className="flex items-center gap-2 mb-2">
              <m.icon className="h-4 w-4 text-purple-500" />
              <p className="text-xs text-gray-500">{m.label}</p>
            </div>
            <p className="text-2xl font-bold text-gray-900">{m.value}</p>
          </div>
        ))}
      </div>

      <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
        <h2 className="text-base font-semibold text-gray-800 mb-4">Traffic by Source (30 days)</h2>
        <div className="space-y-3">
          {[
            { source: 'Organic Search', pct: 62, color: 'bg-blue-500' },
            { source: 'Direct', pct: 18, color: 'bg-purple-500' },
            { source: 'Social', pct: 12, color: 'bg-pink-500' },
            { source: 'Referral', pct: 8, color: 'bg-yellow-500' },
          ].map(s => (
            <div key={s.source}>
              <div className="flex justify-between text-sm mb-1">
                <span className="text-gray-700">{s.source}</span>
                <span className="font-medium text-gray-800">{s.pct}%</span>
              </div>
              <div className="w-full bg-gray-100 rounded-full h-2">
                <div className={`${s.color} h-2 rounded-full`} style={{ width: `${s.pct}%` }} />
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
