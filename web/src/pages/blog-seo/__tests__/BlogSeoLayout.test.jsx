import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';
import BlogSeoLayout from '../BlogSeoLayout.jsx';
import { BlogSeoScaffoldPage } from '../BlogSeoScaffoldPage.jsx';
import { ROUTES } from '../../../constants/routes';

function renderSection(ctx) {
  return renderWithAuth(
    <Routes>
      <Route path="/blog-seo" element={<BlogSeoLayout />}>
        <Route path="internal-links" element={<BlogSeoScaffoldPage />} />
      </Route>
    </Routes>,
    ctx,
  );
}

describe('BlogSeoLayout', () => {
  it('renders its own sub-nav under the renamed section title', () => {
    renderSection({
      auth: { user: { id: 1, email: 'admin@aicountly.org', role: 'super_admin' }, permissions: ['blog.view'] },
      route: ROUTES.BLOG_SEO_INTERNAL_LINKS,
    });

    expect(screen.getByRole('heading', { name: 'Blog SEO and Indexing' })).toBeInTheDocument();
    // The section keeps every leaf it had as a Blog Command Centre tab.
    expect(screen.getByRole('link', { name: 'Search Console' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Indexing Status' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Sitemap' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Technical SEO' })).toBeInTheDocument();
    // Scaffold leaves still title themselves from the nav manifest.
    expect(screen.getByRole('heading', { name: 'Internal Links' })).toBeInTheDocument();
  });

  it('refuses access explicitly rather than rendering a blank page', () => {
    renderSection({
      auth: { user: { id: 2, email: 'viewer@aicountly.org', role: 'viewer' }, permissions: ['dashboard.view'] },
      route: ROUTES.BLOG_SEO_INTERNAL_LINKS,
    });

    expect(screen.queryByRole('heading', { name: 'Blog SEO and Indexing' })).not.toBeInTheDocument();
    expect(screen.getByText(/blog\.view/)).toBeInTheDocument();
  });
});
