import { NavLink, Outlet } from 'react-router-dom';
import { usePermission } from '../../hooks/usePermission';
import { ROUTES } from '../../constants/routes';
import { BookOpen } from 'lucide-react';

const KNOWLEDGE_NAV = [
  { label: 'Overview',          path: ROUTES.KNOWLEDGE,              end: true, requires: 'knowledge.view' },
  { label: 'Products',          path: ROUTES.KNOWLEDGE_PRODUCTS,     requires: 'product.view' },
  { label: 'Personas',          path: ROUTES.KNOWLEDGE_PERSONAS,     requires: 'persona.view' },
  { label: 'Industries',        path: ROUTES.KNOWLEDGE_INDUSTRIES,   requires: 'industry.view' },
  { label: 'Markets',           path: ROUTES.KNOWLEDGE_MARKETS,      requires: 'knowledge.view' },
  { label: 'Business Problems', path: ROUTES.KNOWLEDGE_PROBLEMS,     requires: 'knowledge.view' },
  { label: 'Search Intents',    path: ROUTES.KNOWLEDGE_INTENTS,      requires: 'intent.view' },
  { label: 'Topic Clusters',    path: ROUTES.KNOWLEDGE_CLUSTERS,     requires: 'knowledge.view' },
  { label: 'Sources',           path: ROUTES.KNOWLEDGE_SOURCES,      requires: 'source.view' },
  { label: 'Citations',         path: ROUTES.KNOWLEDGE_CITATIONS,    requires: 'citation.view' },
  { label: 'Claims',            path: ROUTES.KNOWLEDGE_CLAIMS,       requires: 'claim.view' },
  { label: 'Brand Rules',       path: ROUTES.KNOWLEDGE_BRAND_RULES,  requires: 'brand_rules.view' },
  { label: 'Content Policies',  path: ROUTES.KNOWLEDGE_POLICIES,     requires: 'content_policy.view' },
  { label: 'Completeness',      path: ROUTES.KNOWLEDGE_COMPLETENESS, requires: 'knowledge.view' },
];

export function KnowledgeLayout() {
  const { has } = usePermission();
  const visible = KNOWLEDGE_NAV.filter((n) => !n.requires || has(n.requires));

  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <BookOpen size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">Knowledge Foundation</h1>
        </div>
        <p className="page-layout__subtitle">
          Products, personas, claims, brand rules, and knowledge completeness.
        </p>
      </div>

      <nav className="sub-nav" aria-label="Knowledge navigation">
        {visible.map(({ path, label, end }) => (
          <NavLink
            key={path}
            to={path}
            end={end}
            className={({ isActive }) =>
              `sub-nav__link${isActive ? ' sub-nav__link--active' : ''}`
            }
          >
            {label}
          </NavLink>
        ))}
      </nav>

      <div className="page-layout__body">
        <Outlet />
      </div>
    </div>
  );
}
