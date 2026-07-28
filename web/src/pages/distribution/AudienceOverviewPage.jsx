import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

export default function AudienceOverviewPage() {
  const [stats, setStats]     = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api.get('v1/distribution/segments').catch(() => null),
      api.get('v1/distribution/suppressions', { page: 1, per_page: 1 }).catch(() => null),
      api.get('v1/distribution/consents', { page: 1, per_page: 1 }).catch(() => null),
    ]).then(([segments, suppressions, consents]) => {
      const segmentRows = Array.isArray(segments?.data)
        ? segments.data
        : (Array.isArray(segments) ? segments : []);
      setStats({
        totalSegments: segmentRows.length,
        totalSuppressions: suppressions?.total ?? 0,
        totalConsents: consents?.total ?? 0,
      });
    }).finally(() => setLoading(false));
  }, []);

  const SECTIONS = [
    {
      title: 'Audience Segments',
      description: 'Define dynamic and static recipient groups used for campaign targeting.',
      to: '/distribution/audience/segments',
      action: 'View Segments',
    },
    {
      title: 'Suppression List',
      description: 'Manage unsubscribes, bounces, and complaints to ensure compliant sending.',
      to: '/distribution/suppressions',
      action: 'View Suppressions',
    },
    {
      title: 'Channel Consents',
      description: 'Review granted and revoked consent records used for channel eligibility checks.',
      to: '/distribution/audience/consents',
      action: 'View Consents',
    },
  ];

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Audience Management</h1>
        <p className="page-header__subtitle">
          Segments, consent records, and suppression lists for campaign targeting
        </p>
      </div>

      {!loading && stats && (
        <div className="stats-row mb-6">
          <div className="stat-card">
            <div className="stat-card__value">{stats.totalSegments}</div>
            <div className="stat-card__label">Segments</div>
            <Link to="/distribution/audience/segments" className="stat-card__link">Manage →</Link>
          </div>
          <div className="stat-card">
            <div className="stat-card__value">{stats.totalSuppressions}</div>
            <div className="stat-card__label">Suppressions</div>
            <Link to="/distribution/suppressions" className="stat-card__link">Manage →</Link>
          </div>
          <div className="stat-card">
            <div className="stat-card__value">{stats.totalConsents}</div>
            <div className="stat-card__label">Consents</div>
            <Link to="/distribution/audience/consents" className="stat-card__link">Manage →</Link>
          </div>
        </div>
      )}

      <div className="cards-grid">
        {SECTIONS.map(s => (
          <div key={s.to} className="card">
            <div className="card__header">
              <h3 className="card__title">{s.title}</h3>
            </div>
            <div className="card__body">
              <p>{s.description}</p>
            </div>
            <div className="card__footer">
              <Link to={s.to} className="btn btn--primary btn--sm">{s.action}</Link>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
