export default function SecurityStatusPage() {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-2">Security Status</h1>
      <p className="text-sm text-gray-500 mb-6">
        Open security findings from readiness audit runs. Critical and high findings
        must be resolved or risk-accepted before release.
      </p>
      <div className="bg-white border border-gray-200 rounded-lg p-8 text-center">
        <p className="text-gray-500 text-sm">No audit runs recorded yet.</p>
      </div>
    </div>
  );
}
