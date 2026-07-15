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
  { path: '/readiness/release',          label: 'Release Acceptance',      desc: 'Final go/no-go decision record' },
];

export default function ReadinessOverviewPage() {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-2">Product Readiness Centre</h1>
      <p className="text-sm text-gray-500 mb-6">
        Phase 1–9 product readiness status. A release acceptance record must be
        created before any production deployment recommendation can be issued.
      </p>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {SECTIONS.map(({ path, label, desc }) => (
          <Link
            key={path}
            to={path}
            className="bg-white border border-gray-200 rounded-lg p-4 hover:border-blue-300 hover:shadow-sm transition-all group"
          >
            <p className="font-medium text-gray-900 group-hover:text-blue-700">{label}</p>
            <p className="text-sm text-gray-500 mt-1">{desc}</p>
          </Link>
        ))}
      </div>
    </div>
  );
}
