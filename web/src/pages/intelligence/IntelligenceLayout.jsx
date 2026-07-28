import { NavLink, Outlet } from 'react-router-dom';
import { Search, BarChart3, MapPin, Zap, Link2, Eye, Users2, Plug, Activity, LayoutDashboard } from 'lucide-react';

const NAV_ITEMS = [
  { label: 'Overview',      path: '/intelligence',                     icon: LayoutDashboard, end: true },
  { section: 'Search' },
  { label: 'Search',        path: '/intelligence/search',              icon: Search, end: true },
  { label: 'Queries',       path: '/intelligence/search/queries',      icon: Search },
  { label: 'Pages',         path: '/intelligence/search/pages',        icon: BarChart3 },
  { section: 'Content' },
  { label: 'Performance',   path: '/intelligence/content',             icon: BarChart3, end: true },
  { section: 'Sitemaps & Index' },
  { label: 'Sitemaps',      path: '/intelligence/sitemaps',            icon: MapPin },
  { label: 'IndexNow',      path: '/intelligence/indexnow',            icon: Zap },
  { section: 'Attribution' },
  { label: 'Attribution',   path: '/intelligence/attribution',         icon: Link2, end: true },
  { label: 'UTM Templates', path: '/intelligence/attribution/utm',     icon: Link2 },
  { section: 'Visibility' },
  { label: 'AI Visibility', path: '/intelligence/visibility',          icon: Eye, end: true },
  { label: 'Prompt Library', path: '/intelligence/visibility/prompts', icon: Eye },
  { label: 'Run History',   path: '/intelligence/visibility/runs',     icon: Eye },
  { section: 'Competitors' },
  { label: 'Competitors',   path: '/intelligence/competitors',         icon: Users2 },
  { section: 'Operations' },
  { label: 'Connectors',    path: '/intelligence/connectors',          icon: Plug },
  { label: 'Operations',    path: '/intelligence/operations',          icon: Activity },
];

export default function IntelligenceLayout() {
  return (
    <div style={{ display: 'flex', minHeight: 'calc(100vh - var(--header-height) - 2.5rem)', background: 'var(--color-bg)' }}>
      <aside style={{
        width: 220,
        flexShrink: 0,
        background: 'var(--color-surface)',
        borderRight: '1px solid var(--color-border)',
        overflowY: 'auto',
      }}>
        <div style={{
          padding: '0.85rem 1rem',
          borderBottom: '1px solid var(--color-border)',
          display: 'flex',
          alignItems: 'center',
          gap: '0.5rem',
        }}>
          <Search size={18} style={{ color: 'var(--color-primary)', flexShrink: 0 }} />
          <span style={{ fontWeight: 600, fontSize: '0.85rem', color: 'var(--color-text)' }}>
            Intelligence
          </span>
        </div>
        <nav style={{ padding: '0.6rem' }}>
          {NAV_ITEMS.map((item, i) => {
            if (item.section) {
              return (
                <p
                  key={`section-${i}`}
                  style={{
                    fontSize: '0.65rem',
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.05em',
                    color: 'var(--color-text-muted)',
                    padding: '0.85rem 0.6rem 0.25rem',
                    margin: 0,
                  }}
                >
                  {item.section}
                </p>
              );
            }
            return (
              <NavLink
                key={item.path}
                to={item.path}
                end={!!item.end}
                style={({ isActive }) => ({
                  display: 'flex',
                  alignItems: 'center',
                  gap: '0.55rem',
                  padding: '0.4rem 0.6rem',
                  borderRadius: 'var(--radius)',
                  fontSize: '0.83rem',
                  fontWeight: isActive ? 600 : 500,
                  color: isActive ? 'var(--color-primary-hover)' : 'var(--color-text)',
                  background: isActive ? 'var(--color-primary-light)' : 'transparent',
                  textDecoration: 'none',
                  marginBottom: '0.1rem',
                })}
              >
                <item.icon size={15} style={{ flexShrink: 0 }} />
                <span style={{ flex: 1, minWidth: 0 }}>{item.label}</span>
              </NavLink>
            );
          })}
        </nav>
      </aside>
      <main style={{ flex: 1, minWidth: 0, overflowY: 'auto' }}>
        <Outlet />
      </main>
    </div>
  );
}
