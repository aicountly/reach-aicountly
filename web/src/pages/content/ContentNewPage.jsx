import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { ROUTES } from '../../constants/routes';

const CONTENT_TYPES = [
  'blog', 'knowledge_base', 'community_question', 'community_answer',
  'video_topic', 'video_script', 'social_post', 'email', 'whatsapp', 'sms',
  'landing_page', 'product_announcement', 'release_announcement',
  'webinar', 'case_study', 'content_refresh',
];

export function ContentNewPage() {
  const [form, setForm]       = useState({ content_type: 'blog', title: '', summary: '', language: 'en', risk_level: 'low' });
  const [submitting, setSub]  = useState(false);
  const [error, setError]     = useState(null);
  const nav = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!form.title.trim()) return;
    setSub(true);
    setError(null);
    try {
      const result = await contentService.createItem(form);
      nav(ROUTES.CONTENT_DETAIL.replace(':id', result.item?.id ?? result.id));
    } catch (err) {
      setError(err.message);
      setSub(false);
    }
  };

  const field = (key, label, type = 'text', opts = {}) => (
    <div style={{ marginBottom: 14 }}>
      <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>{label}</label>
      {type === 'select' ? (
        <select
          value={form[key] || ''}
          onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.value }))}
          style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }}
        >
          {opts.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
      ) : type === 'textarea' ? (
        <textarea
          value={form[key] || ''}
          onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.value }))}
          rows={3}
          style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }}
        />
      ) : (
        <input
          type={type}
          value={form[key] || ''}
          onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.value }))}
          style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }}
        />
      )}
    </div>
  );

  return (
    <div style={{ maxWidth: 640 }}>
      <div className="page-header">
        <h1>New Content Item</h1>
      </div>
      {error && <Alert variant="danger">{error}</Alert>}
      <Card>
        <form onSubmit={handleSubmit}>
          {field('content_type', 'Content Type', 'select', {
            options: CONTENT_TYPES.map((t) => ({ value: t, label: t.replace(/_/g, ' ') })),
          })}
          {field('title', 'Title *')}
          {field('summary', 'Summary', 'textarea')}
          {field('language', 'Language', 'text')}
          {field('risk_level', 'Risk Level', 'select', {
            options: ['low', 'medium', 'high', 'critical'].map((r) => ({ value: r, label: r })),
          })}
          {field('funnel_stage', 'Funnel Stage', 'select', {
            options: [{ value: '', label: '— select —' }, { value: 'top', label: 'Top' }, { value: 'middle', label: 'Middle' }, { value: 'bottom', label: 'Bottom' }],
          })}
          <div style={{ marginTop: 16, display: 'flex', gap: 8 }}>
            <button type="submit" className="btn btn-primary" disabled={submitting || !form.title.trim()}>
              {submitting ? 'Creating…' : 'Create Content Item'}
            </button>
            <button type="button" className="btn btn-ghost" onClick={() => nav(-1)}>Cancel</button>
          </div>
        </form>
      </Card>
    </div>
  );
}
