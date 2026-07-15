import { Target, Users, CheckCircle, AlertCircle } from 'lucide-react';

export default function AttributionOverviewPage() {
  const stats = [
    { label: 'Total Conversions', value: '347', icon: Target, color: 'text-blue-600' },
    { label: 'Attributed', value: '289', icon: CheckCircle, color: 'text-green-600' },
    { label: 'Unattributed', value: '58', icon: AlertCircle, color: 'text-yellow-600' },
    { label: 'Attribution Rate', value: '83.3%', icon: Users, color: 'text-purple-600' },
  ];

  const breakdown = [
    { channel: 'Organic Search', conversions: 142, pct: 49 },
    { channel: 'Email Campaign', conversions: 87, pct: 30 },
    { channel: 'Social Media', conversions: 38, pct: 13 },
    { channel: 'Direct', conversions: 22, pct: 8 },
  ];

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center gap-3">
        <Target className="h-7 w-7 text-blue-600" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Attribution Overview</h1>
          <p className="text-sm text-gray-500">First-touch and last-touch lead attribution foundations</p>
        </div>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {stats.map(s => (
          <div key={s.label} className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
            <s.icon className={`h-5 w-5 ${s.color} mb-2`} />
            <p className={`text-2xl font-bold ${s.color}`}>{s.value}</p>
            <p className="text-xs text-gray-500 mt-1">{s.label}</p>
          </div>
        ))}
      </div>

      <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
        <h2 className="text-base font-semibold text-gray-800 mb-4">Conversions by First-Touch Channel</h2>
        <div className="space-y-3">
          {breakdown.map(b => (
            <div key={b.channel}>
              <div className="flex justify-between text-sm mb-1">
                <span className="text-gray-700">{b.channel}</span>
                <span className="font-medium text-gray-800">{b.conversions} ({b.pct}%)</span>
              </div>
              <div className="w-full bg-gray-100 rounded-full h-2">
                <div className="bg-blue-500 h-2 rounded-full" style={{ width: `${b.pct}%` }} />
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
        <strong>Phase 8 Attribution:</strong> First-touch and last-touch attribution only.
        Multi-touch weighting and revenue attribution are planned for Phase 9.
      </div>
    </div>
  );
}
