import { NavLink, Outlet } from 'react-router-dom';
import { usePermission } from '../../hooks/usePermission';
import { ROUTES } from '../../constants/routes';
import {
  BookOpen, Users, Building2, Globe2, HelpCircle,
  Search, Layers, Link2, Quote, ShieldAlert, FileText, Tag,
} from 'lucide-react';

const KNOWLEDGE_NAV = [
  { label: 'Overview',          path: ROUTES.KNOWLEDGE,            icon: BookOpen,    end: true, requires: 'knowledge.view' },
  { label: 'Products',          path: ROUTES.KNOWLEDGE_PRODUCTS,   icon: Tag,         requires: 'product.view' },
  { label: 'Personas',          path: ROUTES.KNOWLEDGE_PERSONAS,   icon: Users,       requires: 'persona.view' },
  { label: 'Industries',        path: ROUTES.KNOWLEDGE_INDUSTRIES, icon: Building2,   requires: 'industry.view' },
  { label: 'Markets',           path: ROUTES.KNOWLEDGE_MARKETS,    icon: Globe2,      requires: 'knowledge.view' },
  { label: 'Business Problems', path: ROUTES.KNOWLEDGE_PROBLEMS,   icon: HelpCircle,  requires: 'knowledge.view' },
  { label: 'Search Intents',    path: ROUTES.KNOWLEDGE_INTENTS,    icon: Search,      requires: 'intent.view' },
  { label: 'Topic Clusters',    path: ROUTES.KNOWLEDGE_CLUSTERS,   icon: Layers,      requires: 'knowledge.view' },
  { label: 'Sources',           path: ROUTES.KNOWLEDGE_SOURCES,    icon: Link2,       requires: 'source.view' },
  { label: 'Citations',         path: ROUTES.KNOWLEDGE_CITATIONS,  icon: Quote,       requires: 'citation.view' },
  { label: 'Claims',            path: ROUTES.KNOWLEDGE_CLAIMS,     icon: ShieldAlert, requires: 'claim.view' },
  { label: 'Brand Rules',       path: ROUTES.KNOWLEDGE_BRAND_RULES,icon: FileText,    requires: 'brand_rules.view' },
  { label: 'Content Policies',  path: ROUTES.KNOWLEDGE_POLICIES,   icon: FileText,    requires: 'content_policy.view' },
];

export function KnowledgeLayout() {
  const { has } = usePermission();
  return (
    <div style={{ display: 'flex', gap: 0, minHeight: '100%' }}>
      <aside style={{
        width: 200, flexShrink: 0, borderRight: '1px solid var(--color-border)',
        padding: '1rem 0',
      }}>
        <div style={{ padding: '0 1rem 0.5rem', fontSize: 11, fontWeight: 700, textTransform: 'uppercase', color: '#6b7280' }}>
          Knowledge
        </div>
        {KNOWLEDGE_NAV.filter(n => !n.requires || has(n.requires)).map(n => (
          <NavLink
            key={n.path}
            to={n.path}
            end={n.end}
            style={({ isActive }) => ({
              display: 'flex', alignItems: 'center', gap: 8,
              padding: '0.45rem 1rem',
              fontSize: 13,
              color: isActive ? 'var(--color-primary, #3b82f6)' : 'var(--color-text, #111)',
              background: isActive ? 'var(--color-primary-light, #eff6ff)' : 'transparent',
              textDecoration: 'none',
              borderRadius: 6,
              margin: '1px 6px',
            })}
          >
            <n.icon size={14} />
            {n.label}
          </NavLink>
        ))}
      </aside>
      <main style={{ flex: 1, padding: '1.5rem', overflow: 'auto' }}>
        <Outlet />
      </main>
    </div>
  );
}
