import { NavLink, Outlet } from 'react-router-dom';
import { Search, BarChart3, MapPin, Zap, Link2, Eye, Users2, Plug, Activity, LayoutDashboard } from 'lucide-react';

const NAV_ITEMS = [
  { label: 'Overview',     path: '/intelligence',                  icon: LayoutDashboard, end: true },
  { section: 'Search' },
  { label: 'Search',       path: '/intelligence/search',           icon: Search,   end: true },
  { label: 'Queries',      path: '/intelligence/search/queries',   icon: Search },
  { label: 'Pages',        path: '/intelligence/search/pages',     icon: BarChart3 },
  { section: 'Content' },
  { label: 'Performance',  path: '/intelligence/content',          icon: BarChart3, end: true },
  { section: 'Sitemaps & Index' },
  { label: 'Sitemaps',     path: '/intelligence/sitemaps',         icon: MapPin },
  { label: 'IndexNow',     path: '/intelligence/indexnow',         icon: Zap },
  { section: 'Attribution' },
  { label: 'Attribution',  path: '/intelligence/attribution',      icon: Link2,    end: true },
  { label: 'UTM Templates',path: '/intelligence/attribution/utm',  icon: Link2 },
  { section: 'Visibility' },
  { label: 'AI Visibility', path: '/intelligence/visibility',      icon: Eye,      end: true },
  { label: 'Prompt Library',path: '/intelligence/visibility/prompts', icon: Eye },
  { label: 'Run History',  path: '/intelligence/visibility/runs',  icon: Eye },
  { section: 'Competitors' },
  { label: 'Competitors',  path: '/intelligence/competitors',      icon: Users2 },
  { section: 'Operations' },
  { label: 'Connectors',   path: '/intelligence/connectors',       icon: Plug },
  { label: 'Operations',   path: '/intelligence/operations',       icon: Activity },
];

export default function IntelligenceLayout() {
  return (
    <div className="flex h-full min-h-screen bg-gray-50">
      <aside className="w-56 shrink-0 bg-white border-r border-gray-200 overflow-y-auto">
        <div className="p-4 border-b border-gray-100">
          <div className="flex items-center gap-2">
            <Search className="h-5 w-5 text-blue-600" />
            <span className="font-semibold text-gray-800 text-sm">Intelligence</span>
          </div>
        </div>
        <nav className="p-3">
          {NAV_ITEMS.map((item, i) => {
            if (item.section) {
              return (
                <p key={i} className="text-xs font-bold uppercase tracking-wider text-gray-400 px-2 pt-4 pb-1">
                  {item.section}
                </p>
              );
            }
            return (
              <NavLink
                key={item.path}
                to={item.path}
                end={!!item.end}
                className={({ isActive }) =>
                  `flex items-center gap-2 px-2 py-1.5 rounded-md text-sm mb-0.5 transition-colors ${
                    isActive ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-600 hover:bg-gray-50'
                  }`
                }
              >
                <item.icon className="h-4 w-4 shrink-0" />
                {item.label}
              </NavLink>
            );
          })}
        </nav>
      </aside>
      <main className="flex-1 overflow-y-auto">
        <Outlet />
      </main>
    </div>
  );
}
