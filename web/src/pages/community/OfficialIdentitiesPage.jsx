import { useState, useEffect } from 'react';
import api from '../../services/api';

export default function OfficialIdentitiesPage() {
  const [identities, setIdentities] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm]         = useState({ slug: '', display_name: '', department: '', badge_type: 'official', disclosure_template: '' });
  const [saving, setSaving]     = useState(false);
  const [msg, setMsg]           = useState('');

  function load() {
    setLoading(true);
    api.get('/community/identities', { params: { include_inactive: 1 } })
      .then(r => setIdentities(r.data?.data ?? []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, []);

  function handleCreate(e) {
    e.preventDefault();
    setSaving(true);
    api.post('/community/identities', form)
      .then(() => { setMsg('Identity created.'); setShowForm(false); setForm({ slug: '', display_name: '', department: '', badge_type: 'official', disclosure_template: '' }); load(); })
      .catch(e => setMsg('Error: ' + (e.response?.data?.error ?? e.message)))
      .finally(() => setSaving(false));
  }

  function handleDeactivate(slug) {
    if (!confirm(`Deactivate identity "${slug}"?`)) return;
    api.delete(`/community/identities/${slug}`)
      .then(() => load())
      .catch(e => alert(e.message));
  }

  if (loading) return <p className="muted">Loading…</p>;
  if (error) return <p className="text-error">{error}</p>;

  return (
    <div>
      <div className="page-header">
        <h1>Official Identities</h1>
        <button className="btn btn--sm btn--primary" onClick={() => setShowForm(v => !v)}>
          {showForm ? 'Cancel' : '+ New identity'}
        </button>
      </div>

      {msg && <div className="alert alert--info mb-3">{msg}</div>}

      {showForm && (
        <form onSubmit={handleCreate} className="card mb-4 form-grid">
          <h2 className="card__title">Create identity</h2>
          <label className="form-label">
            Slug *
            <input className="form-input" required value={form.slug} onChange={e => setForm(f => ({ ...f, slug: e.target.value }))} />
          </label>
          <label className="form-label">
            Display name *
            <input className="form-input" required value={form.display_name} onChange={e => setForm(f => ({ ...f, display_name: e.target.value }))} />
          </label>
          <label className="form-label">
            Department
            <input className="form-input" value={form.department} onChange={e => setForm(f => ({ ...f, department: e.target.value }))} />
          </label>
          <label className="form-label">
            Badge type
            <select className="form-select" value={form.badge_type} onChange={e => setForm(f => ({ ...f, badge_type: e.target.value }))}>
              <option value="official">Official</option>
              <option value="staff">Staff</option>
              <option value="partner">Partner</option>
            </select>
          </label>
          <label className="form-label" style={{ gridColumn: '1/-1' }}>
            Disclosure template
            <textarea className="form-textarea" rows={3} value={form.disclosure_template} onChange={e => setForm(f => ({ ...f, disclosure_template: e.target.value }))} />
          </label>
          <div style={{ gridColumn: '1/-1' }}>
            <button className="btn btn--primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Create'}</button>
          </div>
        </form>
      )}

      <table className="data-table">
        <thead>
          <tr>
            <th>Slug</th>
            <th>Display name</th>
            <th>Department</th>
            <th>Badge</th>
            <th>Active</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {identities.length === 0 ? (
            <tr><td colSpan={6} className="muted">No identities.</td></tr>
          ) : identities.map(id => (
            <tr key={id.id}>
              <td><code>{id.slug}</code></td>
              <td>{id.display_name}</td>
              <td>{id.department ?? '—'}</td>
              <td>{id.badge_type}</td>
              <td>{id.is_active ? <span className="badge badge--success">Active</span> : <span className="badge badge--neutral">Inactive</span>}</td>
              <td>
                {id.is_active && (
                  <button className="btn btn--sm btn--ghost" onClick={() => handleDeactivate(id.slug)}>Deactivate</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
