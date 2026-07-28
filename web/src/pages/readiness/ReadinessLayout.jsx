import { NavLink, Outlet } from 'react-router-dom';
import {
  LayoutDashboard, RefreshCw, Target, BarChart3, ShieldCheck,
  Lock, Bot, Database, Activity, Server, Wrench, CheckSquare,
} from 'lucide-react';

const NAV = [
  { path: '/readiness',                   label: 'Overview',           Icon: LayoutDashboard },
  { path: '/readiness/refresh',           label: 'Recommendations',    Icon: RefreshCw },
  { path: '/readiness/outcomes',          label: 'Outcomes',           Icon: Target },
  { path: '/readiness/attribution',       label: 'Attribution',        Icon: BarChart3 },
  { path: '/readiness/security',          label: 'Security',           Icon: ShieldCheck },
  { path: '/readiness/privacy',           label: 'Privacy',            Icon: Lock },
  { path: '/readiness/ai-governance',     label: 'AI Governance',      Icon: Bot },
  { path: '/readiness/migrations',        label: 'Migrations',         Icon: Database },
  { path: '/readiness/operations',        label: 'Operations',         Icon: Activity },
  { path: '/readiness/disaster-recovery', label: 'Disaster Recovery',  Icon: Server },
  { path: '/readiness/technical-debt',    label: 'Technical Debt',     Icon: Wrench },
  { path: '/readiness/release',           label: 'Release Acceptance', Icon: CheckSquare },
];

export default function ReadinessLayout() {
  return (
    <div style={{ display: 'flex', minHeight: 'calc(100vh - var(--header-height) - 2.5rem)' }}>
      <nav style={{
        width: 220,
        flexShrink: 0,
        borderRight: '1px solid var(--color-border)',
        background: 'var(--color-surface)',
        overflowY: 'auto',
      }}>
        <div style={{
          padding: '0.85rem 1rem',
          borderBottom: '1px solid var(--color-border)',
        }}>
          <p style={{
            fontSize: '0.65rem',
            fontWeight: 700,
            textTransform: 'uppercase',
            letterSpacing: '0.05em',
            color: 'var(--color-text-muted)',
            margin: 0,
          }}>
            Product Readiness
          </p>
        </div>
        <ul style={{ listStyle: 'none', margin: 0, padding: '0.5rem 0.6rem' }}>
          {NAV.map((navItem) => {
            const NavIcon = navItem.Icon;
            return (
              <li key={navItem.path} style={{ marginBottom: '0.1rem' }}>
                <NavLink
                  to={navItem.path}
                  end={navItem.path === '/readiness'}
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
                  })}
                >
                  <NavIcon size={15} style={{ flexShrink: 0 }} />
                  <span style={{ flex: 1, minWidth: 0 }}>{navItem.label}</span>
                </NavLink>
              </li>
            );
          })}
        </ul>
      </nav>
      <main style={{ flex: 1, minWidth: 0, overflowY: 'auto', background: 'var(--color-bg)' }}>
        <Outlet />
      </main>
    </div>
  );
}
