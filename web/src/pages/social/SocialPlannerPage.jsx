import { useEffect, useState } from 'react';
import { Plus, Save } from 'lucide-react';
import { socialService } from '../../services/socialService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { ChannelBadge } from '../../components/common/ChannelBadge';
import { ApprovalBadge } from '../../components/common/ApprovalBadge';
import { FilterBar } from '../../components/common/FilterBar';
import { Modal } from '../../components/common/Modal';

const CHANNELS = ['linkedin','twitter','facebook','instagram','youtube','whatsapp_channel','email_newsletter'];

const EMPTY = { channel: 'linkedin', content: '', scheduled_at: '', hashtags: '', media_refs: '' };

export function SocialPlannerPage() {
  const [rows, setRows]     = useState([]);
  const [channel, setChannel] = useState('');
  const [status, setStatus]   = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError]   = useState(null);
  const [open, setOpen]     = useState(false);
  const [form, setForm]     = useState(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    socialService.list({ channel, status })
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, [channel, status]);

  const submit = async () => {
    setSaving(true);
    try {
      const payload = {
        ...form,
        hashtags:   form.hashtags   ? form.hashtags.split(/[\s,]+/).filter(Boolean) : [],
        media_refs: form.media_refs ? form.media_refs.split(/[\s,]+/).filter(Boolean) : [],
        scheduled_at: form.scheduled_at ? form.scheduled_at.replace('T', ' ') + ':00' : null,
      };
      await socialService.create(payload);
      setOpen(false); setForm(EMPTY); load();
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  const columns = [
    { key: 'channel', label: 'Channel', render: (r) => <ChannelBadge channel={r.channel} /> },
    { key: 'content', label: 'Content', render: (r) => (
      <div style={{ maxWidth: 480, whiteSpace: 'pre-wrap' }}>{(r.content || '').slice(0, 240)}{r.content && r.content.length > 240 ? '…' : ''}</div>
    )},
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'approval_status', label: 'Approval', render: (r) => <ApprovalBadge status={r.approval_status} /> },
    { key: 'scheduled_at', label: 'Scheduled', render: (r) => r.scheduled_at ? new Date(r.scheduled_at).toLocaleString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Social planner</h1>
          <p className="text-sm text-muted">Draft posts, request approvals, schedule for the queue.</p>
        </div>
        <button className="btn btn-primary" onClick={() => setOpen(true)}><Plus size={14}/> New post</button>
      </div>

      <FilterBar>
        <select value={channel} onChange={(e) => setChannel(e.target.value)}>
          <option value="">All channels</option>
          {CHANNELS.map((c) => <option key={c} value={c}>{c.replace(/_/g,' ')}</option>)}
        </select>
        <select value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All statuses</option>
          {['draft','pending_approval','approved','scheduled','posted','manual_queue','rejected','failed','archived'].map((s) => (
            <option key={s} value={s}>{s.replace(/_/g,' ')}</option>
          ))}
        </select>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No social posts yet." />
        </Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="New social post" width={560}
        footer={<>
          <button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={submit} disabled={saving}><Save size={13}/> {saving ? 'Saving…' : 'Save'}</button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div>
            <label className="text-xs text-secondary">Channel</label>
            <select value={form.channel} onChange={(e) => setForm({...form, channel: e.target.value})}>
              {CHANNELS.map((c) => <option key={c} value={c}>{c.replace(/_/g,' ')}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs text-secondary">Content</label>
            <textarea rows={6} value={form.content} onChange={(e) => setForm({...form, content: e.target.value})} />
          </div>
          <div>
            <label className="text-xs text-secondary">Hashtags (comma/space)</label>
            <input value={form.hashtags} onChange={(e) => setForm({...form, hashtags: e.target.value})} />
          </div>
          <div>
            <label className="text-xs text-secondary">Media refs (comma-separated URLs)</label>
            <input value={form.media_refs} onChange={(e) => setForm({...form, media_refs: e.target.value})} />
          </div>
          <div>
            <label className="text-xs text-secondary">Scheduled at</label>
            <input type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm({...form, scheduled_at: e.target.value})} />
          </div>
        </div>
      </Modal>
    </div>
  );
}
