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
  const { has, hasAny } = usePermission();
  const canSeeProviders = hasAny(['ai_provider.view', 'ai_provider.manage']);

  const visible = NAV_ITEMS.filter((item) => {
    if (item.to === ROUTES.AI_PROVIDERS || item.to === ROUTES.AI_MODELS || item.to === ROUTES.AI_ROUTING || item.to === ROUTES.AI_HEALTH) {
      return canSeeProviders;
    }
    return has(item.perm);
  });

  return (
    <div style={{ display: 'flex', flexDirection: 'column', minHeight: '100%' }}>
      <div style={{
        background: 'var(--color-surface)',
        borderBottom: '1px solid var(--color-border)',
        padding: '0.85rem 1.25rem',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.2rem' }}>
          <span style={{ fontSize: '1.1rem', fontWeight: 700, color: 'var(--color-text)' }}>
            AI Control Centre
          </span>
          <span className="badge badge-primary">Phase 3</span>
        </div>
        <p className="text-xs text-muted">
          Manage AI providers, prompts, generation requests, usage and budgets.
        </p>
      </div>

      <div style={{
        borderBottom: '1px solid var(--color-border)',
        background: 'var(--color-bg)',
      }}>
        <nav
          aria-label="AI navigation"
          style={{
            display: 'flex',
            flexWrap: 'wrap',
            gap: '0.15rem 0.25rem',
            padding: '0 0.75rem',
            overflowX: 'auto',
          }}
        >
          {visible.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              style={({ isActive }) => ({
                display: 'inline-flex',
                alignItems: 'center',
                padding: '0.65rem 0.9rem',
                fontSize: '0.85rem',
                fontWeight: isActive ? 600 : 500,
                whiteSpace: 'nowrap',
                borderBottom: isActive
                  ? '2px solid var(--color-primary)'
                  : '2px solid transparent',
                color: isActive ? 'var(--color-primary-hover)' : 'var(--color-text-secondary)',
                textDecoration: 'none',
              })}
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
      </div>

      <div style={{ flex: 1, padding: '1.25rem 1.5rem' }}>
        <Outlet />
      </div>
    </div>
  );
}
