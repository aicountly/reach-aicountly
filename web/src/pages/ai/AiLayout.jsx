import React from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { ROUTES } from '../../constants/routes.js';
import { usePermission } from '../../hooks/usePermission.js';

const NAV_ITEMS = [
  { to: ROUTES.AI_DASHBOARD,   label: 'Dashboard',   perm: 'ai.view' },
  { to: ROUTES.AI_PROVIDERS,   label: 'Providers',   perm: 'ai_provider.view' },
  { to: ROUTES.AI_MODELS,      label: 'Models',      perm: 'ai_provider.view' },
  { to: ROUTES.AI_ROUTING,     label: 'Routing',     perm: 'ai_provider.view' },
  { to: ROUTES.AI_PROMPTS,     label: 'Prompts',     perm: 'ai_prompt.view' },
  { to: ROUTES.AI_GENERATIONS, label: 'Generations', perm: 'ai.view' },
  { to: ROUTES.AI_USAGE,       label: 'Usage',       perm: 'ai.view' },
  { to: ROUTES.AI_BUDGETS,     label: 'Budgets',     perm: 'ai_provider.manage' },
  { to: ROUTES.AI_VALIDATIONS, label: 'Validations', perm: 'ai.view' },
  { to: ROUTES.AI_HEALTH,      label: 'Health',      perm: 'ai_provider.view' },
];

export default function AiLayout() {
  const { has } = usePermission();

  return (
    <div className="flex flex-col min-h-screen">
      <div className="bg-white border-b border-gray-200 px-4 py-3">
        <div className="flex items-center gap-2 mb-1">
          <span className="text-lg font-bold text-gray-900">AI Control Centre</span>
          <span className="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded font-medium">Phase 3</span>
        </div>
        <p className="text-xs text-gray-500">Manage AI providers, prompts, generation requests, usage and budgets.</p>
      </div>

      <div className="border-b border-gray-200 bg-gray-50">
        <nav className="flex gap-0 overflow-x-auto px-4" aria-label="AI navigation">
          {NAV_ITEMS.filter(item => has(item.perm)).map(item => (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) =>
                `px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${
                  isActive
                    ? 'border-purple-600 text-purple-700'
                    : 'border-transparent text-gray-600 hover:text-gray-900 hover:border-gray-300'
                }`
              }
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
      </div>

      <div className="flex-1 p-4 md:p-6">
        <Outlet />
      </div>
    </div>
  );
}
