export default function SecurityStatusPage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Security Status</h1>
        <p className="page-header__subtitle">
          Open security findings from readiness audit runs. Critical and high findings
          must be resolved or risk-accepted before release.
        </p>
      </div>

      <div className="card">
        <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
          <p className="text-sm text-muted" style={{ margin: 0 }}>No audit runs recorded yet.</p>
        </div>
      </div>
    </div>
  );
}
