export default function TechnicalDebtPage() {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-2">Technical Debt</h1>
      <p className="text-sm text-gray-500 mb-6">
        Classified technical debt items. Critical and high blockers must be resolved
        or formally accepted before release.
      </p>
      <div className="bg-white border border-gray-200 rounded-lg p-8 text-center">
        <p className="text-gray-500 text-sm">No technical debt records created yet.</p>
      </div>
    </div>
  );
}
