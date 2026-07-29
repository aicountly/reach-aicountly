export default function RefreshOutcomePage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Refresh Outcomes</h1>
        <p className="page-header__subtitle">
          Observed post-refresh changes relative to the pre-refresh baseline.
          Results represent observational data only — no causal claims are made.
        </p>
      </div>

      <div className="alert alert-warning mb-4">
        <strong>Important:</strong> Outcome data represents observed changes in the
        post-refresh period compared to the pre-refresh baseline. These observations
        are not proof of causation and no revenue is attributed.
      </div>

      <div className="card">
        <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
          <p className="text-sm text-muted" style={{ margin: 0 }}>
            Outcome windows open automatically 28 days after publication.
            Select a published refresh to view its outcome measurement.
          </p>
        </div>
      </div>
    </div>
  );
}
