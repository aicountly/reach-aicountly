import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

export default function DistributionOverviewPage() {
  const [stats, setStats]     = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api.get('/campaigns?per_page=1').catch(() => ({ data: null })),
      api.get('/distribution/dispatches?per_page=1').catch(() => ({ data: null })),
    ]).then(([campaigns, dispatches]) => {
      setStats({
        totalCampaigns: campaigns.data?.data?.total ?? 0,
        totalDispatches: dispatches.data?.data?.total ?? 0,
      });
    }).finally(() => setLoading(false));
  }, []);

  const SECTIONS = [
    { title: 'Campaign Workspace', description: 'Manage versioned campaigns with approval workflow.', to: '/distribution/campaigns', icon: '📋' },
    { title: 'Audience Segments',  description: 'Define and preview dynamic audience segments.',       to: '/distribution/audience/segments', icon: '👥' },
    { title: 'Suppressions',       description: 'Manage unsubscribes, bounces, and complaints.',      to: '/distribution/suppressions', icon: '🚫' },
    { title: 'Social Dispatch',    description: 'Dispatch approved posts to social channels.',         to: '/distribution/social', icon: '📣' },
    { title: 'Email Dispatch',     description: 'Send email campaigns via governed provider.',         to: '/distribution/email', icon: '✉️' },
    { title: 'WhatsApp',           description: 'Template-based WhatsApp broadcasts.',                 to: '/distribution/whatsapp', icon: '💬' },
    { title: 'SMS',                description: 'SMS campaigns with DLT compliance.',                  to: '/distribution/sms', icon: '📱' },
    { title: 'Orchestration',      description: 'Fan-out, reconcile, and monitor dispatches.',        to: '/distribution/orchestration', icon: '⚙️' },
    { title: 'Analytics',          description: 'Campaign delivery and engagement metrics.',           to: '/distribution/analytics', icon: '📊' },
  ];

  return (
    <div>
      <div className="page-header">
        <h1>Distribution Hub</h1>
        <p className="page-header__subtitle">Omnichannel campaign distribution — social, email, WhatsApp, SMS</p>
      </div>

      {!loading && stats && (
        <div className="stats-row mb-6">
          <div className="stat-card">
            <div className="stat-card__value">{stats.totalCampaigns}</div>
            <div className="stat-card__label">Total Campaigns</div>
          </div>
          <div className="stat-card">
            <div className="stat-card__value">{stats.totalDispatches}</div>
            <div className="stat-card__label">Total Dispatches</div>
          </div>
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
