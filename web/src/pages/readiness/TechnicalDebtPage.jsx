export default function TechnicalDebtPage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Technical Debt</h1>
        <p className="page-header__subtitle">
          Classified technical debt items. Critical and high blockers must be resolved
          or formally accepted before release.
        </p>
      </div>

      <div className="card">
        <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
          <p className="text-sm text-muted" style={{ margin: 0 }}>No technical debt records created yet.</p>
        </div>
      </div>
    </div>
  );
}
