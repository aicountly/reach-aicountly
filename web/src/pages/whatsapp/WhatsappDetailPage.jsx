import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Save, Send } from 'lucide-react';
import { whatsappService } from '../../services/whatsappService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { StatusBadge } from '../../components/common/StatusBadge';

export function WhatsappDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [form, setForm] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    whatsappService.get(id).then(setForm).catch((e) => setError(e.message));
  }, [id]);
  useEffect(load, [load]);

  const set = (k) => (e) => setForm((s) => ({ ...s, [k]: e.target.value }));

  const save = async () => { setBusy(true); try { await whatsappService.update(id, form); load(); } catch (e) { setError(e.message); } finally { setBusy(false); } };
  const markSent = async () => {
    const total = Number(window.prompt('Total recipients:') || 0);
    setBusy(true);
    try { await whatsappService.markSent(id, { total_recipients: total }); load(); }
    catch (e) { setError(e.message); } finally { setBusy(false); }
  };

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!form) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <button className="btn btn-secondary btn-sm" onClick={() => navigate('/whatsapp')}><ArrowLeft size={12}/> WhatsApp campaigns</button>
          <h1 style={{ marginTop: 6 }}>{form.template_name || '(no template)'}</h1>
          <div className="flex gap-2 mt-1"><StatusBadge status={form.status} /></div>
        </div>
        <div className="flex gap-2">
          <button className="btn btn-secondary" onClick={save} disabled={busy}><Save size={13}/> Save</button>
          <button className="btn btn-primary" onClick={markSent} disabled={busy}><Send size={13}/> Mark sent</button>
        </div>
      </div>
      <Card title="Details">
        <div className="grid grid-2">
          <div style={{ gridColumn: 'span 2' }}><label className="text-xs text-secondary">Template name</label><input value={form.template_name || ''} onChange={set('template_name')} /></div>
          <div style={{ gridColumn: 'span 2' }}>
            <label className="text-xs text-secondary">Template params (JSON)</label>
            <textarea
              rows={6}
              value={typeof form.template_params === 'object' && form.template_params !== null
                ? JSON.stringify(form.template_params, null, 2)
                : (form.template_params || '')}
              onChange={(e) => setForm({ ...form, template_params: e.target.value })}
              style={{ fontFamily: 'monospace', fontSize: '0.8rem' }}
            />
          </div>
        </div>
      </Card>
    </div>
  );
}
