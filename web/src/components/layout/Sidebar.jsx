import { NavLink } from 'react-router-dom';
import { buildNavSections } from '../../constants/navManifest';
import { BotModeBadge } from '../bot/BotModeBadge';
import { ReachLogo } from '../brand/ReachLogo';
import { useReachCounts } from '../../context/ReachCountsContext';
import { usePermission } from '../../hooks/usePermission';
import { useAuth } from '../../context/AuthContext';
import { ROLES_MANAGED_TEXT } from '../auth/RolesManagedNote';

const ROLE_LABELS = {
  super_admin: 'Superadmin',
  reach_admin: 'Reach Admin',
  marketing_manager: 'Marketing Manager',
  content_reviewer: 'Content Reviewer',
};

export function Sidebar() {
  const counts = useReachCounts();
  const { has } = usePermission();
  const { user } = useAuth();
  const role = user?.role_slug || user?.role || '';
  const roleLabel = user?.role_name || ROLE_LABELS[role] || role.replace(/_/g, ' ');
  const visibleSections = buildNavSections()
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => !item.requires || has(item.requires)),
    }))
    .filter((section) => section.items.length > 0);
  return (
    <aside className="reach-sidebar" aria-label="Primary">
      <div className="reach-sidebar__brand">
        <ReachLogo height={32} />
        <div className="text-xs text-muted mt-1" title={ROLES_MANAGED_TEXT} data-testid="sidebar-role">{roleLabel}</div>
        <div className="mt-2"><BotModeBadge /></div>
      </div>

      <nav className="reach-sidebar__nav">
        {visibleSections.map((section) => (
          <div key={section.title} className="reach-sidebar__section">
            <p className="reach-sidebar__section-title">{section.title}</p>
            {section.items.map((item) => (
              <NavLink
                key={item.path}
                to={item.path}
                end={!!item.end}
                className={({ isActive }) =>
                  `reach-sidebar__link${isActive ? ' reach-sidebar__link--active' : ''}`
                }
              >
                <item.icon size={15} style={{ flexShrink: 0 }} aria-hidden="true" />
                <span className="reach-sidebar__link-label">{item.label}</span>
                {item.countKey != null && counts[item.countKey] > 0 && (
                  <span className="reach-sidebar__count">
                    {counts[item.countKey]}
                  </span>
                )}
              </NavLink>
            ))}
          </div>
        ))}
      </nav>
    </aside>
  );
}
