import { NavLink, Outlet } from 'react-router-dom';
import { ShieldCheck } from 'lucide-react';

// 2026-08 revamp: the Recommendations backlog (its backend was never built),
// and the hardcoded-prose Privacy / AI Governance / Migrations pages were
// removed — every remaining entry is backed by a real endpoint.
const navItems = [
  { to: '/readiness',                   label: 'Overview',           end: true },
  { to: '/readiness/outcomes',          label: 'Outcomes' },
  { to: '/readiness/attribution',       label: 'Attribution' },
  { to: '/readiness/security',          label: 'Security' },
  { to: '/readiness/operations',        label: 'Operations' },
  { to: '/readiness/disaster-recovery', label: 'Disaster Recovery' },
  { to: '/readiness/technical-debt',    label: 'Technical Debt' },
  { to: '/readiness/release',           label: 'Release Acceptance' },
];

export default function ReadinessLayout() {
  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <ShieldCheck size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">Product Readiness</h1>
        </div>
        <p className="page-layout__subtitle">
          Refresh recommendations, outcomes, attribution, and release acceptance gates.
        </p>
      </div>

      <nav className="sub-nav" aria-label="Product Readiness navigation">
        {navItems.map(({ to, label, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            className={({ isActive }) =>
              `sub-nav__link${isActive ? ' sub-nav__link--active' : ''}`
            }
          >
            {label}
          </NavLink>
        ))}
      </nav>

      <div className="page-layout__body">
        <Outlet />
      </div>
    </div>
  );
}
