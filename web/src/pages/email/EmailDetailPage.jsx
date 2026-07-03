import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Save, Send } from 'lucide-react';
import { emailService } from '../../services/emailService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { StatusBadge } from '../../components/common/StatusBadge';

export function EmailDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [form, setForm] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    emailService.get(id).then(setForm).catch((e) => setError(e.message));
  }, [id]);
  useEffect(load, [load]);

  const set = (k) => (e) => setForm((s) => ({ ...s, [k]: e.target.value }));

  const save = async () => { setBusy(true); try { await emailService.update(id, form); load(); } catch (e) { setError(e.message); } finally { setBusy(false); } };
  const markSent = async () => {
    const total = Number(window.prompt('Total recipients:') || 0);
    setBusy(true);
    try { await emailService.markSent(id, { total_recipients: total }); load(); }
    catch (e) { setError(e.message); } finally { setBusy(false); }
  };

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!form) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <button className="btn btn-secondary btn-sm" onClick={() => navigate('/email')}><ArrowLeft size={12}/> Email campaigns</button>
          <h1 style={{ marginTop: 6 }}>{form.subject || '(untitled)'}</h1>
          <div className="flex gap-2 mt-1"><StatusBadge status={form.status} /></div>
        </div>
        <div className="flex gap-2">
          <button className="btn btn-secondary" onClick={save} disabled={busy}><Save size={13}/> Save</button>
          <button className="btn btn-primary" onClick={markSent} disabled={busy}><Send size={13}/> Mark sent</button>
        </div>
      </div>

      <Card title="Details">
        <div className="grid grid-2">
          <div style={{ gridColumn: 'span 2' }}><label className="text-xs text-secondary">Subject</label><input value={form.subject || ''} onChange={set('subject')} /></div>
          <div><label className="text-xs text-secondary">From email</label><input value={form.from_email || ''} onChange={set('from_email')} /></div>
          <div><label className="text-xs text-secondary">From name</label><input value={form.from_name || ''} onChange={set('from_name')} /></div>
          <div style={{ gridColumn: 'span 2' }}><label className="text-xs text-secondary">Body (HTML)</label><textarea rows={10} value={form.body_html || ''} onChange={set('body_html')} /></div>
          <div style={{ gridColumn: 'span 2' }}><label className="text-xs text-secondary">Body (text fallback)</label><textarea rows={4} value={form.body_text || ''} onChange={set('body_text')} /></div>
        </div>
      </Card>
    </div>
  );
}
