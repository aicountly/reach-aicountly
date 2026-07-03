import { useEffect, useState } from 'react';
import { Plus, Save, ExternalLink } from 'lucide-react';
import { landingService } from '../../services/landingService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Modal } from '../../components/common/Modal';

const EMPTY = { title: '', slug: '', url: '', purpose: '', campaign_id: '', body: '', status: 'draft' };

export function LandingListPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [open, setOpen]  = useState(false);
  const [form, setForm]  = useState(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    landingService.list()
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  const submit = async () => {
    setSaving(true);
    try {
      const payload = {
        title: form.title,
        slug: form.slug,
        campaign_id: form.campaign_id ? Number(form.campaign_id) : null,
        status: form.status,
        body: form.body,
        meta: { url: form.url || null, purpose: form.purpose || null },
      };
      await landingService.create(payload);
      setOpen(false); setForm(EMPTY); load();
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  const columns = [
    { key: 'title', label: 'Title', render: (r) => (
      <div>
        <div className="font-semibold">{r.title}</div>
        <div className="text-xs text-muted">{r.slug}</div>
      </div>
    )},
    { key: 'url', label: 'URL', render: (r) => r.meta?.url ? (
      <a href={r.meta.url} target="_blank" rel="noopener noreferrer">{r.meta.url} <ExternalLink size={12}/></a>
    ) : '—' },
    { key: 'purpose', label: 'Purpose', render: (r) => r.meta?.purpose || '—' },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'updated_at', label: 'Updated', render: (r) => r.updated_at ? new Date(r.updated_at).toLocaleString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Landing pages</h1>
          <p className="text-sm text-muted">Registered landing pages, their linked campaigns and lead sources.</p>
        </div>
        <button className="btn btn-primary" onClick={() => setOpen(true)}><Plus size={14}/> New landing page</button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No landing pages registered." />
        </Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="New landing page"
        footer={<>
          <button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={submit} disabled={saving}><Save size={13}/> {saving ? 'Saving…' : 'Save'}</button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div><label className="text-xs text-secondary">Title</label><input value={form.title} onChange={(e) => setForm({...form, title: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Slug</label><input value={form.slug} onChange={(e) => setForm({...form, slug: e.target.value})} placeholder="auto if empty" /></div>
          <div><label className="text-xs text-secondary">URL (public)</label><input value={form.url} onChange={(e) => setForm({...form, url: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Purpose</label><input value={form.purpose} onChange={(e) => setForm({...form, purpose: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Linked campaign ID (optional)</label><input value={form.campaign_id} onChange={(e) => setForm({...form, campaign_id: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Status</label>
            <select value={form.status} onChange={(e) => setForm({...form, status: e.target.value})}>
              <option value="draft">Draft</option>
              <option value="published">Published</option>
              <option value="paused">Paused</option>
              <option value="archived">Archived</option>
            </select>
          </div>
        </div>
      </Modal>
    </div>
  );
}
