import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { getAiProvider } from '../../services/aiService.js';
import { ROUTES } from '../../constants/routes.js';

export default function AiProvidersDetailPage() {
  const { id } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    getAiProvider(id)
      .then(d => setData(d.provider || d))
      .catch(e => setError(e.message));
  }, [id]);

  if (error) return <p className="text-error">Error: {error}</p>;
  if (!data)  return <p className="muted">Loading…</p>;

  return (
    <div>
      <div className="page-header">
        <div>
          <p className="text-xs text-muted mb-2">
            <Link to={ROUTES.AI_PROVIDERS}>← Providers</Link>
          </p>
          <h1>{data.display_name}</h1>
        </div>
      </div>

      <div className="card mb-3" style={{ maxWidth: 640 }}>
        <div className="card__body">
          <dl className="definition-list" style={{ margin: 0 }}>
            <dt>Provider Key</dt>
            <dd><code>{data.provider_key}</code></dd>
            <dt>Status</dt>
            <dd>{data.status}</dd>
            <dt>Config Status</dt>
            <dd>{data.configuration_status}</dd>
            <dt>Last Health</dt>
            <dd>{data.last_health_status || '—'}</dd>
            <dt>Structured Output</dt>
            <dd>{data.supports_structured_output ? 'Yes' : 'No'}</dd>
            <dt>Tool Calls</dt>
            <dd>{data.supports_tool_calls ? 'Yes' : 'No'}</dd>
          </dl>
        </div>
      </div>

      <div className="alert alert--warning" style={{ maxWidth: 640 }}>
        API keys are configured via environment variables and are never shown in this interface.
      </div>
    </div>
  );
}
