import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { CompletenessGauge } from '../../../components/knowledge/CompletenessGauge';

const authCtx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'super_admin' },
    permissions: ['*'],
  },
};

describe('CompletenessGauge', () => {
  it('renders percentage text', () => {
    renderWithAuth(<CompletenessGauge percent={72} />, authCtx);
    expect(screen.getByText('72%')).toBeInTheDocument();
  });

  it('renders 0% when percent is 0', () => {
    renderWithAuth(<CompletenessGauge percent={0} />, authCtx);
    expect(screen.getByText('0%')).toBeInTheDocument();
  });

  it('renders 100% when fully complete', () => {
    renderWithAuth(<CompletenessGauge percent={100} />, authCtx);
    expect(screen.getByText('100%')).toBeInTheDocument();
  });
});
