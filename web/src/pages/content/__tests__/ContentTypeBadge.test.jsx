import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { ContentTypeBadge } from '../../../components/content/ContentTypeBadge';

const ctx = {
  auth: {
    user: { id: 1, email: 'viewer@test.com', role: 'viewer' },
    permissions: ['content.view'],
  },
};

describe('ContentTypeBadge', () => {
  it('renders blog type', () => {
    renderWithAuth(<ContentTypeBadge type="blog" />, ctx);
    expect(screen.getByText(/blog/i)).toBeInTheDocument();
  });

  it('renders social_post type', () => {
    renderWithAuth(<ContentTypeBadge type="social_post" />, ctx);
    expect(screen.getByText(/social/i)).toBeInTheDocument();
  });
});
