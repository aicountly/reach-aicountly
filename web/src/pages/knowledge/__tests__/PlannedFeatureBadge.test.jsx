import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { FeatureAvailabilityBadge } from '../../../components/knowledge/FeatureAvailabilityBadge';

const authCtx = {
  auth: {
    user: { id: 1, email: 'test@test.com', role: 'viewer' },
    permissions: ['knowledge.view'],
  },
};

describe('FeatureAvailabilityBadge — planned feature', () => {
  it('shows "planned" for planned availability', () => {
    renderWithAuth(<FeatureAvailabilityBadge availability="planned" />, authCtx);
    expect(screen.getByText(/planned/i)).toBeInTheDocument();
  });

  it('shows "available" for available', () => {
    renderWithAuth(<FeatureAvailabilityBadge availability="available" />, authCtx);
    expect(screen.getByText(/available/i)).toBeInTheDocument();
  });

  it('does not render "available" label for planned', () => {
    renderWithAuth(<FeatureAvailabilityBadge availability="planned" />, authCtx);
    expect(screen.queryByText(/^available$/i)).not.toBeInTheDocument();
  });
});
