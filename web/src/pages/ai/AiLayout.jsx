import React from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { BrainCircuit } from 'lucide-react';
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
  const { has, hasAny } = usePermission();
  const canSeeProviders = hasAny(['ai_provider.view', 'ai_provider.manage']);

  const visible = NAV_ITEMS.filter((item) => {
    if (item.to === ROUTES.AI_PROVIDERS || item.to === ROUTES.AI_MODELS || item.to === ROUTES.AI_ROUTING || item.to === ROUTES.AI_HEALTH) {
      return canSeeProviders;
    }
    return has(item.perm);
  });

  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <BrainCircuit size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">AI Control Centre</h1>
          <span className="badge badge-primary">Phase 3</span>
        </div>
        <p className="page-layout__subtitle">
          Manage AI providers, prompts, generation requests, usage and budgets.
        </p>
      </div>

      <nav className="sub-nav" aria-label="AI navigation">
        {visible.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            className={({ isActive }) =>
              `sub-nav__link${isActive ? ' sub-nav__link--active' : ''}`
            }
          >
            {item.label}
          </NavLink>
        ))}
      </nav>

      <div className="page-layout__body">
        <Outlet />
      </div>
    </div>
  );
}
