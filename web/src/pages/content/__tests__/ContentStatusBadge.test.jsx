import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { ContentStatusBadge } from '../../../components/content/ContentStatusBadge';

const ctx = {
  auth: {
    user: { id: 1, email: 'viewer@test.com', role: 'viewer' },
    permissions: ['content.view'],
  },
};

describe('ContentStatusBadge', () => {
  it('renders approved status badge', () => {
    renderWithAuth(<ContentStatusBadge status="approved" />, ctx);
    expect(screen.getByText(/approved/i)).toBeInTheDocument();
  });

  it('renders review_pending status badge', () => {
    renderWithAuth(<ContentStatusBadge status="review_pending" />, ctx);
    expect(screen.getByText(/review/i)).toBeInTheDocument();
  });

  it('renders draft status badge', () => {
    renderWithAuth(<ContentStatusBadge status="draft" />, ctx);
    expect(screen.getByText(/draft/i)).toBeInTheDocument();
  });
});
