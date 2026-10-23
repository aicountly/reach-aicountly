import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Plus, Save } from 'lucide-react';
import { leadsService } from '../../services/leadsService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { StatusBadge } from '../../components/common/StatusBadge';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { Modal } from '../../components/common/Modal';
import { DrillLink, drillHref, useUrlFilter } from '../../components/drill';
import { ROUTES } from '../../constants/routes';

const EMPTY = {
  name: '', email: '', mobile: '', whatsapp: '', organization: '',
  source_kind: 'form', product_interest: '', priority: 'normal',
  campaign_id: '', landing_page_id: '', notes: '',
};

const STATES = ['','pending_push','pushed','failed','duplicate','rejected','retry_scheduled'];
const SOURCE_KINDS = ['form', 'landing', 'campaign', 'social', 'social_inbox', 'email', 'whatsapp', 'event', 'referral', 'manual'];
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
// Drill filters with no control of their own — shown as removable chips.
const CHIP_FILTERS = { lead_id: 'Lead #', campaign_id: 'Campaign #', landing_page_id: 'Landing page #', days: 'Captured in the last' };

export function LeadsPage() {
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  // Filters live in the URL so Analytics, Campaign and Outcomes pages can link
  // to the leads behind a number (/leads?campaign_id=7, /leads?lead_id=41).
  const [params] = useSearchParams();
  const [pushStatus, setPushStatus] = useUrlFilter('engage_push_status');
  const [sourceKind, setSourceKind] = useUrlFilter('source_kind');
  const [priority, setPriority] = useUrlFilter('priority');
  const [search, setSearch] = useUrlFilter('search');
  const chipValues = Object.fromEntries(Object.keys(CHIP_FILTERS).map((k) => [k, params.get(k) || '']));
  const chipKey = JSON.stringify(chipValues);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [open, setOpen]  = useState(false);
  const [form, setForm]  = useState(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    leadsService.list({
      engage_push_status: pushStatus, source_kind: sourceKind, priority, search,
      ...JSON.parse(chipKey), limit: 100,
    })
      .then((d) => { setRows(d.items || d); setTotal(d.total ?? (d.items || d).length); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, [pushStatus, sourceKind, priority, search, chipKey]);

  // An in-page drill step: the current filters plus `patch` (a Link, so Back steps up again).
  const here = (patch) => drillHref(ROUTES.LEADS, { ...Object.fromEntries(params), ...patch });
  const activeChips = Object.entries(CHIP_FILTERS).filter(([k]) => chipValues[k]);
  const filtered = pushStatus || sourceKind || priority || search || activeChips.length > 0;

  const columns = [
    { key: 'name', label: 'Name', render: (l) => (
      <div>
        <div style={{ fontWeight: 600 }}>{l.name}</div>
        {l.organization && <div className="text-xs text-muted">{l.organization}</div>}
      </div>
    )},
    { key: 'contact', label: 'Contact', render: (l) => (
      <div>
        <div>{l.email || '—'}</div>
        <div className="text-xs text-muted">{l.mobile || l.whatsapp || ''}</div>
      </div>
    )},
    { key: 'source', label: 'Source', render: (l) => (
      <div>
        <div>{l.source_kind ? <DrillLink to={here({ source_kind: l.source_kind })}>{l.source_kind}</DrillLink> : '—'}</div>
        {l.campaign_id && (
          <div className="text-xs">
            <DrillLink to={ROUTES.CAMPAIGN_DETAIL.replace(':id', l.campaign_id)}>Campaign #{l.campaign_id}</DrillLink>
          </div>
        )}
        {(l.utm_source || l.utm_campaign) && (
          <div className="text-xs text-muted">utm: {[l.utm_source, l.utm_campaign].filter(Boolean).join(' / ')}</div>
        )}
        {l.ref_code && <div className="text-xs text-muted">ref: {l.ref_code}</div>}
      </div>
    )},
    { key: 'engage_push_status', label: 'Engage push', render: (l) => (
      <DrillLink to={l.engage_push_status ? here({ engage_push_status: l.engage_push_status }) : null}>
        <StatusBadge status={l.engage_push_status} />
      </DrillLink>
    )},
    // Engage's outcomes for this lead (all time) — the next level down from a lead.
    { key: 'engage_lead_code', label: 'Engage code', render: (l) => (
      <div className="text-xs text-muted">
        <div>{l.engage_lead_code || '—'}</div>
        {l.engage_stage && (
          <div>
            <DrillLink to={drillHref(ROUTES.LEAD_OUTCOMES, { lead_id: l.id, days: 'all' })} title="Outcomes Engage reported for this lead">
              stage: {l.engage_stage.replace(/_/g, ' ')}
            </DrillLink>
          </div>
        )}
      </div>
    )},
    { key: 'actions', label: '', render: (l) => (
      l.engage_push_status === 'pushed' ? null : (
        <button className="btn btn-secondary btn-sm" onClick={() => doPush(l)}>Push to Engage</button>
      )
    )},
  ];

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
        <select aria-label="Engage status" value={pushStatus} onChange={(e) => setPushStatus(e.target.value)}>
          {STATES.map((s) => <option key={s} value={s}>{s ? `Engage: ${s.replace(/_/g,' ')}` : 'All Engage statuses'}</option>)}
        </select>
        <select aria-label="Source" value={sourceKind} onChange={(e) => setSourceKind(e.target.value)}>
          <option value="">All sources</option>
          {/* Keep a linked value selectable even when it is not one of the known kinds. */}
          {[...SOURCE_KINDS, ...(sourceKind && !SOURCE_KINDS.includes(sourceKind) ? [sourceKind] : [])].map((k) => (
            <option key={k} value={k}>{k.replace(/_/g, ' ')}</option>
          ))}
        </select>
        <select aria-label="Priority" value={priority} onChange={(e) => setPriority(e.target.value)}>
          <option value="">Any priority</option>
          {PRIORITIES.map((p) => <option key={p} value={p}>{p}</option>)}
        </select>
        <input aria-label="Search" placeholder="Name, e-mail, phone, Engage code, ref" value={search} onChange={(e) => setSearch(e.target.value)} />
      </FilterBar>

      {activeChips.length > 0 && (
        <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
          {activeChips.map(([k, chip]) => (
            <Link key={k} to={here({ [k]: '' })} className="badge badge-secondary" aria-label={`Clear ${chip.replace(' #', '')} filter`}>
              {k === 'days' ? `${chip} ${chipValues[k]} days` : `${chip}${chipValues[k]}`} ✕
            </Link>
          ))}
          <span className="text-xs text-muted">{total} matching lead{total === 1 ? '' : 's'}</span>
        </div>
      )}

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable
            columns={columns}
            rows={rows}
            emptyMessage={filtered ? 'No leads match this filter.' : 'No leads captured yet.'}
          />
        </Card>
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
