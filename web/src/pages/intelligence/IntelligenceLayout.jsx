import { NavLink, Outlet } from 'react-router-dom';
import { Search } from 'lucide-react';

/**
 * Horizontal Intelligence chrome only — no nested left aside.
 * Nested routes stay in this sub-nav so they remain reachable
 * (the app Sidebar lists primary destinations only).
 */
const navItems = [
  { to: '/intelligence', end: true, label: 'Overview' },
  { to: '/intelligence/search', end: true, label: 'Search' },
  { to: '/intelligence/search/queries', label: 'Queries' },
  { to: '/intelligence/search/pages', label: 'Pages' },
  { to: '/intelligence/content', end: true, label: 'Content' },
  { to: '/intelligence/sitemaps', label: 'Sitemaps' },
  { to: '/intelligence/indexnow', label: 'IndexNow' },
  { to: '/intelligence/attribution', end: true, label: 'Attribution' },
  { to: '/intelligence/attribution/utm', label: 'UTM Templates' },
  { to: '/intelligence/visibility', end: true, label: 'AI Visibility' },
  { to: '/intelligence/visibility/prompts', label: 'Prompt Library' },
  { to: '/intelligence/visibility/runs', label: 'Run History' },
  { to: '/intelligence/competitors', label: 'Competitors' },
  { to: '/intelligence/connectors', label: 'Connectors' },
  { to: '/intelligence/operations', label: 'Operations' },
];

export default function IntelligenceLayout() {
  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <Search size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">Intelligence</h1>
        </div>
        <p className="page-layout__subtitle">
          Search analytics, attribution, AI visibility, connectors, and operations.
        </p>
      </div>

      <nav className="sub-nav" aria-label="Intelligence navigation">
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
