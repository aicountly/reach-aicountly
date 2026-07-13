import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import PublishingLayout from '../PublishingLayout';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view', 'publishing.publish', 'seo.view'],
  },
};

describe('PublishingLayout', () => {
  it('renders navigation links', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    expect(screen.getByText('Blogs')).toBeInTheDocument();
    expect(screen.getByText('Knowledge Base')).toBeInTheDocument();
    expect(screen.getByText('Calendar')).toBeInTheDocument();
    expect(screen.getByText('Deployments')).toBeInTheDocument();
    expect(screen.getByText('Verifications')).toBeInTheDocument();
    expect(screen.getByText('Connections')).toBeInTheDocument();
    expect(screen.getByText('Readiness')).toBeInTheDocument();
  });

  it('renders exactly 7 nav items', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    const links = document.querySelectorAll('.sub-nav__link');
    expect(links).toHaveLength(7);
  });

  it('has sub-nav container', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    expect(document.querySelector('.sub-nav')).toBeTruthy();
  });
});
