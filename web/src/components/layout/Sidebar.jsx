import { NavLink } from 'react-router-dom';
import { buildNavSections } from '../../constants/navManifest';
import { BotModeBadge } from '../bot/BotModeBadge';
import { ReachLogo } from '../brand/ReachLogo';
import { useReachCounts } from '../../context/ReachCountsContext';
import { usePermission } from '../../hooks/usePermission';

export function Sidebar() {
  const counts = useReachCounts();
  const { has } = usePermission();
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
        <div className="text-xs text-muted mt-1">Superadmin</div>
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
