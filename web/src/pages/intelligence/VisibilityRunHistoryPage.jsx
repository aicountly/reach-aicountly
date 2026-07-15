import { History, CheckCircle, XCircle, Clock } from 'lucide-react';

const RUNS = [
  { id: 1, prompt: '"Best accounting software" (India)', model: 'gpt-4o', status: 'completed', cost_cents: 42, mentioned: true, ran_at: '2026-07-15T07:30:00Z' },
  { id: 2, prompt: '"GST filing tools"', model: 'gpt-4o', status: 'completed', cost_cents: 38, mentioned: true, ran_at: '2026-07-14T18:00:00Z' },
  { id: 3, prompt: 'Startup bookkeeping tools', model: 'gpt-4o-mini', status: 'completed', cost_cents: 12, mentioned: false, ran_at: '2026-07-14T12:00:00Z' },
  { id: 4, prompt: 'Invoice management India', model: 'gpt-4o', status: 'failed', cost_cents: 0, mentioned: null, ran_at: '2026-07-13T20:00:00Z' },
];

export default function VisibilityRunHistoryPage() {
  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center gap-3">
        <History className="h-7 w-7 text-purple-600" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Visibility Run History</h1>
          <p className="text-sm text-gray-500">Execution log with immutable raw AI responses</p>
        </div>
      </div>
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Prompt</th>
              <th className="px-4 py-3 text-left">Model</th>
              <th className="px-4 py-3 text-left">Status</th>
              <th className="px-4 py-3 text-left">Brand</th>
              <th className="px-4 py-3 text-right">Cost</th>
              <th className="px-4 py-3 text-left">Ran At</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {RUNS.map(r => (
              <tr key={r.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 text-gray-700 max-w-xs truncate">{r.prompt}</td>
                <td className="px-4 py-3 font-mono text-xs text-gray-500">{r.model}</td>
                <td className="px-4 py-3">
                  <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ${r.status === 'completed' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                    {r.status === 'completed' ? <CheckCircle className="h-3 w-3" /> : <XCircle className="h-3 w-3" />}
                    {r.status}
                  </span>
                </td>
                <td className="px-4 py-3">
                  {r.mentioned === true && <CheckCircle className="h-4 w-4 text-green-500" />}
                  {r.mentioned === false && <XCircle className="h-4 w-4 text-red-400" />}
                  {r.mentioned === null && <span className="text-gray-300 text-xs">—</span>}
                </td>
                <td className="px-4 py-3 text-right text-gray-600">{r.cost_cents > 0 ? `¢${r.cost_cents}` : '—'}</td>
                <td className="px-4 py-3 text-gray-400 text-xs">{new Date(r.ran_at).toLocaleString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
