import { useCallback, useEffect, useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight, Plus, Trash2 } from 'lucide-react';
import { calendarService } from '../services/calendarService';
import { ContentCalendarGrid } from '../components/calendar/ContentCalendarGrid';
import { Card } from '../components/common/Card';
import { Loader } from '../components/common/Loader';
import { Alert } from '../components/common/Alert';
import { Modal } from '../components/common/Modal';

const KINDS = ['blog', 'social', 'email', 'whatsapp', 'campaign', 'webinar', 'other'];

const EMPTY_ITEM = {
  title: '',
  item_kind: 'blog',
  date: new Date().toISOString().slice(0, 10),
  notes: '',
};

function validateItem(form) {
  const errors = {};
  const title = String(form.title || '').trim();
  if (!title) errors.title = 'Title is required.';
  if (!form.date) errors.date = 'Date is required.';
  else if (!/^\d{4}-\d{2}-\d{2}$/.test(form.date)) errors.date = 'Use a valid date (YYYY-MM-DD).';
  if (!KINDS.includes(form.item_kind)) errors.item_kind = 'Select a valid kind.';
  return errors;
}

export function ContentCalendarPage() {
  const [items, setItems]     = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [offset, setOffset]   = useState(0);
  const [modalOpen, setModalOpen] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [form, setForm]       = useState(EMPTY_ITEM);
  const [formErrors, setFormErrors] = useState({});
  const [saving, setSaving]   = useState(false);
  const [deleting, setDeleting] = useState(false);

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

  const closeModal = () => {
    setModalOpen(false);
    setEditingId(null);
    setForm(EMPTY_ITEM);
    setFormErrors({});
  };

  const openCreate = () => {
    setEditingId(null);
    setForm({ ...EMPTY_ITEM, date: new Date().toISOString().slice(0, 10) });
    setFormErrors({});
    setModalOpen(true);
  };

  const openEdit = (item) => {
    setEditingId(item.id);
    setForm({
      title: item.title || '',
      item_kind: item.item_kind || 'blog',
      date: String(item.date || '').slice(0, 10),
      notes: item.notes || '',
    });
    setFormErrors({});
    setModalOpen(true);
  };

  const setField = (key) => (e) => {
    const value = e.target.value;
    setForm((s) => ({ ...s, [key]: value }));
    if (formErrors[key]) setFormErrors((errs) => ({ ...errs, [key]: undefined }));
  };

  const save = async () => {
    const errors = validateItem(form);
    setFormErrors(errors);
    if (Object.keys(errors).length) return;

    setSaving(true);
    setError(null);
    const payload = {
      ...form,
      title: form.title.trim(),
      notes: form.notes?.trim() || '',
    };
    try {
      if (editingId) {
        await calendarService.update(editingId, payload);
      } else {
        await calendarService.create(payload);
      }
      closeModal();
      load();
    } catch (e) {
      setError(e.message);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!editingId) return;
    if (!window.confirm('Delete this calendar item?')) return;
    setDeleting(true);
    setError(null);
    try {
      await calendarService.remove(editingId);
      closeModal();
      load();
    } catch (e) {
      setError(e.message);
    } finally {
      setDeleting(false);
    }
  };

  const handleItemMove = async (item, newDate) => {
    const current = String(item.date || '').slice(0, 10);
    if (!newDate || newDate === current) return;
    setError(null);
    // Optimistic UI so the drag feels immediate
    setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, date: newDate } : it)));
    try {
      await calendarService.update(item.id, { date: newDate });
    } catch (e) {
      setError(e.message);
      load();
    }
  };

  const isEditing = editingId != null;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Content calendar</h1>
          <p className="text-sm text-muted">Blog, social, campaign & email planning in one view. Click an item to edit · drag to reschedule.</p>
        </div>
        <div className="flex gap-2 items-center">
          <button className="btn btn-secondary btn-sm" onClick={() => setOffset(offset - 1)}><ChevronLeft size={14}/></button>
          <button className="btn btn-secondary btn-sm" onClick={() => setOffset(0)}>Today</button>
          <button className="btn btn-secondary btn-sm" onClick={() => setOffset(offset + 1)}><ChevronRight size={14}/></button>
          <button className="btn btn-primary" onClick={openCreate}><Plus size={14}/> Add item</button>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      <Card padding={false}>
        <div style={{ padding: '1rem' }}>
          {loading ? (
            <Loader />
          ) : (
            <ContentCalendarGrid
              items={items}
              monthOffset={offset}
              onItemClick={openEdit}
              onItemMove={handleItemMove}
            />
          )}
        </div>
      </Card>

      <Modal
        open={modalOpen}
        onClose={closeModal}
        title={isEditing ? 'Edit calendar item' : 'Add calendar item'}
        footer={<>
          {isEditing && (
            <button
              type="button"
              className="btn btn-danger"
              onClick={handleDelete}
              disabled={saving || deleting}
              style={{ marginRight: 'auto' }}
            >
              <Trash2 size={14} /> {deleting ? 'Deleting…' : 'Delete'}
            </button>
          )}
          <button type="button" className="btn btn-secondary" onClick={closeModal} disabled={saving || deleting}>Cancel</button>
          <button type="button" className="btn btn-primary" onClick={save} disabled={saving || deleting}>
            {saving ? 'Saving…' : 'Save'}
          </button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div>
            <label className="text-xs text-secondary">Title <span className="text-danger">*</span></label>
            <input
              value={form.title}
              onChange={setField('title')}
              placeholder="e.g. July cash-flow tips"
              required
              autoFocus
            />
            {formErrors.title && <div className="text-xs text-danger" style={{ marginTop: 4 }}>{formErrors.title}</div>}
          </div>
          <div>
            <label className="text-xs text-secondary">Kind <span className="text-danger">*</span></label>
            <select value={form.item_kind} onChange={setField('item_kind')}>
              {KINDS.map((k) => (
                <option key={k} value={k}>{k.charAt(0).toUpperCase() + k.slice(1)}</option>
              ))}
            </select>
            {formErrors.item_kind && <div className="text-xs text-danger" style={{ marginTop: 4 }}>{formErrors.item_kind}</div>}
          </div>
          <div>
            <label className="text-xs text-secondary">Date <span className="text-danger">*</span></label>
            <input type="date" value={form.date} onChange={setField('date')} required />
            {formErrors.date && <div className="text-xs text-danger" style={{ marginTop: 4 }}>{formErrors.date}</div>}
          </div>
          <div>
            <label className="text-xs text-secondary">Notes</label>
            <textarea rows={3} value={form.notes} onChange={setField('notes')} />
          </div>
        </div>
      </Modal>
    </div>
  );
}
