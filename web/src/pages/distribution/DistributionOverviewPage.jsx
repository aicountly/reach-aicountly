import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

export default function DistributionOverviewPage() {
  const [stats, setStats]     = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api.get('v1/campaigns', { per_page: 1, limit: 1 }).catch(() => null),
      api.get('v1/distribution/dispatches', { per_page: 1 }).catch(() => null),
    ]).then(([campaigns, dispatches]) => {
      setStats({
        totalCampaigns: campaigns?.total ?? 0,
        totalDispatches: dispatches?.total ?? 0,
      });
    }).finally(() => setLoading(false));
  }, []);

  const SECTIONS = [
    { title: 'Campaign Workspace', description: 'Manage versioned campaigns with approval workflow.', to: '/distribution/campaigns', icon: '📋' },
    { title: 'Audience Segments',  description: 'Define and preview dynamic audience segments.',       to: '/distribution/audience/segments', icon: '👥' },
    { title: 'Suppressions',       description: 'Manage unsubscribes, bounces, and complaints.',      to: '/distribution/suppressions', icon: '🚫' },
    { title: 'Social Dispatch',    description: 'Social posts, YouTube videos and their status — in Social & Ads.', to: '/social/calendar', icon: '📣' },
    { title: 'Email Dispatch',     description: 'Send email campaigns via governed provider.',         to: '/distribution/email', icon: '✉️' },
    { title: 'WhatsApp',           description: 'Template-based WhatsApp broadcasts.',                 to: '/distribution/whatsapp', icon: '💬' },
    { title: 'SMS',                description: 'SMS campaigns with DLT compliance.',                  to: '/distribution/sms', icon: '📱' },
    { title: 'Orchestration',      description: 'Fan-out, reconcile, and monitor dispatches.',        to: '/distribution/orchestration', icon: '⚙️' },
    { title: 'Analytics',          description: 'Campaign delivery and engagement metrics.',           to: '/distribution/analytics', icon: '📊' },
    { title: 'Campaign readiness', description: 'Live status of every channel: configured, test mode, or why not.', to: '/distribution/readiness', icon: '✅' },
  ];

  return (
    <div>
      <div className="page-header">
        <h1>Distribution Hub</h1>
        <p className="page-header__subtitle">Omnichannel campaign distribution — social, email, WhatsApp, SMS</p>
      </div>

      {!loading && stats && (
        <div className="stats-row mb-6">
          {[
            { value: stats.totalCampaigns, label: 'Total Campaigns', to: '/distribution/campaigns' },
            { value: stats.totalDispatches, label: 'Total Dispatches', to: '/distribution/orchestration' },
          ].map(({ value, label, to }) => {
            const body = (
              <>
                <div className="stat-card__value">{value}</div>
                <div className="stat-card__label">{label}</div>
              </>
            );
            // A zero has nothing behind it to open.
            return value > 0
              ? <Link key={label} to={to} className="stat-card stat-tile" aria-label={`${label}: ${value} — view records`}>{body}</Link>
              : <div key={label} className="stat-card">{body}</div>;
          })}
        </div>
      )}

      <div className="cards-grid">
        {SECTIONS.map(s => (
          <div key={s.to} className="card">
            <div className="card__header">
              <span className="card__icon">{s.icon}</span>
              <h3>{s.title}</h3>
            </div>
            <div className="card__body"><p>{s.description}</p></div>
            <div className="card__footer">
              <Link to={s.to} className="btn btn--primary btn--sm">Open</Link>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
