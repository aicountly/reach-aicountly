import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { campaignService } from '../../services/campaignService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { CampaignCard } from '../../components/campaign/CampaignCard';
import { EmptyState } from '../../components/common/EmptyState';
import { FilterBar } from '../../components/common/FilterBar';
import { ROUTES } from '../../constants/routes';
import { useUrlFilter } from '../../components/drill';

const TYPES  = ['', 'email','whatsapp','social','landing','paid_ad','webinar','referral','multi'];
const STATES = ['', 'draft','pending_approval','approved','scheduled','running','completed','paused','archived'];

export function CampaignListPage() {
  const [rows, setRows] = useState([]);
  // In the URL so dashboard tiles can link to a filtered list (?status=running).
  const [type, setType] = useUrlFilter('type');
  const [status, setStatus] = useUrlFilter('status');
  // "Created in the last N days" — Analytics' "Campaigns created (30d)" tile links here.
  const [days, setDays] = useUrlFilter('days');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = () => {
    setLoading(true);
    // The API filters on campaign_type; `type` is the shorter URL param.
    campaignService.list({ campaign_type: type, status, days })
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, [type, status, days]);

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Campaigns</h1>
          <p className="text-sm text-muted">Email, WhatsApp, social, landing, paid & event campaigns.</p>
        </div>
        <Link to={ROUTES.CAMPAIGN_NEW} className="btn btn-primary"><Plus size={14}/> New campaign</Link>
      </div>

      <FilterBar>
        <select value={type} onChange={(e) => setType(e.target.value)}>
          {TYPES.map((t) => <option key={t} value={t}>{t ? t.replace(/_/g,' ') : 'All types'}</option>)}
        </select>
        <select value={status} onChange={(e) => setStatus(e.target.value)}>
          {STATES.map((s) => <option key={s} value={s}>{s ? s.replace(/_/g,' ') : 'All statuses'}</option>)}
        </select>
      </FilterBar>

      {days && (
        <div className="flex items-center gap-2 mb-2">
          <button type="button" className="badge badge-secondary" onClick={() => setDays('')}
                  aria-label={`Clear created in the last ${days} days filter`}>
            Created in the last {days} days ✕
          </button>
        </div>
      )}

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        rows.length === 0
          ? <EmptyState message={type || status || days ? 'No campaigns match this filter.' : 'No campaigns yet.'} />
          : (
            <div className="grid grid-3">
              {rows.map((c) => <CampaignCard key={c.id} campaign={c} />)}
            </div>
          )
      )}
    </div>
  );
}
