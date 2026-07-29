import { useState, useEffect } from 'react';

const RISK_BADGE = {
  critical: 'badge badge--danger',
  high:     'badge badge--warning',
  medium:   'badge badge--info',
  low:      'badge badge--success',
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
    <div>
      <div className="page-header">
        <h1>Refresh Recommendation Backlog</h1>
      </div>

      <div className="btn-group mb-4" role="tablist" aria-label="Recommendation status filter">
        {statusTabs.map((s) => (
          <button
            key={s}
            type="button"
            role="tab"
            aria-selected={filter === s}
            onClick={() => setFilter(s)}
            className={`btn btn--sm ${filter === s ? 'btn--primary' : 'btn--secondary'}`}
            style={{ textTransform: 'capitalize' }}
          >
            {s}
          </button>
        ))}
      </div>

      {loading ? (
        <p className="muted">Loading…</p>
      ) : recommendations.length === 0 ? (
        <div className="card">
          <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No {filter} recommendations. Run the content refresh detection job to generate recommendations.
            </p>
          </div>
        </div>
      ) : (
        <div className="card">
          {recommendations.map((rec) => (
            <div key={rec.uuid} className="card__body" style={{ borderBottom: '1px solid var(--color-border)' }}>
              <div className="section-header">
                <div style={{ minWidth: 0, flex: 1 }}>
                  <p style={{ fontWeight: 600, margin: 0 }}>{rec.content_identity_id}</p>
                  <p className="text-sm text-muted" style={{ margin: '0.25rem 0 0' }}>
                    Confidence: {(rec.confidence * 100).toFixed(0)}% ·
                    Effort: {rec.effort_estimate}
                  </p>
                </div>
                <span className={RISK_BADGE[rec.risk_classification] ?? 'badge badge--muted'}>
                  {rec.risk_classification}
                </span>
              </div>
              {rec.triage_notes && (
                <p className="text-sm text-muted" style={{ margin: '0.5rem 0 0', fontStyle: 'italic' }}>
                  {rec.triage_notes}
                </p>
              )}
              <div className="btn-group" style={{ marginTop: '0.75rem' }}>
                <button type="button" className="btn btn--sm btn--primary">Accept</button>
                <button type="button" className="btn btn--sm btn--secondary">Defer</button>
                <button type="button" className="btn btn--sm btn--danger">Reject</button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
