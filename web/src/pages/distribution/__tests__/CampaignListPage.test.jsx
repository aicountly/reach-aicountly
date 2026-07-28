import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import CampaignListPage from '../CampaignListPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['distribution.read'],
  },
};

beforeEach(() => {
  api.get.mockReset();
});

describe('Distribution CampaignListPage', () => {
  it('loads campaigns from v1/campaigns', async () => {
    api.get.mockResolvedValueOnce([
      { id: 12, name: 'GST Push', campaign_type: 'email', status: 'draft' },
    ]);
    renderWithAuth(<CampaignListPage />, ctx);
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('v1/campaigns', {}));
    expect(screen.getByText('GST Push')).toBeInTheDocument();
    expect(screen.getByText('Open workspace').closest('a')).toHaveAttribute(
      'href',
      '/distribution/campaigns/12',
    );
  });

  it('does not request /campaigns/undefined', async () => {
    api.get.mockResolvedValueOnce([]);
    renderWithAuth(<CampaignListPage />, ctx);
    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(api.get.mock.calls.some(([path]) => String(path).includes('undefined'))).toBe(false);
  });

  it('shows empty state', async () => {
    api.get.mockResolvedValueOnce([]);
    renderWithAuth(<CampaignListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No campaigns found/i)).toBeInTheDocument());
  });
});
