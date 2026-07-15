import { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';

const STATUS_ORDER = [
  'accepted', 'brief_prepared', 'draft_generating', 'draft_ready',
  'in_review', 'approved', 'publish_queued', 'published', 'monitoring', 'outcome_recorded',
];

function StatusBadge({ status }) {
  const terminal = ['rejected', 'cancelled', 'withdrawn', 'outcome_recorded', 'failed'].includes(status);
  const active = ['in_review', 'approved'].includes(status);
  return (
    <span className={`px-2 py-0.5 rounded text-xs font-medium ${
      terminal ? 'bg-gray-100 text-gray-500' :
      active   ? 'bg-blue-100 text-blue-700' :
                 'bg-green-100 text-green-700'
    }`}>
      {status.replace(/_/g, ' ')}
    </span>
  );
}

function ProgressSteps({ currentStatus }) {
  const idx = STATUS_ORDER.indexOf(currentStatus);
  return (
    <div className="flex items-center gap-1 flex-wrap mb-6">
      {STATUS_ORDER.map((s, i) => (
        <div key={s} className="flex items-center">
          <div className={`w-2 h-2 rounded-full ${i < idx ? 'bg-green-500' : i === idx ? 'bg-blue-600' : 'bg-gray-200'}`} />
          <span className={`text-xs ml-1 mr-2 ${i === idx ? 'text-blue-600 font-medium' : 'text-gray-400'}`}>
            {s.replace(/_/g, ' ')}
          </span>
          {i < STATUS_ORDER.length - 1 && <span className="text-gray-200 mr-1">›</span>}
        </div>
      ))}
    </div>
  );
}

export default function RefreshWorkspacePage() {
  const { id } = useParams();
  const [workflow, setWorkflow] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // TODO: fetch from /api/refresh/workflows/:id
    setLoading(false);
  }, [id]);

  if (loading) return <div className="p-6 text-gray-500">Loading…</div>;
  if (! workflow) return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-4">Refresh Workspace</h1>
      <div className="bg-white border border-gray-200 rounded-lg p-8 text-center">
        <p className="text-gray-500 text-sm">
          Enter a workflow ID in the URL to view a refresh workspace, or select a
          recommendation from the backlog to create a workflow.
        </p>
      </div>
    </div>
  );

  return (
    <div className="p-6">
      <div className="flex items-center gap-3 mb-4">
        <h1 className="text-2xl font-semibold text-gray-900">Refresh Workspace</h1>
        <StatusBadge status={workflow.status} />
      </div>

      <ProgressSteps currentStatus={workflow.status} />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div className="bg-white border border-gray-200 rounded-lg p-4">
          <h2 className="font-medium text-gray-900 mb-2">Objective</h2>
          <p className="text-sm text-gray-600">{workflow.refresh_objective}</p>
        </div>

        <div className="bg-white border border-gray-200 rounded-lg p-4">
          <h2 className="font-medium text-gray-900 mb-2">Evidence Snapshot</h2>
          <p className="text-sm text-gray-500">
            Completeness: —
          </p>
        </div>

        <div className="bg-white border border-gray-200 rounded-lg p-4 lg:col-span-2">
          <h2 className="font-medium text-gray-900 mb-2">Brief</h2>
          <p className="text-sm text-gray-500">No brief prepared yet.</p>
        </div>
      </div>
    </div>
  );
}
