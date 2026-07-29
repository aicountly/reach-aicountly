import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { MessagesSquare } from 'lucide-react';

const navItems = [
  { to: '/community/overview',    label: 'Overview', overview: true },
  { to: '/community/questions',   label: 'Question Inbox' },
  { to: '/community/answers',     label: 'Official Answers' },
  { to: '/community/identities',  label: 'Identities' },
  { to: '/community/moderation',  label: 'Moderation Queue' },
  { to: '/community/deployments', label: 'Deployments' },
  { to: '/community/analytics',   label: 'Analytics' },
  { to: '/community/settings',    label: 'Settings' },
];

export default function CommunityLayout() {
  const { pathname } = useLocation();
  const onOverview = pathname === '/community' || pathname === '/community/overview';

  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <MessagesSquare size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">Community Control Centre</h1>
        </div>
        <p className="page-layout__subtitle">
          Official Q&amp;A automation dashboard
        </p>
      </div>

      <nav className="sub-nav" aria-label="Community navigation">
        {navItems.map(({ to, label, overview }) => (
          <NavLink
            key={to}
            to={to}
            end={!overview}
            className={({ isActive }) =>
              `sub-nav__link${(overview ? onOverview : isActive) ? ' sub-nav__link--active' : ''}`
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
