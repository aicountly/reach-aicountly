import { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import api from '../../services/api';

const CANONICAL_OPTIONS = [
  { value: 'self_canonical', label: 'Self canonical (default)' },
  { value: 'canonical_to_existing', label: 'Canonical to existing URL' },
  { value: 'noindex', label: 'No-index (hide from search)' },
  { value: 'redirect_to_existing', label: 'Redirect to existing URL' },
  { value: 'historical_archive', label: 'Historical archive' },
];

export default function SeoEditorPage() {
  const { contentId } = useParams();
  const [profile, setProfile] = useState(null);
  const [form, setForm] = useState({
    primary_keyword: '',
    meta_title: '',
    meta_description: '',
    slug: '',
    canonical_preference: 'self_canonical',
    robots_directive: 'index,follow',
    focus_language: 'en',
  });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState(null);
  const [seoResult, setSeoResult] = useState(null);

  useEffect(() => {
    api.get(`/publishing/seo/${contentId}`)
      .then(r => {
        const p = r.data?.data ?? {};
        setProfile(p);
        setForm(f => ({
          ...f,
          primary_keyword: p.primary_keyword ?? '',
          meta_title: p.meta_title ?? '',
          meta_description: p.meta_description ?? '',
          slug: p.slug ?? '',
          canonical_preference: p.canonical_preference ?? 'self_canonical',
          robots_directive: p.robots_directive ?? 'index,follow',
          focus_language: p.focus_language ?? 'en',
        }));
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [contentId]);

  const save = async () => {
    setSaving(true);
    setMsg(null);
    try {
      await api.put(`/publishing/seo/${contentId}`, form);
      setMsg('SEO profile saved.');
    } catch (e) {
      setMsg('Save failed: ' + (e.response?.data?.message ?? e.message));
    } finally {
      setSaving(false);
    }
  };

  const runSeoCheck = async () => {
    setSeoResult(null);
    try {
      const r = await api.post(`/publishing/seo/${contentId}/evaluate`);
      setSeoResult(r.data?.data ?? null);
    } catch {
      setMsg('SEO evaluation failed.');
    }
  };

  if (loading) return <p className="muted">Loading SEO profile…</p>;

  const statusColor = s => ({
    ready: 'badge--success',
    warning: 'badge--warning',
    blocked: 'badge--error',
    draft: 'badge--neutral',
  }[s] ?? 'badge--neutral');

  return (
    <div className="detail-page">
      <div className="page-header">
        <h1>SEO Editor — Content #{contentId}</h1>
        {profile?.seo_status && (
          <span className={`badge ${statusColor(profile.seo_status)}`}>{profile.seo_status}</span>
        )}
      </div>

      {msg && <p className="alert alert--info">{msg}</p>}

      <div className="form-grid">
        <div className="form-field">
          <label>Primary Keyword</label>
          <input className="input" value={form.primary_keyword} onChange={e => setForm(f => ({ ...f, primary_keyword: e.target.value }))} />
        </div>
        <div className="form-field">
          <label>Meta Title <span className="muted small">({form.meta_title.length}/70)</span></label>
          <input className="input" value={form.meta_title} onChange={e => setForm(f => ({ ...f, meta_title: e.target.value }))} maxLength={100} />
        </div>
        <div className="form-field form-field--full">
          <label>Meta Description <span className="muted small">({form.meta_description.length}/165)</span></label>
          <textarea className="input" rows={3} value={form.meta_description} onChange={e => setForm(f => ({ ...f, meta_description: e.target.value }))} maxLength={320} />
        </div>
        <div className="form-field">
          <label>Slug</label>
          <input className="input" value={form.slug} onChange={e => setForm(f => ({ ...f, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-') }))} />
        </div>
        <div className="form-field">
          <label>Canonical Preference</label>
          <select className="input" value={form.canonical_preference} onChange={e => setForm(f => ({ ...f, canonical_preference: e.target.value }))}>
            {CANONICAL_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </div>
        <div className="form-field">
          <label>Robots Directive</label>
          <select className="input" value={form.robots_directive} onChange={e => setForm(f => ({ ...f, robots_directive: e.target.value }))}>
            <option value="index,follow">index, follow</option>
            <option value="noindex,follow">noindex, follow</option>
            <option value="index,nofollow">index, nofollow</option>
            <option value="noindex,nofollow">noindex, nofollow</option>
          </select>
        </div>
      </div>

      <div className="action-bar">
        <button className="btn btn--primary" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save SEO Profile'}</button>
        <button className="btn" onClick={runSeoCheck}>Run SEO Check</button>
      </div>

      {seoResult && (
        <div className="seo-result">
          <h3>SEO Evaluation: <span className={`badge ${statusColor(seoResult.status)}`}>{seoResult.status}</span></h3>
          {seoResult.findings?.length > 0 ? (
            <ul>
              {seoResult.findings.map((f, i) => (
                <li key={i} className={f.level === 'error' ? 'text-error' : f.level === 'warning' ? 'text-warning' : 'muted'}>
                  [{f.level}] {f.message}
                </li>
              ))}
            </ul>
          ) : <p className="text-success">No issues found.</p>}
        </div>
      )}
    </div>
  );
}
