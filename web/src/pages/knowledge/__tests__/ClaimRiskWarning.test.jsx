import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { ClaimRiskBadge } from '../../../components/knowledge/ClaimRiskBadge';

const authCtx = {
  auth: {
    user: { id: 1, email: 'test@test.com', role: 'viewer' },
    permissions: ['knowledge.view'],
  },
};

describe('ClaimRiskBadge', () => {
  it('renders critical badge for critical risk', () => {
    renderWithAuth(<ClaimRiskBadge risk="critical" />, authCtx);
    expect(screen.getByText(/critical/i)).toBeInTheDocument();
  });

  it('renders low badge for low risk', () => {
    renderWithAuth(<ClaimRiskBadge risk="low" />, authCtx);
    expect(screen.getByText(/low/i)).toBeInTheDocument();
  });
});
