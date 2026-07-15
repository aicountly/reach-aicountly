import { useState, useEffect } from 'react';

const RISK_COLOURS = {
  critical: 'bg-red-100 text-red-700',
  high:     'bg-orange-100 text-orange-700',
  medium:   'bg-yellow-100 text-yellow-700',
  low:      'bg-green-100 text-green-700',
};

export default function RecommendationBacklogPage() {
  const [recommendations, _setRecommendations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('recommended');

  useEffect(() => {
    // TODO: fetch from /api/refresh/recommendations?status=filter
    setLoading(false);
  }, [filter]);

  const statusTabs = ['recommended', 'triaged', 'accepted', 'deferred'];

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-semibold text-gray-900">Refresh Recommendation Backlog</h1>
      </div>

      <div className="flex gap-2 mb-4 border-b border-gray-200">
        {statusTabs.map((s) => (
          <button
            key={s}
            onClick={() => setFilter(s)}
            className={`px-4 py-2 text-sm capitalize -mb-px border-b-2 transition-colors ${
              filter === s
                ? 'border-blue-600 text-blue-600 font-medium'
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            {s}
          </button>
        ))}
      </div>

      {loading ? (
        <div className="text-gray-500 text-sm">Loading…</div>
      ) : recommendations.length === 0 ? (
        <div className="bg-white border border-gray-200 rounded-lg p-8 text-center">
          <p className="text-gray-500 text-sm">
            No {filter} recommendations. Run the content refresh detection job to generate recommendations.
          </p>
        </div>
      ) : (
        <div className="bg-white border border-gray-200 rounded-lg divide-y divide-gray-100">
          {recommendations.map((rec) => (
            <div key={rec.uuid} className="p-4">
              <div className="flex items-start justify-between gap-4">
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-gray-900 truncate">{rec.content_identity_id}</p>
                  <p className="text-xs text-gray-500 mt-0.5">
                    Confidence: {(rec.confidence * 100).toFixed(0)}% ·
                    Effort: {rec.effort_estimate}
                  </p>
                </div>
                <span className={`px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap ${RISK_COLOURS[rec.risk_classification] ?? 'bg-gray-100 text-gray-600'}`}>
                  {rec.risk_classification}
                </span>
              </div>
              {rec.triage_notes && (
                <p className="text-xs text-gray-500 mt-2 italic">{rec.triage_notes}</p>
              )}
              <div className="flex gap-2 mt-3">
                <button className="text-xs px-3 py-1 bg-blue-50 text-blue-700 rounded hover:bg-blue-100">
                  Accept
                </button>
                <button className="text-xs px-3 py-1 bg-gray-50 text-gray-700 rounded hover:bg-gray-100">
                  Defer
                </button>
                <button className="text-xs px-3 py-1 bg-red-50 text-red-700 rounded hover:bg-red-100">
                  Reject
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
