export default function RefreshOutcomePage() {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-2">Refresh Outcomes</h1>
      <p className="text-sm text-gray-500 mb-6">
        Observed post-refresh changes relative to the pre-refresh baseline.
        Results represent observational data only — no causal claims are made.
      </p>

      <div className="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 text-sm text-amber-800">
        <strong>Important:</strong> Outcome data represents observed changes in the
        post-refresh period compared to the pre-refresh baseline. These observations
        are not proof of causation and no revenue is attributed.
      </div>

      <div className="bg-white border border-gray-200 rounded-lg p-8 text-center">
        <p className="text-gray-500 text-sm">
          Outcome windows open automatically 28 days after publication.
          Select a published refresh to view its outcome measurement.
        </p>
      </div>
    </div>
  );
}
