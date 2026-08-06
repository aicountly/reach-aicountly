import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { FileSearch } from 'lucide-react';
import { Breadcrumbs } from '../../components/common/Breadcrumbs';
import { usePermission } from '../../hooks/usePermission';
import { ForbiddenPage } from '../ForbiddenPage';
import { BLOG_SEO_NAV, buildBlogSeoBreadcrumbs } from '../../constants/blogSeoNav';

/**
 * Blog SEO and Indexing — sidebar SEO block, sibling of the SEO Command
 * Centre (site-wide rankings/backlinks) rather than a Blog Command Centre
 * tab that ejected the operator into Intelligence.
 *
 * The section carries the access it had inside the Blog Command Centre:
 * a real permission denial says so rather than rendering a blank page.
 */
export function BlogSeoLayout() {
  const { pathname } = useLocation();
  const { hasAny } = usePermission();

  if (!hasAny(['blog.view', 'seo.view'])) {
    return <ForbiddenPage requiredPermission="blog.view" />;
  }

  return (
    <div className="page-layout page-layout--flush">
      <div className="page-layout__header">
        <div className="page-layout__title-row">
          <FileSearch size={18} className="page-layout__icon" aria-hidden="true" />
          <h1 className="page-layout__title">Blog SEO and Indexing</h1>
        </div>
        <p className="page-layout__subtitle">
          Search Console, indexing, sitemap and on-page SEO health for published blogs.
        </p>
        <Breadcrumbs items={buildBlogSeoBreadcrumbs(pathname)} />
      </div>

      <nav className="sub-nav" aria-label="Blog SEO and Indexing navigation">
        {BLOG_SEO_NAV.map(({ path, label }) => (
          <NavLink
            key={path}
            to={path}
            className={({ isActive }) => `sub-nav__link${isActive ? ' sub-nav__link--active' : ''}`}
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

export default BlogSeoLayout;
