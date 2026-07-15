export default function DisasterRecoveryPage() {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-2">Disaster Recovery</h1>
      <p className="text-sm text-gray-500 mb-6">
        DR test evidence. All four test types must pass before release acceptance.
      </p>
      <div className="bg-white border border-gray-200 rounded-lg divide-y divide-gray-100">
        {['backup_verify', 'restore_verify', 'rollback_verify', 'migration_verify'].map((t) => (
          <div key={t} className="p-4 flex items-center justify-between">
            <p className="text-sm font-medium text-gray-700">{t.replace(/_/g, ' ')}</p>
            <span className="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-500">pending</span>
          </div>
        ))}
      </div>
    </div>
  );
}
