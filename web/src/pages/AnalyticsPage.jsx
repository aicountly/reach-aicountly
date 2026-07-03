import { useEffect, useState } from 'react';
import { analyticsService } from '../services/analyticsService';
import { Card } from '../components/common/Card';
import { Loader } from '../components/common/Loader';
import { Alert } from '../components/common/Alert';
import { StatusBadge } from '../components/common/StatusBadge';

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
  const [traffic, setTraffic] = useState(null);
  const [providers, setProviders] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    Promise.all([
      analyticsService.summary().then(setSummary),
      analyticsService.traffic().then(setTraffic).catch(() => setTraffic({})),
      analyticsService.providers().then(setProviders).catch(() => setProviders({})),
    ]).catch((e) => setError(e.message));
  }, []);

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!summary) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Analytics</h1>
          <p className="text-sm text-muted">Internal marketing metrics + external integration health.</p>
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

      <Card title="Traffic snapshots (internal)">
        {(traffic?.snapshots || []).length === 0 ? (
          <div className="text-sm text-muted">
            No traffic snapshots yet. Configure GA4 / Search Console / Meta credentials in <code>.env</code> to import external data.
          </div>
        ) : (
          <pre className="text-xs" style={{ whiteSpace: 'pre-wrap' }}>{JSON.stringify(traffic.snapshots, null, 2)}</pre>
        )}
      </Card>

      <Card title="External providers">
        <div className="grid grid-2">
          {(providers?.providers || []).map((p) => (
            <div key={p.provider} className="flex items-center justify-between" style={{ padding: '0.4rem 0', borderBottom: '1px solid var(--color-border)' }}>
              <div className="text-sm font-semibold" style={{ textTransform: 'uppercase' }}>{String(p.provider).replace(/_/g,' ')}</div>
              <StatusBadge status={p.status} />
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
