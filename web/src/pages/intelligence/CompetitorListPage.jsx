import { useState } from 'react';
import { Users2, Plus, TrendingUp, TrendingDown } from 'lucide-react';

const COMPETITORS = [
  { id: 1, name: 'QuickBooks India', domain: 'quickbooks.intuit.com', category: 'accounting_software', monitoring_status: 'active', mention_rate: 0.72 },
  { id: 2, name: 'Zoho Books', domain: 'zoho.com/books', category: 'accounting_software', monitoring_status: 'active', mention_rate: 0.64 },
  { id: 3, name: 'Tally Solutions', domain: 'tallysolutions.com', category: 'accounting_software', monitoring_status: 'active', mention_rate: 0.58 },
  { id: 4, name: 'FreshBooks', domain: 'freshbooks.com', category: 'invoicing', monitoring_status: 'active', mention_rate: 0.31 },
];

export default function CompetitorListPage() {
  const [_showForm, setShowForm] = useState(false);

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Users2 className="h-7 w-7 text-orange-600" />
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Competitor Monitoring</h1>
            <p className="text-sm text-gray-500">Track competitor mentions in AI assistant responses</p>
          </div>
        </div>
        <button onClick={() => setShowForm(v => !v)} className="flex items-center gap-2 px-4 py-2 bg-orange-600 text-white rounded-lg text-sm hover:bg-orange-700">
          <Plus className="h-4 w-4" /> Add Competitor
        </button>
      </div>

      <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
        <strong>Disclosure:</strong> Mention rates are based on a sample of monitored AI prompt responses and do not represent comprehensive market data or actual search rankings.
      </div>

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Competitor</th>
              <th className="px-4 py-3 text-left">Domain</th>
              <th className="px-4 py-3 text-left">Category</th>
              <th className="px-4 py-3 text-right">Sample Mention Rate</th>
              <th className="px-4 py-3 text-center">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {COMPETITORS.map(c => (
              <tr key={c.id} className="hover:bg-gray-50 cursor-pointer">
                <td className="px-4 py-3 font-medium text-gray-800">{c.name}</td>
                <td className="px-4 py-3 font-mono text-xs text-gray-500">{c.domain}</td>
                <td className="px-4 py-3 text-gray-500">{c.category.replace('_', ' ')}</td>
                <td className="px-4 py-3 text-right font-medium text-gray-800">{(c.mention_rate * 100).toFixed(0)}%</td>
                <td className="px-4 py-3 text-center">
                  <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${c.monitoring_status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                    {c.monitoring_status}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
