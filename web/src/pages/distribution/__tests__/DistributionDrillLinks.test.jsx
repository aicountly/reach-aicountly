import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));
import api from '../../../services/api';
import DistributionOverviewPage from '../DistributionOverviewPage';
import DistributionAnalyticsPage from '../DistributionAnalyticsPage';
import CampaignListPage from '../CampaignListPage';
import EmailDispatchPage from '../EmailDispatchPage';
import WhatsAppDispatchPage from '../WhatsAppDispatchPage';
import SmsDispatchPage from '../SmsDispatchPage';

/**
 * Distribution counts open the records behind them, and the lists they open
 * apply the filter carried in the URL.
 */
beforeEach(() => {
  api.get.mockReset();
  api.get.mockResolvedValue({ data: [], items: [], total: 0 });
});

describe('Distribution overview tiles', () => {
  it('link to the campaign and dispatch lists when non-zero', async () => {
    api.get.mockImplementation((path) => Promise.resolve(
      path === 'v1/campaigns' ? { total: 4 } : { total: 0 },
    ));
    renderWithAuth(<DistributionOverviewPage />);
    const tile = await screen.findByRole('link', { name: /Total Campaigns: 4/ });
    expect(tile).toHaveAttribute('href', '/distribution/campaigns');
    // Zero dispatches: nothing to open.
    expect(screen.queryByRole('link', { name: /Total Dispatches/ })).not.toBeInTheDocument();
    expect(screen.getByText('Total Dispatches')).toBeInTheDocument();
  });
});

describe('Distribution analytics drill links', () => {
  const row = {
    id: 1, dispatch_id: 9, campaign_id: 42, channel: 'email',
    sent_count: 10, delivered_count: 8, open_count: 2, click_count: 0, bounce_count: 2, unsubscribe_count: 0,
  };

  it('links campaign and bounce cells, not zero unsubscribes', async () => {
    api.get.mockResolvedValueOnce({ data: [row] });
    renderWithAuth(<DistributionAnalyticsPage />);
    expect(await screen.findByRole('link', { name: '42' })).toHaveAttribute('href', '/distribution/campaigns/42');
    expect(screen.getByRole('link', { name: '2' })).toHaveAttribute(
      'href', '/distribution/suppressions?channel=email&reason=bounce',
    );
    expect(screen.queryByRole('link', { name: '0' })).not.toBeInTheDocument();
  });

  it('applies ?channel= to the analytics query', async () => {
    renderWithAuth(<DistributionAnalyticsPage />, { route: '/distribution/analytics?channel=sms' });
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('v1/distribution/analytics', { channel: 'sms' }));
    expect(screen.getByRole('button', { name: /clear channel sms filter/i })).toBeInTheDocument();
  });
});

describe('Distribution list filters in the URL', () => {
  it('campaign list applies ?status=', async () => {
    renderWithAuth(<CampaignListPage />, { route: '/distribution/campaigns?status=running' });
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('v1/campaigns', { status: 'running' }));
    expect(screen.getByLabelText('Status')).toHaveValue('running');
  });

  it('email dispatch applies ?status= and rows open the email campaign', async () => {
    api.get.mockResolvedValueOnce({ items: [{ id: 5, subject: 'Launch', status: 'failed' }], total: 1 });
    renderWithAuth(<EmailDispatchPage />, { route: '/distribution/email?status=failed' });
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(
      'v1/email/campaigns', expect.objectContaining({ status: 'failed' }),
    ));
    expect(await screen.findByRole('link', { name: 'Launch' })).toHaveAttribute('href', '/email/5');
  });

  it('email dispatch defaults to approved; "all" sends no status', async () => {
    renderWithAuth(<EmailDispatchPage />, { route: '/distribution/email?status=all' });
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(
      'v1/email/campaigns', expect.objectContaining({ status: undefined }),
    ));
  });

  it('whatsapp dispatch applies ?status=', async () => {
    renderWithAuth(<WhatsAppDispatchPage />, { route: '/distribution/whatsapp?status=sent' });
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(
      'v1/whatsapp/campaigns', expect.objectContaining({ status: 'sent' }),
    ));
  });

  it('sms dispatch applies ?status= and rows open the campaign workspace', async () => {
    api.get.mockImplementation((path) => Promise.resolve(
      path === 'v1/campaigns' ? { items: [{ id: 3, name: 'SMS launch', status: 'completed' }], total: 1 } : {},
    ));
    renderWithAuth(<SmsDispatchPage />, { route: '/distribution/sms/dispatch?status=completed' });
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(
      'v1/campaigns', expect.objectContaining({ channel: 'sms', status: 'completed' }),
    ));
    expect(await screen.findByRole('link', { name: 'SMS launch' })).toHaveAttribute('href', '/distribution/campaigns/3');
  });
});
