import { NavLink, Outlet, Link } from 'react-router-dom';
import { usePermission } from '../../hooks/usePermission';
import { canSee } from '../../constants/navManifest';

/**
 * Section chrome shared by the merged areas (Social & Ads, Analytics &
 * reports, Leads & Engage): title, one line of context, a tab strip and the
 * tab's page. Tabs the user may not open are left out; `related` links point
 * at neighbouring pages that live in another section.
 *
 * @param {{
 *   title: string, subtitle?: string, icon?: any, label: string,
 *   tabs: { to: string, label: string, end?: boolean, requires?: string|string[], count?: number }[],
 *   related?: { to: string, label: string, requires?: string|string[] }[],
 *   children?: any,
 * }} props
 */
export function HubLayout({ title, subtitle, icon: Icon, label, tabs, related = [], children }) {
  const { has } = usePermission();
  const visibleTabs = tabs.filter((t) => canSee(t, has));
  const visibleRelated = related.filter((r) => canSee(r, has));
  return (
    <div className="page-layout page-layout--flush hub">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          {Icon && <Icon size={18} className="page-layout__icon" aria-hidden="true" />}
          <h1 className="page-layout__title">{title}</h1>
        </div>
        {subtitle && <p className="page-layout__subtitle hub__subtitle">{subtitle}</p>}
        {visibleRelated.length > 0 && (
          <p className="hub__related">
            <span className="hub__related-label">Related:</span>
            {visibleRelated.map((r) => <Link key={r.to} to={r.to} className="hub__related-link">{r.label}</Link>)}
          </p>
        )}
      </div>
      {visibleTabs.length > 1 && (
        <nav className="sub-nav hub__tabs" aria-label={label}>
          {visibleTabs.map((t) => (
            <NavLink
              key={t.to}
              to={t.to}
              end={!!t.end}
              className={({ isActive }) => `sub-nav__link${isActive ? ' sub-nav__link--active' : ''}`}
            >
              {t.label}
              {t.count > 0 && <span className="hub__count" aria-label={`${t.count} pending`}>{t.count}</span>}
            </NavLink>
          ))}
        </nav>
      )}
      <div className="page-layout__body">
        {children ?? <Outlet />}
      </div>
    </div>
  );
}
