export default function ReleaseAcceptancePage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Release Acceptance</h1>
        <p className="page-header__subtitle">
          Final go/no-go decision record. Release acceptance may only be created when
          all critical and high findings are resolved or risk-accepted, all DR tests
          pass, and all operational readiness checks are confirmed.
        </p>
      </div>

      <div className="alert alert-warning mb-4">
        <strong>Not yet accepted.</strong> Complete all prerequisite checks in the
        Security, DR, Operations, and Technical Debt sections before creating an
        acceptance record.
      </div>

      <div className="card">
        <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
          <p className="text-sm text-muted" style={{ margin: '0 0 0.75rem' }}>
            No release acceptance record has been created yet.
          </p>
          <p className="text-sm text-muted" style={{ margin: 0, fontSize: '0.75rem' }}>
            Prerequisites: security findings cleared or risk-accepted · DR tests passing ·
            operations checks confirmed · technical debt reviewed.
          </p>
        </div>
      </div>
    </div>
  );
}
