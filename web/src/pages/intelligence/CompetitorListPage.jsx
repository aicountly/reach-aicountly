import { useEffect, useState } from 'react';
import { Users2, Plus, X } from 'lucide-react';
import { listCompetitors, createCompetitor } from '../../services/intelligenceService.js';

const EMPTY_FORM = {
  name: '',
  website_domain: '',
  category: 'accounting_software',
  notes: '',
};

function normalizeList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
}

export default function CompetitorListPage() {
  const [competitors, setCompetitors] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);

  const load = () => {
    setLoading(true);
    setError(null);
    listCompetitors()
      .then((data) => setCompetitors(normalizeList(data)))
      .catch((e) => setError(e.message || 'Failed to load competitors'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
  }, []);

  const onChange = (field) => (e) => {
    setForm((prev) => ({ ...prev, [field]: e.target.value }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    const name = form.name.trim();
    if (!name) {
      setFormError('Name is required');
      return;
    }

    setSaving(true);
    setFormError(null);
    try {
      await createCompetitor({
        name,
        website_domain: form.website_domain.trim() || null,
        category: form.category || 'general',
        notes: form.notes.trim() || null,
        monitoring_enabled: true,
        monitoring_status: 'active',
      });
      setForm(EMPTY_FORM);
      setShowForm(false);
      load();
    } catch (err) {
      setFormError(err.message || 'Unable to create competitor');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div>
      <div className="page-header">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          <Users2 size={22} style={{ color: 'var(--color-primary)', flexShrink: 0 }} aria-hidden="true" />
          <div>
            <h1 style={{ margin: 0 }}>Competitor Monitoring</h1>
            <p className="page-header__subtitle" style={{ marginTop: '0.15rem' }}>
              Track competitor mentions in AI assistant responses
            </p>
          </div>
        </div>
        <div className="page-header__actions">
          <button
            type="button"
            onClick={() => {
              setShowForm((v) => !v);
              setFormError(null);
            }}
            className="btn btn--sm btn--primary"
          >
            {showForm ? <X size={14} aria-hidden="true" /> : <Plus size={14} aria-hidden="true" />}
            {showForm ? 'Cancel' : 'Add Competitor'}
          </button>
        </div>
      </div>

      {showForm && (
        <div className="card mb-4">
          <div className="card__header">
            <h2 className="card__title" style={{ margin: 0 }}>Add Competitor</h2>
          </div>
          <form className="card__body" onSubmit={handleSubmit}>
            {formError && <div className="alert alert-danger mb-4">{formError}</div>}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '0.85rem' }}>
              <div>
                <label className="text-xs text-secondary">Name *</label>
                <input
                  value={form.name}
                  onChange={onChange('name')}
                  placeholder="e.g. Zoho Books"
                  required
                  autoFocus
                />
              </div>
              <div>
                <label className="text-xs text-secondary">Website domain</label>
                <input
                  value={form.website_domain}
                  onChange={onChange('website_domain')}
                  placeholder="zoho.com/books"
                />
              </div>
              <div>
                <label className="text-xs text-secondary">Category</label>
                <select
                  className="form-select"
                  value={form.category}
                  onChange={onChange('category')}
                >
                  <option value="accounting_software">Accounting software</option>
                  <option value="invoicing">Invoicing</option>
                  <option value="payroll">Payroll</option>
                  <option value="general">General</option>
                </select>
              </div>
            </div>
            <div style={{ marginTop: '0.85rem' }}>
              <label className="text-xs text-secondary">Notes</label>
              <textarea
                rows={2}
                value={form.notes}
                onChange={onChange('notes')}
                placeholder="Optional monitoring notes"
              />
            </div>
            <div style={{ marginTop: '1rem', display: 'flex', gap: '0.5rem' }}>
              <button type="submit" className="btn btn--sm btn--primary" disabled={saving}>
                {saving ? 'Saving…' : 'Save Competitor'}
              </button>
              <button
                type="button"
                className="btn btn--sm btn--secondary"
                onClick={() => {
                  setShowForm(false);
                  setFormError(null);
                }}
                disabled={saving}
              >
                Cancel
              </button>
            </div>
          </form>
        </div>
      )}

      {error && <div className="alert alert-danger mb-4">{error}</div>}
      {loading && <p className="muted">Loading competitors…</p>}

      {!loading && competitors.length === 0 && !error && (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0 }}>
              No competitors configured yet. Use Add Competitor to start monitoring.
            </p>
          </div>
        </div>
      )}

      {!loading && competitors.length > 0 && (
        <div className="card">
          <table className="data-table">
            <thead>
              <tr>
                <th>Competitor</th>
                <th>Domain</th>
                <th>Category</th>
                <th style={{ textAlign: 'right' }}>Sample Mention Rate</th>
                <th style={{ textAlign: 'center' }}>Status</th>
              </tr>
            </thead>
            <tbody>
              {competitors.map((c) => {
                const rate = typeof c.mention_rate === 'number' ? c.mention_rate : null;
                const status = c.monitoring_status || (c.monitoring_enabled ? 'active' : 'inactive');
                const domain = c.website_domain || c.domain || '—';
                const category = (c.category || 'general').replace(/_/g, ' ');
                return (
                  <tr key={c.id || c.uuid || c.name}>
                    <td style={{ fontWeight: 600 }}>{c.name}</td>
                    <td className="text-sm text-muted" style={{ fontFamily: 'var(--font-mono, monospace)' }}>
                      {domain}
                    </td>
                    <td className="text-sm">{category}</td>
                    <td style={{ textAlign: 'right', fontWeight: 600 }}>
                      {rate == null ? '—' : `${(rate * 100).toFixed(0)}%`}
                    </td>
                    <td style={{ textAlign: 'center' }}>
                      <span className={`badge ${status === 'active' ? 'badge--success' : 'badge--muted'}`}>
                        {status}
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {competitors.some((c) => typeof c.mention_rate === 'number') && (
            <div className="card__body" style={{ paddingTop: 0 }}>
              <p className="text-xs text-secondary" style={{ margin: 0 }}>
                Sample mention rates are based on monitored AI prompt responses and do not represent
                comprehensive market data or search rankings.
              </p>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
