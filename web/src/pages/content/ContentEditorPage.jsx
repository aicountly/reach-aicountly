import { useCallback, useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
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
      rows={14}
      placeholder="Write your content here…"
      style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '8px 10px', fontSize: 13, resize: 'vertical', fontFamily: 'inherit' }}
    />
  );
}

export function ContentEditorPage() {
  const { id } = useParams();
  const nav = useNavigate();
  const [item, setItem]       = useState(null);
  const [body, setBody]       = useState({ body_html: '', body_markdown: '', body_plain_text: '', change_summary: '' });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving]   = useState(false);
  const [error, setError]     = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await contentService.getItem(id);
      setItem(data);
      // Populate with current version body
      if (data.current_version_id) {
        const versions = await contentService.listVersions(id);
        const cur = (versions.versions || []).find((v) => v.is_current);
        if (cur) {
          setBody({
            body_html:       cur.body_html || '',
            body_markdown:   cur.body_markdown || '',
            body_plain_text: cur.body_plain_text || '',
            change_summary:  '',
          });
        }
      }
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(load, [load]);

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const versionData = { ...body };
      await contentService.updateItem(id, { title: item.title, version: versionData });
      nav(ROUTES.CONTENT_DETAIL.replace(':id', id));
    } catch (err) {
      setError(err.message);
      setSaving(false);
    }
  };

  if (loading) return <Loader />;
  if (error && !item) return <Alert variant="danger">{error}</Alert>;

  return (
    <div style={{ maxWidth: 900 }}>
      <div className="page-header">
        <h1>Edit: {item?.title}</h1>
      </div>
      {error && <Alert variant="danger">{error}</Alert>}
      <Card>
        <form onSubmit={handleSave}>
          <div style={{ marginBottom: 12 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Content Body (HTML)</label>
            <RichEditor
              value={body.body_html}
              onChange={(v) => setBody((b) => ({ ...b, body_html: v }))}
              disabled={saving}
            />
          </div>
          <div style={{ marginBottom: 12 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Plain Text</label>
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
            <button type="submit" className="btn btn-primary" disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
            <button type="button" className="btn btn-ghost" onClick={() => nav(-1)}>Cancel</button>
          </div>
        </form>
      </Card>
    </div>
  );
}
