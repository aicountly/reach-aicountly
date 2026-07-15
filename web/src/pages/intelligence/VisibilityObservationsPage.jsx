import { Telescope, CheckCircle, XCircle, AlertCircle } from 'lucide-react';

const OBSERVATIONS = [
  { id: 1, prompt: '"Best accounting software"', model: 'gpt-4o', entity: 'AiCountly', coverage: 'mentioned', citation_url: null, ran_at: '2026-07-15T07:30:00Z' },
  { id: 2, prompt: '"Best accounting software"', model: 'gpt-4o', entity: 'QuickBooks India', coverage: 'mentioned', citation_url: null, ran_at: '2026-07-15T07:30:00Z' },
  { id: 3, prompt: '"GST filing tools"', model: 'gpt-4o', entity: 'AiCountly', coverage: 'not_mentioned', citation_url: null, ran_at: '2026-07-14T18:00:00Z' },
  { id: 4, prompt: 'Startup bookkeeping tools', model: 'gpt-4o-mini', entity: 'AiCountly', coverage: 'mentioned', citation_url: 'https://aicountly.com/features', ran_at: '2026-07-14T12:00:00Z' },
];

const CoverageIcon = ({ coverage }) => {
  if (coverage === 'mentioned') return <CheckCircle className="h-4 w-4 text-green-500" />;
  if (coverage === 'not_mentioned') return <XCircle className="h-4 w-4 text-gray-400" />;
  return <AlertCircle className="h-4 w-4 text-yellow-500" />;
};

export default function VisibilityObservationsPage() {
  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center gap-3">
        <Telescope className="h-7 w-7 text-purple-600" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">AI Visibility Observations</h1>
          <p className="text-sm text-gray-500">Raw observation records from AI visibility runs</p>
        </div>
      </div>

      <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
        <strong>Sample scope disclosure:</strong> Observations represent a sampled set of AI model responses. They do not represent comprehensive query volumes, live search rankings, or real-time brand awareness.
      </div>

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Prompt</th>
              <th className="px-4 py-3 text-left">Model</th>
              <th className="px-4 py-3 text-left">Entity</th>
              <th className="px-4 py-3 text-left">Coverage</th>
              <th className="px-4 py-3 text-left">Citation</th>
              <th className="px-4 py-3 text-left">Ran At</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {OBSERVATIONS.map(o => (
              <tr key={o.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 text-gray-700 max-w-xs truncate">{o.prompt}</td>
                <td className="px-4 py-3 font-mono text-xs text-gray-500">{o.model}</td>
                <td className="px-4 py-3 font-medium text-gray-800">{o.entity}</td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5">
                    <CoverageIcon coverage={o.coverage} />
                    <span className="text-sm text-gray-600 capitalize">{o.coverage.replace('_', ' ')}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-xs text-blue-600 truncate max-w-xs">
                  {o.citation_url ? <a href={o.citation_url} target="_blank" rel="noreferrer">{o.citation_url}</a> : <span className="text-gray-400">—</span>}
                </td>
                <td className="px-4 py-3 text-gray-400 text-xs">{new Date(o.ran_at).toLocaleString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
