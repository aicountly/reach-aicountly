import { useState } from 'react';

export default function RefreshEvidencePage() {
  const [_contentId, setContentId] = useState('');

  return (
    <div>
      <div className="page-header">
        <h1>Evidence Snapshots</h1>
      </div>

      <div className="card mb-4">
        <div className="card__body">
          <label className="text-sm" style={{ display: 'block', fontWeight: 600, marginBottom: '0.35rem' }}>
            Content Identity ID
          </label>
          <div className="btn-group" style={{ width: '100%' }}>
            <input
              type="text"
              style={{ flex: 1, minWidth: 0 }}
              placeholder="Enter content identity UUID or ID"
              onChange={(e) => setContentId(e.target.value)}
            />
            <button type="button" className="btn btn--sm btn--primary">Load Snapshots</button>
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
          <p className="text-sm text-muted" style={{ margin: 0 }}>
            Enter a content identity to view its evidence snapshots.
            Snapshots are immutable — they record the exact evidence used
            to generate each recommendation.
          </p>
        </div>
      </div>
    </div>
  );
}
