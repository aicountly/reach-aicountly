import { useEffect, useState } from 'react';
import { Plus, Save } from 'lucide-react';
import { emailService } from '../../services/emailService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Modal } from '../../components/common/Modal';

const EMPTY = {
  subject: '', from_email: '', from_name: '',
  audience_filter: '', body_html: '', body_text: '', scheduled_at: '',
};

export function EmailListPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    emailService.list()
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  const submit = async () => {
    setSaving(true);
    try {
      await emailService.create({
        ...form,
        audience_filter: form.audience_filter ? { segment: form.audience_filter } : null,
        scheduled_at: form.scheduled_at ? form.scheduled_at.replace('T', ' ') + ':00' : null,
      });
      setOpen(false); setForm(EMPTY); load();
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  const columns = [
    { key: 'subject', label: 'Subject', render: (r) => (
      <div className="font-semibold">{r.subject || '(untitled)'}</div>
    )},
    { key: 'from_email', label: 'From', render: (r) => `${r.from_name || ''} <${r.from_email || ''}>` },
    { key: 'audience_filter', label: 'Audience', render: (r) => r.audience_filter?.segment || '—' },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'sent_at', label: 'Sent', render: (r) => r.sent_at ? new Date(r.sent_at).toLocaleString() : '—' },
    { key: 'scheduled_at', label: 'Scheduled', render: (r) => r.scheduled_at ? new Date(r.scheduled_at).toLocaleString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Email campaigns</h1>
          <p className="text-sm text-muted">Broadcast and scheduled email campaigns.</p>
        </div>
        <button className="btn btn-primary" onClick={() => setOpen(true)}><Plus size={14}/> New email</button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No email campaigns yet." />
        </Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="New email campaign" width={560}
        footer={<>
          <button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={submit} disabled={saving}><Save size={13}/> {saving ? 'Saving…' : 'Save'}</button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div><label className="text-xs text-secondary">Subject</label><input value={form.subject} onChange={(e) => setForm({...form, subject: e.target.value})} /></div>
          <div className="grid grid-2">
            <div><label className="text-xs text-secondary">From email</label><input value={form.from_email} onChange={(e) => setForm({...form, from_email: e.target.value})} /></div>
            <div><label className="text-xs text-secondary">From name</label><input value={form.from_name} onChange={(e) => setForm({...form, from_name: e.target.value})} /></div>
          </div>
          <div><label className="text-xs text-secondary">Audience segment (label)</label><input value={form.audience_filter} onChange={(e) => setForm({...form, audience_filter: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Body (HTML)</label><textarea rows={8} value={form.body_html} onChange={(e) => setForm({...form, body_html: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Plain text body (fallback)</label><textarea rows={3} value={form.body_text} onChange={(e) => setForm({...form, body_text: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Scheduled at</label><input type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm({...form, scheduled_at: e.target.value})} /></div>
        </div>
      </Modal>
    </div>
  );
}
