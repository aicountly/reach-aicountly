import { useEffect, useState } from 'react';
import { analyticsService } from '../services/analyticsService';
import { TrafficAnalyticsSection } from '../components/analytics/TrafficAnalyticsSection';
import { Card } from '../components/common/Card';
import { Loader } from '../components/common/Loader';
import { Alert } from '../components/common/Alert';

function Tile({ label, value, hint }) {
  return (
    <div className="stat-tile">
      <div className="stat-tile__label">{label}</div>
      <div className="stat-tile__value">{value ?? 0}</div>
      {hint && <div className="stat-tile__hint">{hint}</div>}
    </div>
  );
}

export function AnalyticsPage() {
  const [summary, setSummary] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    analyticsService.summary()
      .then(setSummary)
      .catch((e) => setError(e.message));
  }, []);

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!summary) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Analytics</h1>
          <p className="text-sm text-muted">
            Internal Reach marketing metrics plus GA4 traffic for AICountly.com and all SaaS products (ported from Flow).
          </p>
        </div>
      </div>

      <Card title={`Internal metrics · window ${summary.window || '30d'}`}>
        <div className="grid grid-4">
          <Tile label="Campaigns created (30d)" value={summary.campaigns_created} />
          <Tile label="Posts planned (30d)"    value={summary.posts_planned} />
          <Tile label="Posts published (30d)"  value={summary.posts_published} />
          <Tile label="Leads generated (30d)"  value={summary.leads_generated} />
          <Tile label="Blog drafts"            value={summary.blog_drafts} />
          <Tile label="Blog published (30d)"   value={summary.blog_published} />
          <Tile label="Approvals pending"      value={summary.approval_pending} />
          <Tile label="Engagement imported"    value={summary.engagement_imported} />
        </div>
      </Card>

      <TrafficAnalyticsSection />
    </div>
  );
}
