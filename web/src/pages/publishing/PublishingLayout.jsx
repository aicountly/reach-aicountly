import { NavLink, Outlet } from 'react-router-dom';
import { Globe } from 'lucide-react';

const navItems = [
  { to: '/publishing/blogs', label: 'Blogs' },
  { to: '/publishing/knowledge-bases', label: 'Knowledge Base' },
  { to: '/publishing/calendar', label: 'Calendar' },
  { to: '/publishing/deployments', label: 'Deployments' },
  { to: '/publishing/verifications', label: 'Verifications' },
  { to: '/publishing/connections', label: 'Connections' },
  { to: '/publishing/readiness', label: 'Readiness' },
];

export default function PublishingLayout() {
  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <Globe size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">Publishing</h1>
          <span className="badge badge-primary">Phase 4</span>
        </div>
        <p className="page-layout__subtitle">
          Blog and knowledge-base deployments, verifications, and site connections.
        </p>
      </div>

      <nav className="sub-nav" aria-label="Publishing navigation">
        {navItems.map(({ to, label }) => (
          <NavLink
            key={to}
            to={to}
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
