import { useEffect, useState } from 'react';
import { Plus, Save } from 'lucide-react';
import { creativeBriefService } from '../../services/creativeBriefService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Modal } from '../../components/common/Modal';

const EMPTY = { title: '', brief: '', audience: '', deliverables: '', campaign_id: '', status: 'draft' };

export function CreativeBriefsPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    creativeBriefService.list()
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  const submit = async () => {
    setSaving(true);
    try {
      await creativeBriefService.create({
        ...form,
        campaign_id: form.campaign_id ? Number(form.campaign_id) : null,
      });
      setOpen(false); setForm(EMPTY); load();
    }
    catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  const columns = [
    { key: 'title', label: 'Title' },
    { key: 'audience', label: 'Audience' },
    { key: 'deliverables', label: 'Deliverables' },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Creative briefs</h1>
          <p className="text-sm text-muted">Briefs used for campaigns, blog and design work.</p>
        </div>
        <button className="btn btn-primary" onClick={() => setOpen(true)}><Plus size={14}/> New brief</button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No creative briefs yet." />
        </Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="New creative brief" width={520}
        footer={<>
          <button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={submit} disabled={saving}><Save size={13}/> {saving ? 'Saving…' : 'Save'}</button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div><label className="text-xs text-secondary">Title</label><input value={form.title} onChange={(e) => setForm({...form, title: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Brief</label><textarea rows={6} value={form.brief} onChange={(e) => setForm({...form, brief: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Audience</label><input value={form.audience} onChange={(e) => setForm({...form, audience: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Deliverables</label><textarea rows={3} value={form.deliverables} onChange={(e) => setForm({...form, deliverables: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Linked campaign ID (optional)</label><input value={form.campaign_id} onChange={(e) => setForm({...form, campaign_id: e.target.value})} /></div>
        </div>
      </Modal>
    </div>
  );
}
