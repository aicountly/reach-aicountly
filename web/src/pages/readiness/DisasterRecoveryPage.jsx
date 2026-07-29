export default function DisasterRecoveryPage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Disaster Recovery</h1>
        <p className="page-header__subtitle">
          DR test evidence. All four test types must pass before release acceptance.
        </p>
      </div>

      <div className="card">
        {['backup_verify', 'restore_verify', 'rollback_verify', 'migration_verify'].map((t) => (
          <div
            key={t}
            className="card__body section-header"
            style={{ borderBottom: '1px solid var(--color-border)' }}
          >
            <p className="text-sm" style={{ fontWeight: 600, margin: 0, textTransform: 'capitalize' }}>
              {t.replace(/_/g, ' ')}
            </p>
            <span className="badge badge--muted">pending</span>
          </div>
        ))}
      </div>
    </div>
  );
}
