import { Outlet, NavLink } from 'react-router-dom';
import { Send } from 'lucide-react';

const navItems = [
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

export default function DistributionLayout() {
  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <Send size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">Distribution</h1>
        </div>
        <p className="page-layout__subtitle">
          Campaigns, audience, channels, orchestration, and analytics.
        </p>
      </div>

      <nav className="sub-nav" aria-label="Distribution navigation">
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
