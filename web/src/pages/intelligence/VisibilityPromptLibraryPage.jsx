import { useState } from 'react';
import { BookOpen, Plus, CheckCircle, Circle } from 'lucide-react';

const SAMPLE_PROMPTS = [
  { id: 1, name: '"Best accounting software" (India)', topic: 'Brand awareness', status: 'active', locale: 'en', schedule_cron: '0 6 * * 1' },
  { id: 2, name: '"GST filing tools" query', topic: 'Product discovery', status: 'active', locale: 'en', schedule_cron: '0 6 * * 3' },
  { id: 3, name: 'Startup bookkeeping tools', topic: 'Segment targeting', status: 'draft', locale: 'en', schedule_cron: null },
  { id: 4, name: 'Invoice management India', topic: 'Feature discovery', status: 'paused', locale: 'en', schedule_cron: '0 6 * * 5' },
];

export default function VisibilityPromptLibraryPage() {
  const [showForm, setShowForm] = useState(false);

  const statusColors = { active: 'bg-green-100 text-green-700', draft: 'bg-gray-100 text-gray-600', paused: 'bg-yellow-100 text-yellow-700' };

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <BookOpen className="h-7 w-7 text-purple-600" />
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Visibility Prompt Library</h1>
            <p className="text-sm text-gray-500">Governed AI visibility monitoring prompts</p>
          </div>
        </div>
        <button
          onClick={() => setShowForm(v => !v)}
          className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm"
        >
          <Plus className="h-4 w-4" /> New Prompt
        </button>
      </div>

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Prompt Name</th>
              <th className="px-4 py-3 text-left">Topic</th>
              <th className="px-4 py-3 text-left">Status</th>
              <th className="px-4 py-3 text-left">Schedule</th>
              <th className="px-4 py-3 text-center">Active Version</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {SAMPLE_PROMPTS.map(p => (
              <tr key={p.id} className="hover:bg-gray-50 cursor-pointer">
                <td className="px-4 py-3 font-medium text-gray-800">{p.name}</td>
                <td className="px-4 py-3 text-gray-500">{p.topic}</td>
                <td className="px-4 py-3">
                  <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${statusColors[p.status]}`}>{p.status}</span>
                </td>
                <td className="px-4 py-3 font-mono text-xs text-gray-400">{p.schedule_cron ?? 'manual'}</td>
                <td className="px-4 py-3 text-center">
                  {p.status === 'active' ? <CheckCircle className="h-4 w-4 text-green-500 mx-auto" /> : <Circle className="h-4 w-4 text-gray-300 mx-auto" />}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="bg-purple-50 border border-purple-200 rounded-lg p-4 text-sm text-purple-800">
        <strong>Immutability guarantee:</strong> Once a prompt version is approved, its text cannot be modified. Changes create a new version requiring fresh approval.
      </div>
    </div>
  );
}
