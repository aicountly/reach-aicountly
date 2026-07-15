import { Search, BarChart3, Zap, Eye, Link2, Users2, CheckCircle, AlertCircle } from 'lucide-react';

const CARDS = [
  { label: 'Search Queries Tracked', value: '1,248', sub: '+112 this week', icon: Search, color: 'text-blue-600', bg: 'bg-blue-50' },
  { label: 'Content Identities', value: '94', sub: '91 published', icon: BarChart3, color: 'text-green-600', bg: 'bg-green-50' },
  { label: 'IndexNow Submitted', value: '67', sub: 'last 7 days', icon: Zap, color: 'text-yellow-600', bg: 'bg-yellow-50' },
  { label: 'AI Visibility Runs', value: '14', sub: '2 this week', icon: Eye, color: 'text-purple-600', bg: 'bg-purple-50' },
  { label: 'Attributed Conversions', value: '238', sub: '84% attribution rate', icon: Link2, color: 'text-orange-600', bg: 'bg-orange-50' },
  { label: 'Competitors Tracked', value: '4', sub: 'active monitoring', icon: Users2, color: 'text-red-600', bg: 'bg-red-50' },
];

const STATUS = [
  { name: 'GSC Connector', status: 'healthy' },
  { name: 'GA4 Connector', status: 'healthy' },
  { name: 'IndexNow', status: 'idle' },
];

export default function IntelligenceOverviewPage() {
  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Intelligence Control Centre</h1>
        <p className="text-sm text-gray-500 mt-1">Phase 8 — Search Intelligence, Attribution &amp; AI Visibility</p>
      </div>

      <div className="grid grid-cols-3 gap-4">
        {CARDS.map(c => (
          <div key={c.label} className="bg-white rounded-xl border border-gray-200 shadow-sm p-5 flex items-start gap-4">
            <div className={`p-2.5 rounded-lg ${c.bg}`}>
              <c.icon className={`h-5 w-5 ${c.color}`} />
            </div>
            <div>
              <p className={`text-2xl font-bold ${c.color}`}>{c.value}</p>
              <p className="text-sm font-medium text-gray-700">{c.label}</p>
              <p className="text-xs text-gray-400 mt-0.5">{c.sub}</p>
            </div>
          </div>
        ))}
      </div>

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <h2 className="text-base font-semibold text-gray-800 mb-4">Connector Health</h2>
        <div className="space-y-3">
          {STATUS.map(s => (
            <div key={s.name} className="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
              <span className="text-sm text-gray-700">{s.name}</span>
              <div className="flex items-center gap-1.5">
                {s.status === 'healthy' ? (
                  <CheckCircle className="h-4 w-4 text-green-500" />
                ) : (
                  <AlertCircle className="h-4 w-4 text-yellow-500" />
                )}
                <span className="text-sm text-gray-600 capitalize">{s.status}</span>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
