import { Plug, CheckCircle, XCircle, AlertCircle, Settings } from 'lucide-react';

const CONNECTORS = [
  { id: 1, provider: 'gsc', display_name: 'Google Search Console', health_status: 'healthy', enabled: true, last_health_check: '2026-07-15T07:00:00Z' },
  { id: 2, provider: 'ga4', display_name: 'GA4 Content Analytics', health_status: 'healthy', enabled: true, last_health_check: '2026-07-15T07:00:00Z' },
  { id: 3, provider: 'indexnow', display_name: 'IndexNow', health_status: 'unknown', enabled: false, last_health_check: null },
];

const HealthIcon = ({ status }) => {
  if (status === 'healthy')  return <CheckCircle className="h-4 w-4 text-green-500" />;
  if (status === 'failing')  return <XCircle className="h-4 w-4 text-red-500" />;
  return <AlertCircle className="h-4 w-4 text-yellow-500" />;
};

export default function ConnectorConfigPage() {
  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center gap-3">
        <Plug className="h-7 w-7 text-gray-700" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Connectors</h1>
          <p className="text-sm text-gray-500">Manage analytics and submission connector configurations</p>
        </div>
      </div>

      <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800">
        <strong>Security:</strong> Credentials are stored as environment references only. Raw API keys are never persisted in the database.
      </div>

      <div className="space-y-4">
        {CONNECTORS.map(c => (
          <div key={c.id} className="bg-white rounded-xl border border-gray-200 p-5 shadow-sm flex items-center justify-between">
            <div className="flex items-center gap-4">
              <div className="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center">
                <Settings className="h-5 w-5 text-gray-500" />
              </div>
              <div>
                <p className="font-semibold text-gray-800">{c.display_name}</p>
                <p className="text-xs text-gray-400 font-mono">{c.provider}</p>
              </div>
            </div>
            <div className="flex items-center gap-4">
              <div className="flex items-center gap-1.5 text-sm">
                <HealthIcon status={c.health_status} />
                <span className="text-gray-600 capitalize">{c.health_status}</span>
              </div>
              <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${c.enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                {c.enabled ? 'Enabled' : 'Disabled'}
              </span>
              {c.last_health_check && (
                <span className="text-xs text-gray-400">
                  Checked {new Date(c.last_health_check).toLocaleTimeString()}
                </span>
              )}
              <button className="text-xs text-blue-600 hover:text-blue-800">Configure</button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
