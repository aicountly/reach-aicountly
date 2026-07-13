import React from 'react';

export default function AiRoutingPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold text-gray-900">AI Model Routing</h1>
      <p className="text-sm text-gray-600">
        Model routes determine which provider and model handles each task type and content type combination.
      </p>
      <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
        <p className="text-sm text-gray-500">
          Route management UI requires <code>ai_provider.manage</code> permission.
          Use the API at <code>/api/v1/ai/routes</code> to manage routes programmatically.
        </p>
      </div>
    </div>
  );
}
