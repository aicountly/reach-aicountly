import { NavLink, Outlet } from 'react-router-dom';

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
