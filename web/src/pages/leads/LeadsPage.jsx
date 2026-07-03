import { useEffect, useState } from 'react';
import { Plus, Save } from 'lucide-react';
import { leadsService } from '../../services/leadsService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { LeadRow } from '../../components/leads/LeadRow';
import { StatusBadge } from '../../components/common/StatusBadge';
import { EmptyState } from '../../components/common/EmptyState';
import { FilterBar } from '../../components/common/FilterBar';
import { Modal } from '../../components/common/Modal';

const EMPTY = {
  name: '', email: '', mobile: '', whatsapp: '', organization: '',
  source_kind: 'form', product_interest: '', priority: 'normal',
  campaign_id: '', landing_page_id: '', notes: '',
};

const STATES = ['','pending_push','pushed','failed','duplicate','rejected','retry_scheduled'];

export function LeadsPage() {
  const [rows, setRows] = useState([]);
  const [pushStatus, setPushStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [open, setOpen]  = useState(false);
  const [form, setForm]  = useState(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    leadsService.list({ engage_push_status: pushStatus })
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, [pushStatus]);

  const doPush = async (lead) => {
    try { await leadsService.push(lead.id); load(); }
    catch (e) { setError(e.message); }
  };

  const submit = async () => {
    setSaving(true);
    try {
      await leadsService.create({
        ...form,
        campaign_id: form.campaign_id ? Number(form.campaign_id) : null,
        landing_page_id: form.landing_page_id ? Number(form.landing_page_id) : null,
      });
      setOpen(false); setForm(EMPTY); load();
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Lead capture</h1>
          <p className="text-sm text-muted">Leads captured from forms, landing pages, campaigns.</p>
        </div>
        <button className="btn btn-primary" onClick={() => setOpen(true)}><Plus size={14}/> New lead</button>
      </div>

      <FilterBar>
        <select value={pushStatus} onChange={(e) => setPushStatus(e.target.value)}>
          {STATES.map((s) => <option key={s} value={s}>{s ? `Engage: ${s.replace(/_/g,' ')}` : 'All Engage statuses'}</option>)}
        </select>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        rows.length === 0 ? <EmptyState message="No leads captured yet." /> : (
          <Card padding={false}>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Name</th><th>Contact</th><th>Source</th><th>Engage push</th><th>Engage code</th><th></th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((l) => <LeadRow key={l.id} lead={l} onPush={l.engage_push_status === 'pushed' ? null : doPush} />)}
                </tbody>
              </table>
            </div>
          </Card>
        )
      )}

      <div className="mt-3">
        <span className="text-xs text-muted">Push statuses:</span>
        <span className="ml-1">
          {STATES.filter(Boolean).map((s) => (
            <span key={s} style={{ marginRight: 6 }}><StatusBadge status={s} /></span>
          ))}
        </span>
      </div>

      <Modal open={open} onClose={() => setOpen(false)} title="New lead"
        footer={<>
          <button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={submit} disabled={saving}><Save size={13}/> {saving ? 'Saving…' : 'Save'}</button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div className="grid grid-2">
            <div><label className="text-xs text-secondary">Name</label><input value={form.name} onChange={(e) => setForm({...form, name: e.target.value})} /></div>
            <div><label className="text-xs text-secondary">Organization</label><input value={form.organization} onChange={(e) => setForm({...form, organization: e.target.value})} /></div>
            <div><label className="text-xs text-secondary">Email</label><input type="email" value={form.email} onChange={(e) => setForm({...form, email: e.target.value})} /></div>
            <div><label className="text-xs text-secondary">Product interest</label><input value={form.product_interest} onChange={(e) => setForm({...form, product_interest: e.target.value})} /></div>
            <div><label className="text-xs text-secondary">Mobile</label><input value={form.mobile} onChange={(e) => setForm({...form, mobile: e.target.value})} /></div>
            <div><label className="text-xs text-secondary">WhatsApp</label><input value={form.whatsapp} onChange={(e) => setForm({...form, whatsapp: e.target.value})} /></div>
            <div><label className="text-xs text-secondary">Source kind</label>
              <select value={form.source_kind} onChange={(e) => setForm({...form, source_kind: e.target.value})}>
                <option value="form">Form</option>
                <option value="landing">Landing page</option>
                <option value="campaign">Campaign</option>
                <option value="social">Social</option>
                <option value="email">Email</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="event">Event / webinar</option>
                <option value="referral">Referral</option>
                <option value="manual">Manual</option>
              </select>
            </div>
            <div><label className="text-xs text-secondary">Priority</label>
              <select value={form.priority} onChange={(e) => setForm({...form, priority: e.target.value})}>
                <option value="low">Low</option>
                <option value="normal">Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div><label className="text-xs text-secondary">Campaign ID (optional)</label><input value={form.campaign_id} onChange={(e) => setForm({...form, campaign_id: e.target.value})} /></div>
            <div><label className="text-xs text-secondary">Landing page ID (optional)</label><input value={form.landing_page_id} onChange={(e) => setForm({...form, landing_page_id: e.target.value})} /></div>
          </div>
          <div><label className="text-xs text-secondary">Notes</label><textarea rows={3} value={form.notes} onChange={(e) => setForm({...form, notes: e.target.value})} /></div>
        </div>
      </Modal>
    </div>
  );
}
