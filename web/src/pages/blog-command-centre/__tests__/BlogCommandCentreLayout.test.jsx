import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';
import BlogCommandCentreLayout from '../BlogCommandCentreLayout.jsx';
import { BlogOverviewPage } from '../BlogOverviewPage.jsx';

vi.mock('../../../services/blogCommandCentreService', () => ({
  blogCommandCentreService: {
    getOverview: () => Promise.reject({ status: 404 }),
  },
}));

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['blog.view', 'content.view', 'publishing.view'],
  },
  route: '/blog-command-centre',
};

describe('BlogCommandCentreLayout', () => {
  it('renders with permissions and shows Overview section', () => {
    renderWithAuth(
      <Routes>
        <Route path="/blog-command-centre" element={<BlogCommandCentreLayout />}>
          <Route index element={<BlogOverviewPage />} />
        </Route>
      </Routes>,
      ctx,
    );
    expect(screen.getByRole('heading', { name: 'Blog Command Centre' })).toBeInTheDocument();
    expect(screen.getAllByText('Overview').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Roadmap')).toBeInTheDocument();
    expect(screen.getByText('Content Pipeline')).toBeInTheDocument();
  });

  it('returns null without required permissions', () => {
    const { container } = renderWithAuth(
      <Routes>
        <Route path="/blog-command-centre" element={<BlogCommandCentreLayout />}>
          <Route index element={<BlogOverviewPage />} />
        </Route>
      </Routes>,
      {
        auth: {
          user: { id: 2, email: 'viewer@test.com', role: 'viewer' },
          permissions: ['dashboard.view'],
        },
        route: '/blog-command-centre',
      },
    );
    expect(container.querySelector('.page-layout')).toBeNull();
  });
});
