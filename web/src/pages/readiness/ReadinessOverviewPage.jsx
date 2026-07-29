import { Link } from 'react-router-dom';

const SECTIONS = [
  { path: '/readiness/refresh',           label: 'Refresh Recommendations', desc: 'Evidence-based content refresh backlog' },
  { path: '/readiness/outcomes',          label: 'Refresh Outcomes',        desc: 'Observed post-refresh changes' },
  { path: '/readiness/attribution',       label: 'Attribution Maturity',    desc: 'Multi-touch attribution models' },
  { path: '/readiness/security',          label: 'Security Status',         desc: 'Open security findings' },
  { path: '/readiness/privacy',           label: 'Privacy Status',          desc: 'Personal data controls' },
  { path: '/readiness/ai-governance',     label: 'AI Governance',           desc: 'AI model audit and controls' },
  { path: '/readiness/migrations',        label: 'Migration Status',        desc: 'Database migration lifecycle' },
  { path: '/readiness/operations',        label: 'Operations',              desc: 'Job reliability and pipeline health' },
  { path: '/readiness/disaster-recovery', label: 'Disaster Recovery',       desc: 'DR test evidence and runbooks' },
  { path: '/readiness/technical-debt',    label: 'Technical Debt',          desc: 'Classified debt items' },
  { path: '/readiness/release',           label: 'Release Acceptance',      desc: 'Final go/no-go decision record' },
];

export default function ReadinessOverviewPage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Product Readiness Centre</h1>
        <p className="page-header__subtitle">
          Phase 1–9 product readiness status. A release acceptance record must be
          created before any production deployment recommendation can be issued.
        </p>
      </div>

      <div className="grid grid-3">
        {SECTIONS.map(({ path, label, desc }) => (
          <Link
            key={path}
            to={path}
            className="card"
            style={{
              display: 'block',
              padding: '1rem 1.1rem',
              textDecoration: 'none',
              color: 'inherit',
              transition: 'border-color var(--transition), box-shadow var(--transition)',
            }}
          >
            <p style={{ fontWeight: 600, margin: 0, color: 'var(--color-text)' }}>{label}</p>
            <p className="text-sm text-muted" style={{ margin: '0.35rem 0 0' }}>{desc}</p>
          </Link>
        ))}
      </div>
    </div>
  );
}
