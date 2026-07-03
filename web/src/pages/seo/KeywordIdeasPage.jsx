import { useEffect, useState } from 'react';
import { Plus, Save } from 'lucide-react';
import { seoService } from '../../services/seoService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Modal } from '../../components/common/Modal';

const EMPTY = { keyword: '', search_intent: 'informational', priority: 'normal', source: 'manual', notes: '', status: 'new' };

export function KeywordIdeasPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    seoService.listKeywords()
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  const submit = async () => {
    setSaving(true);
    try {
      await seoService.createKeyword(form);
      setOpen(false); setForm(EMPTY); load();
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  const columns = [
    { key: 'keyword', label: 'Keyword' },
    { key: 'search_intent', label: 'Intent' },
    { key: 'priority', label: 'Priority' },
    { key: 'source', label: 'Source' },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Keyword ideas</h1>
          <p className="text-sm text-muted">Rolling backlog of keyword and topic ideas.</p>
        </div>
        <button className="btn btn-primary" onClick={() => setOpen(true)}><Plus size={14}/> Add keyword</button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No keyword ideas yet." />
        </Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="Add keyword idea" width={480}
        footer={<>
          <button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={submit} disabled={saving}><Save size={13}/> {saving ? 'Saving…' : 'Save'}</button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div><label className="text-xs text-secondary">Keyword</label><input value={form.keyword} onChange={(e) => setForm({...form, keyword: e.target.value})} /></div>
          <div><label className="text-xs text-secondary">Search intent</label>
            <select value={form.search_intent} onChange={(e) => setForm({...form, search_intent: e.target.value})}>
              <option value="informational">Informational</option>
              <option value="navigational">Navigational</option>
              <option value="commercial">Commercial</option>
              <option value="transactional">Transactional</option>
            </select>
          </div>
          <div className="grid grid-2">
            <div><label className="text-xs text-secondary">Priority</label>
              <select value={form.priority} onChange={(e) => setForm({...form, priority: e.target.value})}>
                <option value="low">Low</option><option value="normal">Normal</option>
                <option value="high">High</option><option value="urgent">Urgent</option>
              </select>
            </div>
            <div><label className="text-xs text-secondary">Source</label>
              <select value={form.source} onChange={(e) => setForm({...form, source: e.target.value})}>
                <option value="manual">Manual</option><option value="bot">Bot</option>
                <option value="external">External tool</option>
              </select>
            </div>
          </div>
          <div><label className="text-xs text-secondary">Notes</label><textarea rows={3} value={form.notes} onChange={(e) => setForm({...form, notes: e.target.value})} /></div>
        </div>
      </Modal>
    </div>
  );
}
