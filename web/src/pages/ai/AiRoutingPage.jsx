import React from 'react';

export default function AiRoutingPage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>AI Model Routing</h1>
        <p className="page-header__subtitle">
          Model routes determine which provider and model handles each task type and content type combination.
        </p>
      </div>

      <div className="card">
        <div className="card__body">
          <p className="muted" style={{ margin: 0, padding: 0 }}>
            Route management UI requires <code>ai_provider.manage</code> permission.
            Use the API at <code>/api/v1/ai/routes</code> to manage routes programmatically.
          </p>
        </div>
      </div>
    </div>
  );
}
