import { NavLink, Outlet } from 'react-router-dom';
import { Search } from 'lucide-react';

const navItems = [
  { to: '/seo-centre/overview', label: 'Overview' },
  { to: '/seo-centre/keywords', label: 'Tracked Keywords' },
  { to: '/seo-centre/backlinks', label: 'Backlinks' },
  { to: '/seo-centre/suggestions', label: 'Suggestions' },
  { to: '/seo-centre/search', label: 'Search Console' },
  { to: '/seo-centre/indexnow', label: 'IndexNow' },
  { to: '/seo-centre/sitemap', label: 'Sitemap' },
  { to: '/seo-centre/connections', label: 'Connections' },
];

export default function SeoLayout() {
  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <Search size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">SEO Command Centre</h1>
        </div>
        <p className="page-layout__subtitle">
          Rankings, indexing, backlink health and search-engine connections for aicountly.com
        </p>
      </div>

      <nav className="sub-nav" aria-label="SEO navigation">
        {navItems.map(({ to, label }) => (
          <NavLink
            key={to}
            to={to}
            className={({ isActive }) => `sub-nav__link${isActive ? ' sub-nav__link--active' : ''}`}
          >
            {label}
          </NavLink>
        ))}
      </nav>

      <div className="page-layout__content">
        <Outlet />
      </div>
    </div>
  );
}
