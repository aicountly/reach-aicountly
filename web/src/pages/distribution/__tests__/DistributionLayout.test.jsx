import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import DistributionLayout from '../DistributionLayout';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['distribution.read'],
  },
};

describe('DistributionLayout', () => {
  it('renders Distribution title and SMS nav link separately', () => {
    renderWithAuth(<DistributionLayout />, ctx);
    expect(screen.getByText('Distribution')).toBeInTheDocument();
    expect(screen.getByText('SMS')).toBeInTheDocument();
    expect(screen.getByText('Campaigns')).toBeInTheDocument();
    expect(screen.queryByText(/OverviewCampaignsAudience/i)).not.toBeInTheDocument();
  });

  it('uses page-layout with horizontal sub-nav', () => {
    renderWithAuth(<DistributionLayout />, ctx);
    expect(document.querySelector('.page-layout')).toBeTruthy();
    expect(document.querySelector('.sub-nav')).toBeTruthy();
    expect(document.querySelector('.page-layout__body')).toBeTruthy();
    expect(document.querySelectorAll('.sub-nav__link').length).toBeGreaterThan(0);
  });

  it('links SMS to /distribution/sms', () => {
    renderWithAuth(<DistributionLayout />, ctx);
    expect(screen.getByText('SMS').closest('a')).toHaveAttribute('href', '/distribution/sms');
  });
});
