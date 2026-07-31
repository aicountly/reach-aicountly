import { Navigate, useParams } from 'react-router-dom';
import { blogFeatureFlags } from '../../constants/blogFeatureFlags';
import { ROUTES } from '../../constants/routes';
import { BlogListPage } from '../blog/BlogListPage';
import { BlogEditorPage } from '../blog/BlogEditorPage';
import { BlogDetailPage } from '../blog/BlogDetailPage';

export function BlogLegacyListRedirect() {
  if (blogFeatureFlags.legacyRedirectEnabled) {
    return <Navigate to={ROUTES.BCC_PIPELINE_DRAFTS} replace />;
  }
  return <BlogListPage />;
}

export function BlogLegacyNewRedirect() {
  if (blogFeatureFlags.legacyCreateDisabled) {
    return <Navigate to={ROUTES.CONTENT_NEW} replace />;
  }
  if (blogFeatureFlags.legacyRedirectEnabled) {
    return <Navigate to={ROUTES.BCC_PIPELINE} replace />;
  }
  return <BlogEditorPage />;
}

export function BlogLegacyDetailRedirect() {
  const { id } = useParams();
  if (blogFeatureFlags.legacyRedirectEnabled) {
    return (
      <Navigate
        to={ROUTES.BCC_PIPELINE_DRAFTS}
        replace
        state={{ notice: `Legacy blog #${id} is not linked to Content Studio. Open drafts or migrate via reach:blog-migrate-legacy.` }}
      />
    );
  }
  return <BlogDetailPage />;
}

export function BlogLegacyEditRedirect() {
  const { id } = useParams();
  if (blogFeatureFlags.legacyRedirectEnabled) {
    return (
      <Navigate
        to={ROUTES.BCC_PIPELINE_DRAFTS}
        replace
        state={{ notice: `Legacy edit for blog #${id} is retired. Use Content Studio for blog content.` }}
      />
    );
  }
  return <BlogEditorPage />;
}
