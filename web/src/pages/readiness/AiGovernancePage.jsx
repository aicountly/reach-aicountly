export default function AiGovernancePage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>AI Governance</h1>
        <p className="page-header__subtitle">
          Audit of AI capabilities, controls, and approval chains across Phase 1–9.
        </p>
      </div>

      <div className="card">
        <div className="card__header">Governance Controls</div>
        <div className="card__body">
          <ul className="text-sm" style={{ margin: 0, paddingLeft: '1.1rem', lineHeight: 1.7 }}>
            <li>✓ AI cannot approve its own generated content</li>
            <li>✓ All generation within approved output schemas</li>
            <li>✓ Budget enforcement with hard-limit circuit breaker</li>
            <li>✓ Grounding to product sources before generation</li>
            <li>✓ Disclosure required in all AI-generated content</li>
            <li>✓ Immutable generation artifact storage</li>
            <li>✓ Claim validation post-generation</li>
            <li>✓ Refresh generation requires disclosure + sources</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
