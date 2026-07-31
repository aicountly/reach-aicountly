import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { NotebookPen } from 'lucide-react';
import { Breadcrumbs } from '../../components/common/Breadcrumbs';
import { BlogScopeProvider } from '../../context/BlogScopeContext';
import { usePermission } from '../../hooks/usePermission';
import { isBlogCommandCentreEnabled } from '../../constants/blogFeatureFlags';
import {
  BCC_SECTIONS,
  buildBccBreadcrumbs,
  findBccSectionByPath,
} from '../../constants/blogCommandCentreNav';
import { ROUTES } from '../../constants/routes';

export function BlogCommandCentreLayout() {
  const { pathname } = useLocation();
  const { hasAny, has } = usePermission();
  const enabled = isBlogCommandCentreEnabled();
  const canAccess = hasAny(['blog.view', 'content.view', 'publishing.view']);

  if (!enabled || !canAccess) return null;

  const activeSection = findBccSectionByPath(pathname);
  const breadcrumbItems = buildBccBreadcrumbs(pathname);
  const visibleLeaves = (activeSection?.leaves ?? []).filter(
    (leaf) => !leaf.requires || has(leaf.requires),
  );

  const onOverview = pathname === ROUTES.BLOG_COMMAND_CENTRE;

  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <NotebookPen size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">Blog Command Centre</h1>
        </div>
        <p className="page-layout__subtitle">
          Blog planning, production, verification, publishing, and analytics in one place.
        </p>
        <Breadcrumbs items={breadcrumbItems} />
      </div>

      <nav className="sub-nav" aria-label="Blog Command Centre sections">
        {BCC_SECTIONS.map(({ path, label, end, id }) => (
          <NavLink
            key={id}
            to={path}
            end={end ?? false}
            className={({ isActive }) => {
              const active = id === 'overview' ? onOverview : isActive;
              return `sub-nav__link${active ? ' sub-nav__link--active' : ''}`;
            }}
          >
            {label}
          </NavLink>
        ))}
      </nav>

      <div className="page-layout__body bcc-layout__body">
        {visibleLeaves.length > 1 && (
          <aside className="bcc-rail" aria-label={`${activeSection.label} navigation`}>
            {visibleLeaves.map((leaf) => (
              <NavLink
                key={leaf.path}
                to={leaf.path}
                end={leaf.end}
                className={({ isActive }) =>
                  `bcc-rail__link${isActive ? ' bcc-rail__link--active' : ''}`
                }
              >
                {leaf.label}
              </NavLink>
            ))}
          </aside>
        )}

        <div className="bcc-layout__content">
          <BlogScopeProvider>
            <Outlet />
          </BlogScopeProvider>
        </div>
      </div>
    </div>
  );
}

export default BlogCommandCentreLayout;
