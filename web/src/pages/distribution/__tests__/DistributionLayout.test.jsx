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

  it('uses section-layout structure with inline flex styles', () => {
    renderWithAuth(<DistributionLayout />, ctx);
    const layout = document.querySelector('.section-layout');
    expect(layout).toBeTruthy();
    expect(layout.style.display).toBe('flex');
    const nav = document.querySelector('.section-nav');
    expect(nav).toBeTruthy();
    expect(nav.style.flexDirection).toBe('column');
  });

  it('links SMS to /distribution/sms', () => {
    renderWithAuth(<DistributionLayout />, ctx);
    expect(screen.getByText('SMS').closest('a')).toHaveAttribute('href', '/distribution/sms');
  });
});
