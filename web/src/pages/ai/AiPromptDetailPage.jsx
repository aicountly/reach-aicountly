import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import { getPrompt, listPromptVersions, approvePromptVersion } from '../../services/aiService.js';
import { usePermission } from '../../hooks/usePermission.js';

export default function AiPromptDetailPage() {
  const { id } = useParams();
  const { has } = usePermission();
  const [template, setTemplate] = useState(null);
  const [versions, setVersions] = useState([]);
  const [error, setError] = useState(null);
  const [approving, setApproving] = useState(null);

  const canApprove = has('ai_prompt.approve');

  useEffect(() => {
    Promise.all([getPrompt(id), listPromptVersions(id)])
      .then(([tp, vd]) => {
        setTemplate(tp.template || tp);
        setVersions(vd.versions || []);
      })
      .catch(e => setError(e.message));
  }, [id]);

  const handleApprove = async (versionId) => {
    if (!canApprove) return;
    setApproving(versionId);
    try {
      const result = await approvePromptVersion(id, versionId);
      setVersions(prev => prev.map(v => v.id === versionId ? (result.version || v) : v));
      setTemplate(prev => ({ ...prev, status: 'approved', current_version_id: versionId }));
    } catch (e) {
      setError(e.message);
    } finally {
      setApproving(null);
    }
  };

  if (error)     return <div className="text-sm text-red-600 p-4">Error: {error}</div>;
  if (!template) return <div className="text-sm text-gray-500 p-4">Loading…</div>;

  return (
    <div className="space-y-6 max-w-4xl">
      <div>
        <h1 className="text-xl font-bold text-gray-900">{template.name}</h1>
        <p className="text-xs text-gray-500 font-mono">{template.slug}</p>
      </div>

      <div className="grid grid-cols-3 gap-3 text-sm">
        <div><dt className="text-gray-500">Task Type</dt><dd>{template.task_type}</dd></div>
        <div><dt className="text-gray-500">Content Type</dt><dd>{template.content_type || '—'}</dd></div>
        <div><dt className="text-gray-500">Status</dt><dd>{template.status}</dd></div>
      </div>

      <div>
        <h2 className="text-base font-semibold text-gray-800 mb-3">Versions</h2>
        <p className="text-xs text-gray-400 mb-3">
          Prompt versions are immutable after creation. AI cannot approve versions.
        </p>

        {versions.length === 0 ? (
          <p className="text-sm text-gray-500">No versions found.</p>
        ) : (
          <div className="space-y-3">
            {versions.map(v => (
              <div key={v.id} className="border border-gray-200 rounded-lg p-3">
                <div className="flex items-center justify-between mb-2">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium">v{v.version_number}</span>
                    <span className={`text-xs px-1.5 py-0.5 rounded ${v.status === 'approved' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
                      {v.status}
                    </span>
                    {template.current_version_id === v.id && (
                      <span className="text-xs px-1.5 py-0.5 rounded bg-purple-100 text-purple-700">current</span>
                    )}
                  </div>
                  {canApprove && v.status !== 'approved' && (
                    <button
                      onClick={() => handleApprove(v.id)}
                      disabled={approving === v.id}
                      className="text-xs px-2 py-1 bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50"
                    >
                      {approving === v.id ? 'Approving…' : 'Approve'}
                    </button>
                  )}
                </div>
                {v.change_summary && <p className="text-xs text-gray-500">{v.change_summary}</p>}
                <div className="mt-2 grid grid-cols-2 gap-2">
                  <div>
                    <p className="text-xs font-medium text-gray-600 mb-1">System Prompt</p>
                    <pre className="text-xs bg-gray-50 rounded p-2 overflow-auto max-h-20 text-gray-700">{v.system_template?.slice(0, 200)}</pre>
                  </div>
                  <div>
                    <p className="text-xs font-medium text-gray-600 mb-1">User Prompt</p>
                    <pre className="text-xs bg-gray-50 rounded p-2 overflow-auto max-h-20 text-gray-700">{v.user_template?.slice(0, 200)}</pre>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
