import { useCallback, useEffect, useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { calendarService } from '../services/calendarService';
import { ContentCalendarGrid } from '../components/calendar/ContentCalendarGrid';
import { Card } from '../components/common/Card';
import { Loader } from '../components/common/Loader';
import { Alert } from '../components/common/Alert';
import { Modal } from '../components/common/Modal';

const EMPTY_ITEM = { title: '', item_kind: 'blog', date: new Date().toISOString().slice(0,10), notes: '' };

export function ContentCalendarPage() {
  const [items, setItems]     = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [offset, setOffset]   = useState(0);
  const [modalOpen, setModalOpen] = useState(false);
  const [form, setForm]       = useState(EMPTY_ITEM);
  const [saving, setSaving]   = useState(false);

  const monthRange = useMemo(() => {
    const now = new Date();
    now.setDate(1);
    now.setMonth(now.getMonth() + offset);
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const from = `${year}-${month}-01`;
    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
    const to = `${year}-${month}-${String(lastDay).padStart(2,'0')}`;
    return { from, to };
  }, [offset]);

  const load = useCallback(() => {
    setLoading(true);
    calendarService.list({ from: monthRange.from, to: monthRange.to })
      .then((d) => setItems(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [monthRange.from, monthRange.to]);

  useEffect(() => { load(); }, [load]);

  const save = async () => {
    setSaving(true);
    try {
      await calendarService.create(form);
      setModalOpen(false);
      setForm(EMPTY_ITEM);
      load();
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Content calendar</h1>
          <p className="text-sm text-muted">Blog, social, campaign & email planning in one view.</p>
        </div>
        <div className="flex gap-2 items-center">
          <button className="btn btn-secondary btn-sm" onClick={() => setOffset(offset - 1)}><ChevronLeft size={14}/></button>
          <button className="btn btn-secondary btn-sm" onClick={() => setOffset(0)}>Today</button>
          <button className="btn btn-secondary btn-sm" onClick={() => setOffset(offset + 1)}><ChevronRight size={14}/></button>
          <button className="btn btn-primary" onClick={() => setModalOpen(true)}><Plus size={14}/> Add item</button>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      <Card padding={false}>
        <div style={{ padding: '1rem' }}>
          {loading ? <Loader /> : <ContentCalendarGrid items={items} monthOffset={offset} />}
        </div>
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="Add calendar item"
        footer={<>
          <button className="btn btn-secondary" onClick={() => setModalOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div>
            <label className="text-xs text-secondary">Title</label>
            <input value={form.title} onChange={(e) => setForm({...form, title: e.target.value})} />
          </div>
          <div>
            <label className="text-xs text-secondary">Kind</label>
            <select value={form.item_kind} onChange={(e) => setForm({...form, item_kind: e.target.value})}>
              <option value="blog">Blog</option>
              <option value="social">Social</option>
              <option value="email">Email</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="campaign">Campaign</option>
              <option value="webinar">Webinar</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div>
            <label className="text-xs text-secondary">Date</label>
            <input type="date" value={form.date} onChange={(e) => setForm({...form, date: e.target.value})} />
          </div>
          <div>
            <label className="text-xs text-secondary">Notes</label>
            <textarea rows={3} value={form.notes} onChange={(e) => setForm({...form, notes: e.target.value})} />
          </div>
        </div>
      </Modal>
    </div>
  );
}
