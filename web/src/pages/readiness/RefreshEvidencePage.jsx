import { useState } from 'react';

export default function RefreshEvidencePage() {
  const [_contentId, setContentId] = useState('');

  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-6">Evidence Snapshots</h1>
      <div className="bg-white border border-gray-200 rounded-lg p-6 mb-6">
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Content Identity ID
        </label>
        <div className="flex gap-2">
          <input
            type="text"
            className="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm"
            placeholder="Enter content identity UUID or ID"
            onChange={(e) => setContentId(e.target.value)}
          />
          <button className="px-4 py-2 bg-blue-600 text-white rounded-md text-sm hover:bg-blue-700">
            Load Snapshots
          </button>
        </div>
      </div>
      <div className="bg-white border border-gray-200 rounded-lg p-8 text-center">
        <p className="text-gray-500 text-sm">
          Enter a content identity to view its evidence snapshots.
          Snapshots are immutable — they record the exact evidence used
          to generate each recommendation.
        </p>
      </div>
    </div>
  );
}
