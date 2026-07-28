import { Outlet, NavLink } from 'react-router-dom';

const NAV_ITEMS = [
  { to: '/distribution',                   label: 'Overview',     end: true },
  { to: '/distribution/campaigns',         label: 'Campaigns' },
  { to: '/distribution/audience',          label: 'Audience',     end: true },
  { to: '/distribution/audience/segments', label: 'Segments' },
  { to: '/distribution/audience/consents', label: 'Consents' },
  { to: '/distribution/suppressions',      label: 'Suppressions' },
  { to: '/distribution/social',            label: 'Social' },
  { to: '/distribution/email',             label: 'Email' },
  { to: '/distribution/whatsapp',          label: 'WhatsApp' },
  { to: '/distribution/sms',               label: 'SMS' },
  { to: '/distribution/orchestration',     label: 'Orchestration' },
  { to: '/distribution/analytics',         label: 'Analytics' },
];

const layoutStyle = {
  display: 'flex',
  alignItems: 'stretch',
  minHeight: 'calc(100vh - var(--header-height) - 2.5rem)',
  background: 'var(--color-bg)',
};

const navStyle = {
  display: 'flex',
  flexDirection: 'column',
  width: 200,
  flexShrink: 0,
  background: 'var(--color-surface)',
  borderRight: '1px solid var(--color-border)',
  padding: '0.75rem',
  overflowY: 'auto',
};

const titleStyle = {
  display: 'block',
  fontSize: '0.85rem',
  fontWeight: 700,
  padding: '0.4rem 0.6rem 0.75rem',
  color: 'var(--color-text)',
};

const contentStyle = {
  flex: 1,
  minWidth: 0,
  padding: '1.25rem 1.5rem',
  overflowY: 'auto',
};

function itemStyle(isActive) {
  return {
    display: 'block',
    padding: '0.4rem 0.6rem',
    borderRadius: 'var(--radius)',
    fontSize: '0.83rem',
    fontWeight: isActive ? 600 : 500,
    color: isActive ? 'var(--color-primary-hover)' : 'var(--color-text)',
    background: isActive ? 'var(--color-primary-light)' : 'transparent',
    textDecoration: 'none',
    marginBottom: '0.1rem',
    whiteSpace: 'nowrap',
  };
}

export default function DistributionLayout() {
  return (
    <div className="section-layout" style={layoutStyle}>
      <nav className="section-nav" aria-label="Distribution navigation" style={navStyle}>
        <div className="section-nav__title" style={titleStyle}>Distribution</div>
        {NAV_ITEMS.map(({ to, label, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            className={({ isActive }) =>
              `section-nav__item${isActive ? ' section-nav__item--active' : ''}`
            }
            style={({ isActive }) => itemStyle(isActive)}
          >
            {label}
          </NavLink>
        ))}
      </nav>
      <main className="section-content" style={contentStyle}>
        <Outlet />
      </main>
    </div>
  );
}
