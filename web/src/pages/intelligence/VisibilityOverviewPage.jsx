import { Eye, Zap, CheckCircle, XCircle } from 'lucide-react';

export default function VisibilityOverviewPage() {
  const cards = [
    { label: 'Active Prompts', value: '4', color: 'text-blue-600' },
    { label: 'Runs (30 days)', value: '28', color: 'text-purple-600' },
    { label: 'Brand Mentions', value: '22', color: 'text-green-600' },
    { label: 'Not Mentioned', value: '6', color: 'text-red-600' },
  ];

  const recentRuns = [
    { id: 1, prompt: '"Best accounting software" (India)', status: 'completed', mentioned: true, ran_at: '2026-07-15T07:30:00Z' },
    { id: 2, prompt: '"GST filing tools"', status: 'completed', mentioned: true, ran_at: '2026-07-14T18:00:00Z' },
    { id: 3, prompt: '"Bookkeeping software for startups"', status: 'completed', mentioned: false, ran_at: '2026-07-14T12:00:00Z' },
    { id: 4, prompt: '"Invoice management India"', status: 'failed', mentioned: null, ran_at: '2026-07-13T20:00:00Z' },
  ];

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center gap-3">
        <Eye className="h-7 w-7 text-purple-600" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">AI Visibility Monitoring</h1>
          <p className="text-sm text-gray-500">Track how AI assistants mention your brand</p>
        </div>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {cards.map(c => (
          <div key={c.label} className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm text-center">
            <p className={`text-3xl font-bold ${c.color}`}>{c.value}</p>
            <p className="text-xs text-gray-500 mt-1">{c.label}</p>
          </div>
        ))}
      </div>

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
          <h2 className="text-base font-semibold text-gray-800">Recent Visibility Runs</h2>
          <button className="flex items-center gap-1 text-xs bg-purple-600 text-white px-3 py-1.5 rounded-lg hover:bg-purple-700">
            <Zap className="h-3 w-3" /> Run Now
          </button>
        </div>
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Prompt</th>
              <th className="px-4 py-3 text-left">Status</th>
              <th className="px-4 py-3 text-left">Brand Mentioned</th>
              <th className="px-4 py-3 text-left">Ran At</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {recentRuns.map(r => (
              <tr key={r.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 text-gray-700">{r.prompt}</td>
                <td className="px-4 py-3">
                  <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${r.status === 'completed' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                    {r.status}
                  </span>
                </td>
                <td className="px-4 py-3">
                  {r.mentioned === true && <CheckCircle className="h-4 w-4 text-green-500" />}
                  {r.mentioned === false && <XCircle className="h-4 w-4 text-red-500" />}
                  {r.mentioned === null && <span className="text-gray-300 text-xs">—</span>}
                </td>
                <td className="px-4 py-3 text-gray-400 text-xs">{new Date(r.ran_at).toLocaleString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
        <strong>Governance:</strong> All prompts must be explicitly approved before execution. Raw AI responses are stored immutably for audit purposes. Budget limits are enforced per run.
      </div>
    </div>
  );
}
