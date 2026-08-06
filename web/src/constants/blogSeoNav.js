import { ROUTES } from './routes';

/**
 * Sub-navigation for the Blog SEO and Indexing section.
 *
 * 2026-08 IA fix: this used to be the Blog Command Centre's "SEO and
 * Indexing" tab, whose leaves were DEEP_LINKs that redirected into
 * `/intelligence/*` — clicking a BCC tab threw the operator out of the
 * Blog Command Centre and into a different surface with its own 15-tab
 * strip. The section now lives under the sidebar's SEO block, renamed so
 * it no longer collides with "SEO Command Centre", and the three linked
 * leaves render their canonical Intelligence pages in place instead of
 * redirecting away.
 *
 * `treatment` mirrors the Blog Command Centre convention: `SHARED` reuses a
 * canonical existing screen, `SCAFFOLD` is a real route behind a real
 * permission check rendering an honest placeholder.
 */
export const BLOG_SEO_NAV = [
  { label: 'Search Console', path: ROUTES.BLOG_SEO_SEARCH, treatment: 'SHARED' },
  { label: 'Indexing Status', path: ROUTES.BLOG_SEO_INDEXING, treatment: 'SHARED' },
  { label: 'Internal Links', path: ROUTES.BLOG_SEO_INTERNAL_LINKS, treatment: 'SCAFFOLD' },
  { label: 'Cannibalisation', path: ROUTES.BLOG_SEO_CANNIBALISATION, treatment: 'SCAFFOLD' },
  { label: 'Sitemap', path: ROUTES.BLOG_SEO_SITEMAP, treatment: 'SHARED' },
  { label: 'Technical SEO', path: ROUTES.BLOG_SEO_TECHNICAL, treatment: 'SCAFFOLD' },
];

export function findBlogSeoNavItem(pathname) {
  const exact = BLOG_SEO_NAV.find((item) => item.path === pathname);
  if (exact) return exact;
  return BLOG_SEO_NAV.find((item) => pathname.startsWith(`${item.path}/`));
}

export function buildBlogSeoBreadcrumbs(pathname) {
  const items = [{ label: 'Blog SEO and Indexing', to: ROUTES.BLOG_SEO }];
  const active = findBlogSeoNavItem(pathname);
  if (active) items.push({ label: active.label });
  return items;
}
