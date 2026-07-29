import { useEffect, useState } from 'react';
import { Tag, Plus, CheckCircle } from 'lucide-react';
import { listUtmTemplates } from '../../services/intelligenceService.js';

export default function UtmTemplatesPage() {
  const [templates, setTemplates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listUtmTemplates()
      .then((data) => {
        if (cancelled) return;
        const rows = Array.isArray(data) ? data : (data?.templates || data?.data || []);
        setTemplates(rows);
      })
      .catch((e) => {
        if (!cancelled) setError(e.message || 'Failed to load UTM templates');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1.25rem', gap: '1rem', flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
          <Tag size={26} style={{ color: 'var(--color-primary)' }} />
          <div>
            <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>UTM Templates</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              Governed UTM parameter templates for attribution tracking
            </p>
          </div>
        </div>
        <button type="button" className="btn btn-primary btn-sm" disabled title="Create via API until form is wired">
          <Plus size={14} /> New Template
        </button>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {loading && <p className="text-sm text-muted">Loading templates…</p>}

      {!loading && templates.length === 0 && !error && (
        <div className="card">
          <div className="card-body" style={{ textAlign: 'center' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No UTM templates configured yet.
            </p>
          </div>
        </div>
      )}

      {!loading && templates.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Source</th>
                <th>Medium</th>
                <th>Campaign Template</th>
                <th style={{ textAlign: 'center' }}>Active</th>
              </tr>
            </thead>
            <tbody>
              {templates.map((t) => (
                <tr key={t.id || t.name}>
                  <td style={{ fontWeight: 600 }}>{t.name}</td>
                  <td>{t.utm_source || '—'}</td>
                  <td>{t.utm_medium || '—'}</td>
                  <td style={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{t.utm_campaign_template || t.campaign_template || '—'}</td>
                  <td style={{ textAlign: 'center' }}>
                    {t.is_active || t.active
                      ? <CheckCircle size={16} style={{ color: 'var(--color-success)' }} />
                      : <span className="text-muted text-xs">—</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
