import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { analyticsService } from '../services/analyticsService';
import { TrafficAnalyticsSection } from '../components/analytics/TrafficAnalyticsSection';
import { OutcomesAnalyticsSection } from '../components/outcomes/OutcomesAnalyticsSection';
import { Card } from '../components/common/Card';
import { Loader } from '../components/common/Loader';
import { Alert } from '../components/common/Alert';
import { ROUTES } from '../constants/routes';
import { drillHref } from '../components/drill';

/** `to` opens the records the tile counted; a zero (or a count with no list to open) stays plain. */
function Tile({ label, value, hint, to }) {
  const body = (
    <>
      <div className="stat-tile__label">{label}</div>
      <div className="stat-tile__value">{value ?? 0}</div>
      {hint && <div className="stat-tile__hint">{hint}</div>}
    </>
  );
  return to && Number(value) > 0
    ? <Link className="stat-tile" to={to}>{body}</Link>
    : <div className="stat-tile">{body}</div>;
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

  // The summary's "(30d)" counts are created_at/published_at within the window;
  // /campaigns and /leads take the same `days` filter on the server.
  const days = parseInt(summary.window, 10) || 30;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Analytics</h1>
          <p className="text-sm text-muted">
            Internal Reach marketing metrics plus GA4 traffic for aicountly.com and all SaaS products (ported from Flow).
          </p>
        </div>
      </div>

      <Card title={`Internal metrics · window ${summary.window || '30d'}`}>
        <div className="grid grid-4">
          <Tile label="Campaigns created (30d)" value={summary.campaigns_created} to={drillHref(ROUTES.CAMPAIGN_LIST, { days })} />
          {/* The social calendar has no "created in the window" filter: planned opens it unfiltered. */}
          <Tile label="Posts planned (30d)"    value={summary.posts_planned} to={ROUTES.SOCIAL_CALENDAR} />
          <Tile label="Posts published (30d)"  value={summary.posts_published} to={drillHref(ROUTES.SOCIAL_CALENDAR, { status: 'posted' })} />
          <Tile label="Leads generated (30d)"  value={summary.leads_generated} to={drillHref(ROUTES.LEADS, { days })} />
          {/* Blog tiles count the legacy blog table, whose list now redirects to Blog Command Centre — no list to open. */}
          <Tile label="Blog drafts"            value={summary.blog_drafts} />
          <Tile label="Blog published (30d)"   value={summary.blog_published} />
          <Tile label="Approvals pending"      value={summary.approval_pending} />
          <Tile label="Engagement imported"    value={summary.engagement_imported} />
        </div>
      </Card>

      <OutcomesAnalyticsSection />

      <TrafficAnalyticsSection />
    </div>
  );
}
