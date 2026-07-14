import { Outlet, NavLink } from 'react-router-dom';

const NAV_ITEMS = [
  { to: '/distribution',                  label: 'Overview',        end: true },
  { to: '/distribution/campaigns',        label: 'Campaigns' },
  { to: '/distribution/audience',         label: 'Audience' },
  { to: '/distribution/audience/segments',label: 'Segments' },
  { to: '/distribution/suppressions',     label: 'Suppressions' },
  { to: '/distribution/social',           label: 'Social' },
  { to: '/distribution/email',            label: 'Email' },
  { to: '/distribution/whatsapp',         label: 'WhatsApp' },
  { to: '/distribution/sms',              label: 'SMS' },
  { to: '/distribution/orchestration',    label: 'Orchestration' },
  { to: '/distribution/analytics',        label: 'Analytics' },
];

export default function DistributionLayout() {
  return (
    <div className="section-layout">
      <nav className="section-nav" aria-label="Distribution navigation">
        <div className="section-nav__title">Distribution</div>
        {NAV_ITEMS.map(({ to, label, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            className={({ isActive }) =>
              `section-nav__item${isActive ? ' section-nav__item--active' : ''}`
            }
          >
            {label}
          </NavLink>
        ))}
      </nav>
      <main className="section-content">
        <Outlet />
      </main>
    </div>
  );
}
