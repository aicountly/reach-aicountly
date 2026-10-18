import { useEffect, useRef } from 'react';
import { NavLink } from 'react-router-dom';
import { ExternalLink, PanelLeftClose, PanelLeftOpen, X } from 'lucide-react';
import { visibleNavSections } from '../../constants/navManifest';
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

export const SIDEBAR_ID = 'reach-primary-nav';

/**
 * Primary navigation.
 *
 * variant:
 *  - `full`   in-flow sidebar with labels (desktop, or tablet expanded)
 *  - `rail`   in-flow icon rail; labels stay in the accessible name + tooltip
 *  - `drawer` off-canvas panel (phone); `open` shows it as a modal dialog
 */
export function Sidebar({
  variant = 'full',
  open = false,
  onClose,
  onToggleRail,
  railToggleLabel,
}) {
  const counts = useReachCounts();
  const { has } = usePermission();
  const { user } = useAuth();
  const closeRef = useRef(null);
  const role = user?.role_slug || user?.role || '';
  const roleLabel = user?.role_name || ROLE_LABELS[role] || role.replace(/_/g, ' ');
  const sections = visibleNavSections(has);
  const isRail = variant === 'rail';
  const isDrawer = variant === 'drawer';
  const hidden = isDrawer && !open;

  // Drawer: move focus in on open; Tab stays inside while it is open.
  useEffect(() => {
    if (!isDrawer || !open) return undefined;
    closeRef.current?.focus();
    return undefined;
  }, [isDrawer, open]);

  const onKeyDown = (e) => {
    if (!isDrawer || !open) return;
    if (e.key === 'Escape') {
      e.stopPropagation();
      onClose?.();
      return;
    }
    if (e.key !== 'Tab') return;
    const focusables = e.currentTarget.querySelectorAll('a[href], button:not([disabled])');
    if (focusables.length === 0) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };

  const dialogProps = isDrawer && open
    ? { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Navigation' }
    : { 'aria-label': 'Primary' };

  return (
    <aside
      id={SIDEBAR_ID}
      className={`reach-sidebar reach-sidebar--${variant}${isDrawer && open ? ' reach-sidebar--open' : ''}`}
      data-testid="reach-sidebar"
      data-variant={variant}
      inert={hidden ? true : undefined}
      aria-hidden={hidden ? 'true' : undefined}
      onKeyDown={onKeyDown}
      {...dialogProps}
    >
      <div className="reach-sidebar__brand">
        <div className="reach-sidebar__brand-row">
          {isRail ? <ReachLogo height={30} compact /> : <ReachLogo height={30} />}
          {isDrawer ? (
            <button
              ref={closeRef}
              type="button"
              className="reach-icon-btn reach-sidebar__toggle"
              onClick={onClose}
              aria-label="Close navigation"
            >
              <X size={18} aria-hidden="true" />
            </button>
          ) : onToggleRail ? (
            <button
              type="button"
              className="reach-icon-btn reach-sidebar__toggle"
              onClick={onToggleRail}
              aria-label={railToggleLabel}
              aria-controls={SIDEBAR_ID}
              aria-expanded={!isRail}
              title={railToggleLabel}
              data-testid="sidebar-rail-toggle"
            >
              {isRail ? <PanelLeftOpen size={17} aria-hidden="true" /> : <PanelLeftClose size={17} aria-hidden="true" />}
            </button>
          ) : null}
        </div>
        {!isRail && roleLabel && (
          <div className="reach-sidebar__role" title={ROLES_MANAGED_TEXT} data-testid="sidebar-role">{roleLabel}</div>
        )}
      </div>

      <nav className="reach-sidebar__nav" aria-label="Main menu">
        {sections.map((section) => (
          <div key={section.id} className="reach-sidebar__section" role="group" aria-labelledby={`nav-group-${section.id}`}>
            <p id={`nav-group-${section.id}`} className="reach-sidebar__section-title">{section.title}</p>
            {section.items.map((item) => {
              const count = item.countKey != null ? Number(counts[item.countKey] ?? 0) : 0;
              const accessible = count > 0 ? `${item.label} (${count})` : item.label;
              const inner = (
                <>
                  <item.icon size={16} className="reach-sidebar__icon" aria-hidden="true" />
                  <span className="reach-sidebar__link-label">{item.label}</span>
                  {item.external && <ExternalLink size={12} className="reach-sidebar__ext" aria-hidden="true" />}
                  {count > 0 && <span className="reach-sidebar__count" aria-hidden="true">{count}</span>}
                </>
              );
              if (item.external) {
                return (
                  <a
                    key={item.id}
                    href={item.path}
                    target="_blank"
                    rel="noreferrer"
                    className="reach-sidebar__link"
                    title={item.hint || (isRail ? item.label : undefined)}
                    aria-label={isRail ? `${accessible} (opens Console)` : undefined}
                  >
                    {inner}
                  </a>
                );
              }
              return (
                <NavLink
                  key={item.id}
                  to={item.path}
                  end={!!item.end}
                  data-nav-id={item.id}
                  title={isRail ? accessible : undefined}
                  aria-label={isRail ? accessible : undefined}
                  className={({ isActive }) => `reach-sidebar__link${isActive ? ' reach-sidebar__link--active' : ''}`}
                >
                  {inner}
                </NavLink>
              );
            })}
          </div>
        ))}
      </nav>
    </aside>
  );
}
