import { useEffect, useState } from 'react';
import { Plus, Save } from 'lucide-react';
import { seoService } from '../../services/seoService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Modal } from '../../components/common/Modal';

const EMPTY = { title: '', focus_keyword: '', secondary_keywords: '', target_url: '', brief: '', status: 'planning' };

export function SeoPlansPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    seoService.listPlans()
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  const submit = async () => {
    setSaving(true);
    try {
      const payload = {
        ...form,
        secondary_keywords: form.secondary_keywords
          ? form.secondary_keywords.split(',').map((s) => s.trim()).filter(Boolean)
          : [],
      };
      await seoService.createPlan(payload);
      setOpen(false); setForm(EMPTY); load();
    }
    catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  const columns = [
    { key: 'title', label: 'Title' },
    { key: 'focus_keyword', label: 'Focus keyword' },
    { key: 'target_url', label: 'Target URL' },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'updated_at', label: 'Updated', render: (r) => r.updated_at ? new Date(r.updated_at).toLocaleString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>SEO plans</h1>
          <p className="text-sm text-muted">Topic clusters, target keywords, and outlines.</p>
        </div>
        <button className="btn btn-primary" onClick={() => setOpen(true)}><Plus size={14}/> New SEO plan</button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No SEO plans yet." />
        </Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="New SEO plan" width={520}
        footer={<>
          <button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={submit} disabled={saving}><Save size={13}/> {saving ? 'Saving…' : 'Save'}</button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div><label className="text-xs text-secondary">Title</label><input value={form.title} onChange={(e) => setForm({...form, title: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Focus keyword</label><input value={form.focus_keyword} onChange={(e) => setForm({...form, focus_keyword: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Secondary keywords (comma separated)</label><input value={form.secondary_keywords} onChange={(e) => setForm({...form, secondary_keywords: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Target URL</label><input value={form.target_url} onChange={(e) => setForm({...form, target_url: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Brief / Outline</label><textarea rows={6} value={form.brief} onChange={(e) => setForm({...form, brief: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Status</label>
            <select value={form.status} onChange={(e) => setForm({...form, status: e.target.value})}>
              <option value="planning">Planning</option>
              <option value="in_progress">In progress</option>
              <option value="done">Done</option>
              <option value="archived">Archived</option>
            </select>
          </div>
        </div>
      </Modal>
    </div>
  );
}
