import { useEffect, useState } from 'react';
import { Plus, Save } from 'lucide-react';
import { whatsappService } from '../../services/whatsappService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Modal } from '../../components/common/Modal';

const EMPTY = { template_name: '', template_params: '', audience_filter: '', scheduled_at: '' };

export function WhatsappListPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    whatsappService.list()
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  const submit = async () => {
    setSaving(true);
    try {
      let template_params = null;
      if (form.template_params && form.template_params.trim() !== '') {
        try { template_params = JSON.parse(form.template_params); }
        catch { throw new Error('Template params must be a valid JSON object or array.'); }
      }
      await whatsappService.create({
        ...form,
        template_params,
        audience_filter: form.audience_filter ? { segment: form.audience_filter } : null,
        scheduled_at: form.scheduled_at ? form.scheduled_at.replace('T',' ') + ':00' : null,
      });
      setOpen(false); setForm(EMPTY); load();
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  const columns = [
    { key: 'template_name', label: 'Template' },
    { key: 'audience_filter', label: 'Audience', render: (r) => r.audience_filter?.segment || '—' },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'sent_at', label: 'Sent', render: (r) => r.sent_at ? new Date(r.sent_at).toLocaleString() : '—' },
    { key: 'scheduled_at', label: 'Scheduled', render: (r) => r.scheduled_at ? new Date(r.scheduled_at).toLocaleString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>WhatsApp campaigns</h1>
          <p className="text-sm text-muted">Broadcast WhatsApp templates &amp; opt-in campaigns.</p>
        </div>
        <button className="btn btn-primary" onClick={() => setOpen(true)}><Plus size={14}/> New WhatsApp campaign</button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No WhatsApp campaigns yet." />
        </Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="New WhatsApp campaign" width={520}
        footer={<>
          <button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={submit} disabled={saving}><Save size={13}/> {saving ? 'Saving…' : 'Save'}</button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div><label className="text-xs text-secondary">Template name</label><input value={form.template_name} onChange={(e) => setForm({...form, template_name: e.target.value})} /></div>
          <div>
            <label className="text-xs text-secondary">Template params (JSON)</label>
            <textarea rows={5} value={form.template_params} onChange={(e) => setForm({...form, template_params: e.target.value})} placeholder='{"1":"Hi","2":"AICOUNTLY"}' style={{ fontFamily: 'monospace', fontSize: '0.8rem' }} />
          </div>
          <div><label className="text-xs text-secondary">Audience segment (label)</label><input value={form.audience_filter} onChange={(e) => setForm({...form, audience_filter: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Scheduled at</label><input type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm({...form, scheduled_at: e.target.value})} /></div>
        </div>
      </Modal>
    </div>
  );
}
