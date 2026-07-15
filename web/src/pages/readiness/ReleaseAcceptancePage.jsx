export default function ReleaseAcceptancePage() {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-2">Release Acceptance</h1>
      <p className="text-sm text-gray-500 mb-6">
        Final go/no-go decision record. Release acceptance may only be created when
        all critical and high findings are resolved or risk-accepted, all DR tests
        pass, and all operational readiness checks are confirmed.
      </p>
      <div className="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 text-sm text-amber-800">
        <strong>Not yet accepted.</strong> Complete all prerequisite checks in the
        Security, DR, Operations, and Technical Debt sections before creating an
        acceptance record.
      </div>
      <div className="bg-white border border-gray-200 rounded-lg p-8 text-center">
        <p className="text-gray-500 text-sm">No release acceptance record created.</p>
      </div>
    </div>
  );
}
