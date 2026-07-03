import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Save } from 'lucide-react';
import { campaignService } from '../../services/campaignService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';

const EMPTY = {
  name: '', campaign_type: 'email', objective: '', target_audience: '',
  products_promoted: '', budget_amount: '', currency: 'INR',
  start_date: '', end_date: '',
  channels: '', utm_source: '', utm_medium: '', utm_campaign: '',
  landing_page_url: '', creative_copy: '', analytics_summary: '',
};

function toStringField(v) {
  if (v == null) return '';
  if (Array.isArray(v)) return v.join(', ');
  return String(v);
}

export function CampaignEditorPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const isNew = !id;
  const [form, setForm]   = useState(EMPTY);
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(!isNew);

  useEffect(() => {
    if (isNew) return;
    campaignService.get(id)
      .then((d) => setForm({
        ...EMPTY, ...d,
        target_audience:   toStringField(d.target_audience),
        products_promoted: toStringField(d.products_promoted),
        channels:          toStringField(d.channels),
        analytics_summary: toStringField(d.analytics_summary),
      }))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [id, isNew]);

  const set = (k) => (e) => setForm((s) => ({ ...s, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const toArr = (v) => (v ? v.split(',').map((s) => s.trim()).filter(Boolean) : []);
      const payload = {
        ...form,
        budget_amount: form.budget_amount === '' ? null : Number(form.budget_amount),
        target_audience:   form.target_audience   ? [form.target_audience]   : [],
        analytics_summary: form.analytics_summary ? [form.analytics_summary] : [],
        products_promoted: toArr(form.products_promoted),
        channels:          toArr(form.channels),
      };
      const saved = isNew
        ? await campaignService.create(payload)
        : await campaignService.update(id, payload);
      navigate(`/campaigns/${saved.id ?? id}`);
    } catch (err) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <button className="btn btn-secondary btn-sm" onClick={() => navigate(-1)}><ArrowLeft size={12}/> Back</button>
          <h1 style={{ marginTop: 6 }}>{isNew ? 'New campaign' : 'Edit campaign'}</h1>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      <form onSubmit={submit} className="grid grid-2" style={{ alignItems: 'start' }}>
        <Card title="Details">
          <div className="flex flex-col gap-3">
            <div>
              <label className="text-xs text-secondary">Name</label>
              <input required value={form.name} onChange={set('name')} />
            </div>
            <div>
              <label className="text-xs text-secondary">Type</label>
              <select value={form.campaign_type} onChange={set('campaign_type')}>
                <option value="email">Email</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="social">Social</option>
                <option value="landing">Landing page</option>
                <option value="paid_ad">Paid ad</option>
                <option value="webinar">Webinar / event</option>
                <option value="referral">Referral / affiliate</option>
                <option value="multi">Multi-channel</option>
              </select>
            </div>
            <div>
              <label className="text-xs text-secondary">Objective</label>
              <textarea rows={2} value={form.objective} onChange={set('objective')} />
            </div>
            <div>
              <label className="text-xs text-secondary">Target audience</label>
              <textarea rows={2} value={form.target_audience} onChange={set('target_audience')} />
            </div>
            <div>
              <label className="text-xs text-secondary">Products promoted (comma separated)</label>
              <input value={form.products_promoted} onChange={set('products_promoted')} />
            </div>
            <div className="grid grid-2">
              <div>
                <label className="text-xs text-secondary">Start date</label>
                <input type="date" value={form.start_date || ''} onChange={set('start_date')} />
              </div>
              <div>
                <label className="text-xs text-secondary">End date</label>
                <input type="date" value={form.end_date || ''} onChange={set('end_date')} />
              </div>
            </div>
            <div className="grid grid-2">
              <div>
                <label className="text-xs text-secondary">Budget (placeholder)</label>
                <input type="number" step="0.01" value={form.budget_amount} onChange={set('budget_amount')} />
              </div>
              <div>
                <label className="text-xs text-secondary">Currency</label>
                <input value={form.currency} onChange={set('currency')} />
              </div>
            </div>
          </div>
        </Card>

        <div className="flex flex-col gap-3">
          <Card title="Channels & tracking">
            <div className="flex flex-col gap-3">
              <div>
                <label className="text-xs text-secondary">Channels (comma separated)</label>
                <input value={form.channels} onChange={set('channels')} placeholder="linkedin, whatsapp, email…" />
              </div>
              <div className="grid grid-2">
                <div>
                  <label className="text-xs text-secondary">UTM source</label>
                  <input value={form.utm_source} onChange={set('utm_source')} />
                </div>
                <div>
                  <label className="text-xs text-secondary">UTM medium</label>
                  <input value={form.utm_medium} onChange={set('utm_medium')} />
                </div>
              </div>
              <div>
                <label className="text-xs text-secondary">UTM campaign</label>
                <input value={form.utm_campaign} onChange={set('utm_campaign')} />
              </div>
              <div>
                <label className="text-xs text-secondary">Landing page URL</label>
                <input value={form.landing_page_url} onChange={set('landing_page_url')} />
              </div>
            </div>
          </Card>

          <Card title="Creative & summary">
            <div className="flex flex-col gap-3">
              <div>
                <label className="text-xs text-secondary">Creative / copy</label>
                <textarea rows={5} value={form.creative_copy} onChange={set('creative_copy')} />
              </div>
              <div>
                <label className="text-xs text-secondary">Analytics summary (manual notes)</label>
                <textarea rows={3} value={form.analytics_summary} onChange={set('analytics_summary')} />
              </div>
            </div>
          </Card>

          <div className="flex justify-end gap-2">
            <button type="button" className="btn btn-secondary" onClick={() => navigate(-1)}>Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={saving}><Save size={14}/> {saving ? 'Saving…' : 'Save'}</button>
          </div>
        </div>
      </form>
    </div>
  );
}
