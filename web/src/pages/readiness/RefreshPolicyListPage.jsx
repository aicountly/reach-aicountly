import { useState, useEffect } from 'react';

export default function RefreshPolicyListPage() {
  const [policies, _setPolicies] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // TODO: fetch from /api/refresh/policies
    setLoading(false);
  }, []);

  return (
    <div>
      <div className="page-header">
        <h1>Refresh Policies</h1>
        <div className="page-header__actions">
          <button type="button" className="btn btn--sm btn--primary">New Policy</button>
        </div>
      </div>

      {loading ? (
        <p className="muted">Loading…</p>
      ) : policies.length === 0 ? (
        <div className="card">
          <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No refresh policies defined. Create a policy to start generating
              evidence-based content refresh recommendations.
            </p>
          </div>
        </div>
      ) : (
        <div className="card">
          {policies.map((policy) => (
            <div
              key={policy.uuid}
              className="card__body section-header"
              style={{ borderBottom: '1px solid var(--color-border)' }}
            >
              <div>
                <p style={{ fontWeight: 600, margin: 0 }}>{policy.name}</p>
                <p className="text-sm text-muted" style={{ margin: '0.25rem 0 0' }}>{policy.content_type}</p>
              </div>
              <span className={`badge ${policy.is_active ? 'badge--success' : 'badge--muted'}`}>
                {policy.is_active ? 'Active' : 'Inactive'}
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
