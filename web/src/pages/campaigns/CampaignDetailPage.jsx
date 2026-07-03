import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Edit3, Check } from 'lucide-react';
import { campaignService } from '../../services/campaignService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { StatusBadge } from '../../components/common/StatusBadge';
import { ApprovalBadge } from '../../components/common/ApprovalBadge';
import { ChannelBadge } from '../../components/common/ChannelBadge';

const NEXT_STATUS = ['draft','pending_approval','approved','scheduled','running','completed','paused','archived'];

export function CampaignDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [c, setC] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    campaignService.get(id).then(setC).catch((e) => setError(e.message));
  }, [id]);
  useEffect(load, [load]);

  const approve = async () => { setBusy(true); try { await campaignService.approve(id); load(); } catch (e) { setError(e.message); } finally { setBusy(false); } };
  const setStatus = async (status) => { setBusy(true); try { await campaignService.setStatus(id, status); load(); } catch (e) { setError(e.message); } finally { setBusy(false); } };

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!c) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <button className="btn btn-secondary btn-sm" onClick={() => navigate('/campaigns')}><ArrowLeft size={12}/> All campaigns</button>
          <h1 style={{ marginTop: 6 }}>{c.name}</h1>
          <div className="flex gap-2 mt-1 flex-wrap">
            <ChannelBadge channel={c.campaign_type} />
            <StatusBadge status={c.status} />
            <ApprovalBadge status={c.approval_status} />
          </div>
        </div>
        <div className="flex gap-2">
          <button className="btn btn-secondary" onClick={() => navigate(`/campaigns/${c.id}/edit`)}><Edit3 size={13}/> Edit</button>
          {c.approval_status !== 'approved' && (
            <button className="btn btn-primary" disabled={busy} onClick={approve}><Check size={13}/> Approve</button>
          )}
        </div>
      </div>

      <div className="grid grid-2" style={{ alignItems: 'start' }}>
        <Card title="Overview">
          <div className="grid grid-2">
            <div><div className="text-xs text-muted">Objective</div><div className="text-sm">{c.objective || '—'}</div></div>
            <div><div className="text-xs text-muted">Target audience</div><div className="text-sm">{Array.isArray(c.target_audience) ? c.target_audience.join(' · ') : (c.target_audience || '—')}</div></div>
            <div><div className="text-xs text-muted">Products promoted</div><div className="text-sm">{Array.isArray(c.products_promoted) ? c.products_promoted.join(', ') : (c.products_promoted || '—')}</div></div>
            <div><div className="text-xs text-muted">Budget</div><div className="text-sm">{c.budget_amount ? `${c.currency || ''} ${c.budget_amount}` : '—'}</div></div>
            <div><div className="text-xs text-muted">Start</div><div className="text-sm">{c.start_date || '—'}</div></div>
            <div><div className="text-xs text-muted">End</div><div className="text-sm">{c.end_date || '—'}</div></div>
            <div><div className="text-xs text-muted">Leads generated</div><div className="text-sm">{c.leads_generated ?? 0}</div></div>
            <div><div className="text-xs text-muted">Landing page URL</div><div className="text-sm" style={{ overflowWrap: 'anywhere' }}>{c.landing_page_url || '—'}</div></div>
          </div>
        </Card>

        <div className="flex flex-col gap-3">
          <Card title="Channels & UTM">
            <div className="text-xs text-muted">Channels</div>
            <div className="flex gap-2 flex-wrap mt-1 mb-3">
              {(Array.isArray(c.channels) ? c.channels : (c.channels ? String(c.channels).split(',') : [])).map((ch, i) => (
                <ChannelBadge key={i} channel={String(ch).trim()} />
              ))}
            </div>
            <div className="grid grid-3">
              <div><div className="text-xs text-muted">utm_source</div><div className="text-sm">{c.utm_source || '—'}</div></div>
              <div><div className="text-xs text-muted">utm_medium</div><div className="text-sm">{c.utm_medium || '—'}</div></div>
              <div><div className="text-xs text-muted">utm_campaign</div><div className="text-sm">{c.utm_campaign || '—'}</div></div>
            </div>
          </Card>

          <Card title="Creative / copy" padding={false}>
            <div style={{ padding: '1rem', whiteSpace: 'pre-wrap', fontSize: '0.9rem' }}>{c.creative_copy || '(empty)'}</div>
          </Card>

          <Card title="Analytics summary" padding={false}>
            <div style={{ padding: '1rem', whiteSpace: 'pre-wrap', fontSize: '0.9rem' }}>
              {Array.isArray(c.analytics_summary) ? c.analytics_summary.join('\n') : (c.analytics_summary || '(none)')}
            </div>
          </Card>

          <Card title="Status transitions">
            <div className="flex gap-2 flex-wrap">
              {NEXT_STATUS.filter((s) => s !== c.status).map((s) => (
                <button key={s} className="btn btn-secondary btn-sm" disabled={busy} onClick={() => setStatus(s)}>
                  → {s.replace(/_/g,' ')}
                </button>
              ))}
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}
