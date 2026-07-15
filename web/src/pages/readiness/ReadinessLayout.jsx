import { NavLink, Outlet } from 'react-router-dom';
import {
  LayoutDashboard, RefreshCw, Target, BarChart3, ShieldCheck,
  Lock, Bot, Database, Activity, Server, HardDrive, Wrench, CheckSquare,
} from 'lucide-react';

const NAV = [
  { path: '/readiness',                  label: 'Overview',           icon: LayoutDashboard },
  { path: '/readiness/refresh',          label: 'Recommendations',    icon: RefreshCw },
  { path: '/readiness/outcomes',         label: 'Outcomes',           icon: Target },
  { path: '/readiness/attribution',      label: 'Attribution',        icon: BarChart3 },
  { path: '/readiness/security',         label: 'Security',           icon: ShieldCheck },
  { path: '/readiness/privacy',          label: 'Privacy',            icon: Lock },
  { path: '/readiness/ai-governance',    label: 'AI Governance',      icon: Bot },
  { path: '/readiness/migrations',       label: 'Migrations',         icon: Database },
  { path: '/readiness/operations',       label: 'Operations',         icon: Activity },
  { path: '/readiness/disaster-recovery',label: 'Disaster Recovery',  icon: Server },
  { path: '/readiness/technical-debt',   label: 'Technical Debt',     icon: Wrench },
  { path: '/readiness/release',          label: 'Release Acceptance', icon: CheckSquare },
];

export default function ReadinessLayout() {
  return (
    <div className="flex h-full">
      <nav className="w-56 shrink-0 border-r border-gray-200 bg-white overflow-y-auto">
        <div className="px-4 py-3 border-b border-gray-100">
          <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide">Product Readiness</p>
        </div>
        <ul className="py-2">
          {NAV.map(({ path, label, icon: Icon }) => (
            <li key={path}>
              <NavLink
                to={path}
                end={path === '/readiness'}
                className={({ isActive }) =>
                  `flex items-center gap-2 px-4 py-2 text-sm transition-colors ${
                    isActive
                      ? 'bg-blue-50 text-blue-700 font-medium'
                      : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                  }`
                }
              >
                <Icon className="w-4 h-4 shrink-0" />
                {label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
      <main className="flex-1 overflow-y-auto bg-gray-50">
        <Outlet />
      </main>
    </div>
  );
}
