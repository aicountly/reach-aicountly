import { useState, useEffect } from 'react';

export default function RefreshPolicyListPage() {
  const [policies, setPolicies] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // TODO: fetch from /api/refresh/policies
    setLoading(false);
  }, []);

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-semibold text-gray-900">Refresh Policies</h1>
        <button className="px-4 py-2 bg-blue-600 text-white rounded-md text-sm hover:bg-blue-700">
          New Policy
        </button>
      </div>

      {loading ? (
        <div className="text-gray-500">Loading…</div>
      ) : policies.length === 0 ? (
        <div className="bg-white border border-gray-200 rounded-lg p-8 text-center">
          <p className="text-gray-500 text-sm">
            No refresh policies defined. Create a policy to start generating
            evidence-based content refresh recommendations.
          </p>
        </div>
      ) : (
        <div className="bg-white border border-gray-200 rounded-lg divide-y divide-gray-100">
          {policies.map((policy) => (
            <div key={policy.uuid} className="p-4 flex items-center justify-between">
              <div>
                <p className="font-medium text-gray-900">{policy.name}</p>
                <p className="text-sm text-gray-500">{policy.content_type}</p>
              </div>
              <span
                className={`px-2 py-0.5 rounded text-xs font-medium ${
                  policy.is_active
                    ? 'bg-green-100 text-green-700'
                    : 'bg-gray-100 text-gray-500'
                }`}
              >
                {policy.is_active ? 'Active' : 'Inactive'}
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
