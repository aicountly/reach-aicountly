import { useCallback, useEffect, useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { ROUTES } from '../../constants/routes';

function RichEditor({ value, onChange, disabled }) {
  return (
    <textarea
      value={value}
      onChange={(e) => onChange(e.target.value)}
      disabled={disabled}
      rows={18}
      placeholder="Write your content here (HTML supported)…"
      style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '8px 10px', fontSize: 13, resize: 'vertical', fontFamily: 'inherit' }}
    />
  );
}

export function ContentEditorPage() {
  const { id } = useParams();
  const nav = useNavigate();
  const [searchParams] = useSearchParams();
  const returnTo = searchParams.get('returnTo');

  const [item, setItem]       = useState(null);
  const [title, setTitle]     = useState('');
  const [summary, setSummary] = useState('');
  const [body, setBody]       = useState({ body_html: '', body_markdown: '', body_plain_text: '', change_summary: '' });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving]   = useState(false);
  const [error, setError]     = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await contentService.getItem(id, 'current_version');
      setItem(data);
      setTitle(data.title || '');
      setSummary(data.summary || data.current_version?.summary || '');
      const cur = data.current_version;
      if (cur) {
        setBody({
          body_html:       cur.body_html || '',
          body_markdown:   cur.body_markdown || '',
          body_plain_text: cur.body_plain_text || '',
          change_summary:  '',
        });
      }
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await contentService.updateItem(id, {
        title,
        summary,
        version: {
          ...body,
          title,
          summary,
        },
      });
      nav(returnTo || ROUTES.CONTENT_DETAIL.replace(':id', id));
    } catch (err) {
      setError(err.message);
      setSaving(false);
    }
  };

  if (loading) return <Loader />;
  if (error && !item) return <Alert variant="danger">{error}</Alert>;

  return (
    <div style={{ maxWidth: 960 }}>
      <div className="page-header">
        <h1>Edit blog content</h1>
        <p className="text-sm text-muted">
          Saving creates a new immutable version. If this post was already approved, high-risk items may need re-approval before publish.
        </p>
      </div>
      {error && <Alert variant="danger">{error}</Alert>}
      <Card>
        <form onSubmit={handleSave}>
          <div style={{ marginBottom: 12 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Title</label>
            <input
              type="text"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              disabled={saving}
              required
              style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 14, fontWeight: 600 }}
            />
          </div>
          <div style={{ marginBottom: 12 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Summary</label>
            <textarea
              value={summary}
              onChange={(e) => setSummary(e.target.value)}
              rows={3}
              disabled={saving}
              style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }}
            />
          </div>
          <div style={{ marginBottom: 12 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Content Body (HTML)</label>
            <RichEditor
              value={body.body_html}
              onChange={(v) => setBody((b) => ({ ...b, body_html: v }))}
              disabled={saving}
            />
          </div>
          <div style={{ marginBottom: 12 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Plain Text (fallback)</label>
            <textarea
              value={body.body_plain_text}
              onChange={(e) => setBody((b) => ({ ...b, body_plain_text: e.target.value }))}
              rows={4}
              style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }}
            />
          </div>
          <div style={{ marginBottom: 12 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Change Summary</label>
            <input
              type="text"
              value={body.change_summary}
              onChange={(e) => setBody((b) => ({ ...b, change_summary: e.target.value }))}
              placeholder="Brief description of changes…"
              style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }}
            />
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="submit" className="btn btn-primary" disabled={saving}>{saving ? 'Saving…' : 'Save new version'}</button>
            <button type="button" className="btn btn-ghost" onClick={() => (returnTo ? nav(returnTo) : nav(-1))}>Cancel</button>
          </div>
        </form>
      </Card>

      {body.body_html && (
        <Card title="Live preview" style={{ marginTop: 16 }}>
          <div
            className="bcc-article-preview__body"
            dangerouslySetInnerHTML={{ __html: body.body_html }}
          />
        </Card>
      )}
    </div>
  );
}
