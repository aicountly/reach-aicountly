import { NavLink, Outlet } from 'react-router-dom';

const navItems = [
  { to: '/community/overview',    label: 'Overview' },
  { to: '/community/questions',   label: 'Question Inbox' },
  { to: '/community/answers',     label: 'Official Answers' },
  { to: '/community/identities',  label: 'Identities' },
  { to: '/community/moderation',  label: 'Moderation Queue' },
  { to: '/community/deployments', label: 'Deployments' },
  { to: '/community/analytics',   label: 'Analytics' },
  { to: '/community/settings',    label: 'Settings' },
];

export default function CommunityLayout() {
  return (
    <div className="page-layout">
      <nav className="sub-nav">
        {navItems.map(({ to, label }) => (
          <NavLink
            key={to}
            to={to}
            className={({ isActive }) => 'sub-nav__link' + (isActive ? ' sub-nav__link--active' : '')}
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
