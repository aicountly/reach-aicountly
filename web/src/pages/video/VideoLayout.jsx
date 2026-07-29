import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { MonitorSmartphone } from 'lucide-react';

const navItems = [
  { to: '/video',              label: 'Overview', end: true },
  { to: '/video/ideas',        label: 'Idea Backlog' },
  { to: '/video/projects',     label: 'Projects' },
  { to: '/video/render-queue', label: 'Render Queue' },
  { to: '/video/publications', label: 'Publications' },
  { to: '/video/connections',  label: 'YT Connections' },
  { to: '/video/operations',   label: 'Operations' },
];

export default function VideoLayout() {
  const { pathname } = useLocation();
  const onOverview = pathname === '/video';

  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <MonitorSmartphone size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">Video Automation</h1>
        </div>
        <p className="page-layout__subtitle">
          Idea backlog, projects, render queue, and YouTube publication
        </p>
      </div>

      <nav className="sub-nav" aria-label="Video navigation">
        {navItems.map(({ to, label, end }) => (
          <NavLink
            key={to}
            to={to}
            end={!!end}
            className={({ isActive }) =>
              `sub-nav__link${(end ? onOverview : isActive) ? ' sub-nav__link--active' : ''}`
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
