export default function PrivacyStatusPage() {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-2">Privacy Status</h1>
      <p className="text-sm text-gray-500 mb-6">
        Personal data controls audit. All visitor data is pseudonymised.
        No raw session tokens or IP addresses are stored.
      </p>
      <div className="bg-white border border-gray-200 rounded-lg p-6">
        <h2 className="font-medium text-gray-900 mb-3">Key Controls</h2>
        <ul className="space-y-2 text-sm text-gray-700">
          <li>✓ Visitor data uses SHA-256 pseudonymised hash</li>
          <li>✓ No raw IP addresses stored</li>
          <li>✓ Attribution identity confidence disclosed in every calculation</li>
          <li>✓ No re-identification path from allocation facts</li>
          <li>✗ GDPR DPIA — deferred to production readiness</li>
          <li>✗ Erasure runbook — deferred to production readiness</li>
        </ul>
      </div>
    </div>
  );
}
