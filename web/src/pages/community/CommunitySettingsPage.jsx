import { useState, useEffect } from 'react';
import api from '../../services/api';

export default function CommunitySettingsPage() {
  const [spaces, setSpaces]     = useState([]);
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm]         = useState({
    slug: '', title: '', description: '',
    visibility: 'public', moderation_mode: 'post_review', official_answer_policy: 'optional',
  });
  const [saving, setSaving]   = useState(false);
  const [msg, setMsg]         = useState('');

  function load() {
    setLoading(true);
    api.get('/community/spaces')
      .then(r => setSpaces(r.data?.data ?? []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, []);

  function handleCreate(e) {
    e.preventDefault();
    setSaving(true);
    api.post('/community/spaces', form)
      .then(() => { setMsg('Space created.'); setShowForm(false); load(); })
      .catch(e => setMsg('Error: ' + (e.response?.data?.error ?? e.message)))
      .finally(() => setSaving(false));
  }

  if (loading) return <p className="muted">Loading…</p>;
  if (error) return <p className="text-error">{error}</p>;

  return (
    <div>
      <div className="page-header">
        <h1>Community Settings</h1>
        <button className="btn btn--sm btn--primary" onClick={() => setShowForm(v => !v)}>
          {showForm ? 'Cancel' : '+ New space'}
        </button>
      </div>

      {msg && <div className="alert alert--info mb-3">{msg}</div>}

      {showForm && (
        <form onSubmit={handleCreate} className="card mb-4 form-grid">
          <h2 className="card__title">Create community space</h2>
          <label className="form-label">
            Slug *
            <input className="form-input" required value={form.slug} onChange={e => setForm(f => ({ ...f, slug: e.target.value }))} />
          </label>
          <label className="form-label">
            Title *
            <input className="form-input" required value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))} />
          </label>
          <label className="form-label" style={{ gridColumn: '1/-1' }}>
            Description
            <textarea className="form-textarea" rows={2} value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))} />
          </label>
          <label className="form-label">
            Visibility
            <select className="form-select" value={form.visibility} onChange={e => setForm(f => ({ ...f, visibility: e.target.value }))}>
              <option value="public">Public</option>
              <option value="private">Private</option>
              <option value="unlisted">Unlisted</option>
            </select>
          </label>
          <label className="form-label">
            Moderation mode
            <select className="form-select" value={form.moderation_mode} onChange={e => setForm(f => ({ ...f, moderation_mode: e.target.value }))}>
              <option value="post_review">Post review</option>
              <option value="pre_review">Pre review</option>
              <option value="auto">Auto</option>
            </select>
          </label>
          <label className="form-label">
            Official answer policy
            <select className="form-select" value={form.official_answer_policy} onChange={e => setForm(f => ({ ...f, official_answer_policy: e.target.value }))}>
              <option value="optional">Optional</option>
              <option value="required">Required</option>
              <option value="disabled">Disabled</option>
            </select>
          </label>
          <div style={{ gridColumn: '1/-1' }}>
            <button className="btn btn--primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Create space'}</button>
          </div>
        </form>
      )}

      <section className="card">
        <h2 className="card__title">Community spaces</h2>
        <table className="data-table">
          <thead>
            <tr>
              <th>Slug</th>
              <th>Title</th>
              <th>Visibility</th>
              <th>Moderation</th>
              <th>Official answers</th>
              <th>Active</th>
            </tr>
          </thead>
          <tbody>
            {spaces.length === 0 ? (
              <tr><td colSpan={6} className="muted">No spaces configured.</td></tr>
            ) : spaces.map(s => (
              <tr key={s.id}>
                <td><code>{s.slug}</code></td>
                <td>{s.title}</td>
                <td>{s.visibility}</td>
                <td>{s.moderation_mode}</td>
                <td>{s.official_answer_policy}</td>
                <td>{s.is_active ? <span className="badge badge--success">Active</span> : <span className="badge badge--neutral">Inactive</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </div>
  );
}
