import { Link } from 'react-router-dom';
import { ChannelBadge } from '../common/ChannelBadge';
import { StatusBadge } from '../common/StatusBadge';

export function CampaignCard({ campaign }) {
  return (
    <div className="card">
      <div className="card-body">
        <div className="flex items-center gap-2 flex-wrap">
          <ChannelBadge channel={campaign.campaign_type} />
          <StatusBadge status={campaign.status} />
        </div>
        <h3 style={{ fontSize: '1rem', marginTop: 8, fontWeight: 600 }}>{campaign.name}</h3>
        {campaign.objective && <p className="text-sm text-secondary mt-1">{campaign.objective}</p>}
        <div className="text-xs text-muted mt-2">
          {campaign.start_date || '—'} → {campaign.end_date || '—'}
        </div>
        <div className="flex justify-end mt-3">
          <Link to={`/campaigns/${campaign.id}`} className="btn btn-secondary btn-sm">Open</Link>
        </div>
      </div>
    </div>
  );
}
