import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { listPrompts } from '../../services/aiService.js';

const STATUS_BADGE = {
  approved:    'bg-green-100 text-green-700',
  draft:       'bg-yellow-100 text-yellow-700',
  needs_review:'bg-blue-100 text-blue-700',
  rejected:    'bg-red-100 text-red-700',
  deprecated:  'bg-gray-100 text-gray-500',
};

export default function AiPromptsPage() {
  const [templates, setTemplates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    listPrompts()
      .then(d => setTemplates(d.templates || []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="text-sm text-gray-500 p-4">Loading prompts…</div>;
  if (error)   return <div className="text-sm text-red-600 p-4">Error: {error}</div>;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold text-gray-900">Prompt Templates</h1>
      </div>
      <p className="text-xs text-gray-500">
        Prompt versions are immutable after creation. Only approved versions are used for generation.
        Approval requires <code>ai_prompt.approve</code> permission.
      </p>
      {templates.length === 0 ? (
        <p className="text-sm text-gray-500">No prompt templates found.</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                {['Name', 'Slug', 'Task Type', 'Content Type', 'Status', 'Actions'].map(h => (
                  <th key={h} className="px-3 py-2 text-left font-medium text-gray-600">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {templates.map(t => (
                <tr key={t.id} className="hover:bg-gray-50">
                  <td className="px-3 py-2 font-medium text-gray-800">{t.name}</td>
                  <td className="px-3 py-2 font-mono text-xs text-gray-600">{t.slug}</td>
                  <td className="px-3 py-2 text-gray-600">{t.task_type}</td>
                  <td className="px-3 py-2 text-gray-600">{t.content_type || '—'}</td>
                  <td className="px-3 py-2">
                    <span className={`text-xs px-1.5 py-0.5 rounded ${STATUS_BADGE[t.status] || 'bg-gray-100 text-gray-600'}`}>
                      {t.status}
                    </span>
                  </td>
                  <td className="px-3 py-2">
                    <Link to={`/ai/prompts/${t.id}`} className="text-xs text-blue-600 hover:underline">View</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
