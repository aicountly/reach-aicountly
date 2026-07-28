export default function ReleaseAcceptancePage() {
  return (
    <div style={{ padding: '1.5rem' }}>
      <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: '0 0 0.35rem' }}>
        Release Acceptance
      </h1>
      <p className="text-sm text-muted" style={{ marginBottom: '1.25rem', maxWidth: 720 }}>
        Final go/no-go decision record. Release acceptance may only be created when
        all critical and high findings are resolved or risk-accepted, all DR tests
        pass, and all operational readiness checks are confirmed.
      </p>

      <div className="alert alert-warning">
        <strong>Not yet accepted.</strong> Complete all prerequisite checks in the
        Security, DR, Operations, and Technical Debt sections before creating an
        acceptance record.
      </div>

      <div className="card">
        <div className="card-body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
          <p className="text-sm text-muted" style={{ margin: '0 0 0.75rem' }}>
            No release acceptance record has been created yet.
          </p>
          <p className="text-xs text-muted" style={{ margin: 0 }}>
            Prerequisites: security findings cleared or risk-accepted · DR tests passing ·
            operations checks confirmed · technical debt reviewed.
          </p>
        </div>
      </div>
    </div>
  );
}
