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

const TYPES  = ['', 'email','whatsapp','social','landing','paid_ad','webinar','referral','multi'];
const STATES = ['', 'draft','pending_approval','approved','scheduled','running','completed','paused','archived'];

export function CampaignListPage() {
  const [rows, setRows] = useState([]);
  const [type, setType] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = () => {
    setLoading(true);
    campaignService.list({ type, status })
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };
  useEffect(load, [type, status]);

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

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        rows.length === 0
          ? <EmptyState message="No campaigns yet." />
          : (
            <div className="grid grid-3">
              {rows.map((c) => <CampaignCard key={c.id} campaign={c} />)}
            </div>
          )
      )}
    </div>
  );
}
