import { useState } from 'react';
import api from '../../services/api';

const STATUS_CLASS = {
  ready: 'badge--success',
  warning: 'badge--warning',
  blocked: 'badge--error',
  not_applicable: 'badge--neutral',
  unknown: 'badge--neutral',
};

export default function ReadinessPage() {
  const [contentId, setContentId] = useState('');
  const [result, setResult] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const check = async () => {
    if (!contentId) return;
    setLoading(true);
    setError(null);
    setResult(null);
    try {
      const r = await api.get(`/publishing/readiness/${contentId}`);
      setResult(r.data?.data ?? null);
    } catch (e) {
      setError(e.response?.data?.message ?? e.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      <div className="page-header">
        <h1>Publication Readiness Check</h1>
      </div>

      <div className="form-row">
        <label htmlFor="content-id" className="label">Content Item ID</label>
        <input
          id="content-id"
          type="number"
          className="input"
          value={contentId}
          onChange={e => setContentId(e.target.value)}
          placeholder="e.g. 123"
        />
        <button className="btn" onClick={check} disabled={loading || !contentId}>
          {loading ? 'Checking…' : 'Check Readiness'}
        </button>
      </div>

      {error && <p className="text-error">{error}</p>}

      {result && (
        <div className="readiness-result">
          <div className="readiness-result__summary">
            <span className={`badge badge--lg ${STATUS_CLASS[result.status] ?? 'badge--neutral'}`}>
              {result.status?.toUpperCase()}
            </span>
            <span className="label">{result.content_type}</span>
          </div>

          {result.blocking?.length > 0 && (
            <section className="readiness-section readiness-section--blocking">
              <h3>Blocking Issues</h3>
              <ul>
                {result.blocking.map((b, i) => <li key={i} className="text-error">{b}</li>)}
              </ul>
            </section>
          )}

          {result.warnings?.length > 0 && (
            <section className="readiness-section">
              <h3>Warnings</h3>
              <ul>
                {result.warnings.map((w, i) => <li key={i} className="text-warning">{w}</li>)}
              </ul>
            </section>
          )}

          <div className="readiness-checks">
            {['domain_check', 'seo_check', 'aeo_check'].map(k => result[k] && (
              <div key={k} className="readiness-checks__item">
                <strong>{k.replace('_check', '').toUpperCase()}</strong>
                <span className={`badge ${STATUS_CLASS[result[k].status] ?? 'badge--neutral'}`}>
                  {result[k].status}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
