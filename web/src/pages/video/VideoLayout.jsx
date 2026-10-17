import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { MonitorSmartphone } from 'lucide-react';

// The old Render Queue / Publications / YT Connections screens drove a manual
// render-and-publish path that never worked; rendering and YouTube uploads now
// run only in the automated pipeline (index tab), so they are not offered here.
const navItems = [
  { to: '/video',            label: 'Pipeline', end: true },
  { to: '/video/ideas',      label: 'Idea Backlog' },
  { to: '/video/projects',   label: 'Projects' },
  { to: '/video/operations', label: 'Operations' },
];

export default function VideoLayout() {
  const { pathname } = useLocation();
  const onIndex = pathname === '/video';

  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <MonitorSmartphone size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">Video Automation</h1>
        </div>
        <p className="page-layout__subtitle">
          Daily YouTube vlog and Short: routine script → Gemini vetting → voice-over → render → YouTube
        </p>
      </div>

      <nav className="sub-nav" aria-label="Video navigation">
        {navItems.map(({ to, label, end }) => (
          <NavLink
            key={to}
            to={to}
            end={!!end}
            className={({ isActive }) =>
              `sub-nav__link${(end ? onIndex : isActive) ? ' sub-nav__link--active' : ''}`
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
