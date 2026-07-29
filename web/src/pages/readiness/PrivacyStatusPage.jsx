export default function PrivacyStatusPage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Privacy Status</h1>
        <p className="page-header__subtitle">
          Personal data controls audit. All visitor data is pseudonymised.
          No raw session tokens or IP addresses are stored.
        </p>
      </div>

      <div className="card">
        <div className="card__header">Key Controls</div>
        <div className="card__body">
          <ul className="text-sm" style={{ margin: 0, paddingLeft: '1.1rem', lineHeight: 1.7 }}>
            <li>✓ Visitor data uses SHA-256 pseudonymised hash</li>
            <li>✓ No raw IP addresses stored</li>
            <li>✓ Attribution identity confidence disclosed in every calculation</li>
            <li>✓ No re-identification path from allocation facts</li>
            <li>✗ GDPR DPIA — deferred to production readiness</li>
            <li>✗ Erasure runbook — deferred to production readiness</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
