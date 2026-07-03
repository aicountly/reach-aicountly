import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Save } from 'lucide-react';
import { landingService } from '../../services/landingService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';

export function LandingDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [form, setForm] = useState(null);
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    landingService.get(id).then((d) => setForm({
      ...d,
      _url:     d?.meta?.url || '',
      _purpose: d?.meta?.purpose || '',
    })).catch((e) => setError(e.message));
  }, [id]);

  const set = (k) => (e) => setForm((s) => ({ ...s, [k]: e.target.value }));

  const save = async () => {
    setSaving(true);
    try {
      await landingService.update(id, {
        title: form.title,
        slug: form.slug,
        status: form.status,
        body: form.body,
        campaign_id: form.campaign_id ? Number(form.campaign_id) : null,
        meta: { url: form._url || null, purpose: form._purpose || null },
      });
      navigate('/landing');
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!form) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <button className="btn btn-secondary btn-sm" onClick={() => navigate('/landing')}><ArrowLeft size={12}/> Landing pages</button>
          <h1 style={{ marginTop: 6 }}>{form.title}</h1>
        </div>
      </div>
      <Card title="Details">
        <div className="grid grid-2">
          <div><label className="text-xs text-secondary">Title</label><input value={form.title || ''} onChange={set('title')} /></div>
          <div><label className="text-xs text-secondary">Slug</label><input value={form.slug || ''} onChange={set('slug')} /></div>
          <div style={{ gridColumn: 'span 2' }}><label className="text-xs text-secondary">URL</label><input value={form._url || ''} onChange={set('_url')} /></div>
          <div style={{ gridColumn: 'span 2' }}><label className="text-xs text-secondary">Purpose</label><input value={form._purpose || ''} onChange={set('_purpose')} /></div>
          <div style={{ gridColumn: 'span 2' }}><label className="text-xs text-secondary">Body / notes</label><textarea rows={6} value={form.body || ''} onChange={set('body')} /></div>
          <div><label className="text-xs text-secondary">Status</label>
            <select value={form.status || 'draft'} onChange={set('status')}>
              <option value="draft">Draft</option>
              <option value="published">Published</option>
              <option value="paused">Paused</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <div><label className="text-xs text-secondary">Linked campaign ID</label><input value={form.campaign_id || ''} onChange={set('campaign_id')} /></div>
        </div>
        <div className="flex justify-end gap-2 mt-4">
          <button className="btn btn-secondary" onClick={() => navigate('/landing')}>Cancel</button>
          <button className="btn btn-primary" onClick={save} disabled={saving}><Save size={14}/> {saving ? 'Saving…' : 'Save'}</button>
        </div>
      </Card>
    </div>
  );
}
