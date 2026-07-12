import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { ContentRiskBadge } from '../../../components/content/ContentRiskBadge';

const ctx = {
  auth: {
    user: { id: 1, email: 'viewer@test.com', role: 'viewer' },
    permissions: ['content.view'],
  },
};

describe('ContentRiskBadge', () => {
  it('renders critical risk level', () => {
    renderWithAuth(<ContentRiskBadge level="critical" />, ctx);
    expect(screen.getByText(/critical/i)).toBeInTheDocument();
  });

  it('renders low risk level', () => {
    renderWithAuth(<ContentRiskBadge level="low" />, ctx);
    expect(screen.getByText(/low/i)).toBeInTheDocument();
  });
});
