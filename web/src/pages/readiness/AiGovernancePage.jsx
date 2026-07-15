export default function AiGovernancePage() {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-2">AI Governance</h1>
      <p className="text-sm text-gray-500 mb-6">
        Audit of AI capabilities, controls, and approval chains across Phase 1–9.
      </p>
      <div className="bg-white border border-gray-200 rounded-lg p-6">
        <h2 className="font-medium text-gray-900 mb-3">Governance Controls</h2>
        <ul className="space-y-2 text-sm text-gray-700">
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
  );
}
