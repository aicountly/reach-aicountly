import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { Route, Routes } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/campaignService', () => ({
  campaignService: { list: vi.fn(), get: vi.fn(), approve: vi.fn(), setStatus: vi.fn() },
}));

import { campaignService } from '../../../services/campaignService';
import { CampaignListPage } from '../CampaignListPage';
import { CampaignDetailPage } from '../CampaignDetailPage';

beforeEach(() => vi.clearAllMocks());

describe('Campaign list deep links', () => {
  it('sends the URL type as campaign_type (the API param) with status and days', async () => {
    campaignService.list.mockResolvedValue({ items: [], total: 0 });
    renderWithAuth(<CampaignListPage />, { route: '/campaigns?type=email&status=running&days=30' });

    await waitFor(() => expect(campaignService.list).toHaveBeenCalled());
    expect(campaignService.list).toHaveBeenCalledWith(expect.objectContaining({ campaign_type: 'email', status: 'running', days: '30' }));
    expect(screen.getByRole('button', { name: /clear created in the last 30 days filter/i })).toBeInTheDocument();
  });
});

describe('Campaign detail', () => {
  const renderDetail = (c) => {
    campaignService.get.mockResolvedValue({ id: 7, name: 'Diwali GST push', status: 'running', approval_status: 'approved', ...c });
    return renderWithAuth(
      <Routes><Route path="/campaigns/:id" element={<CampaignDetailPage />} /></Routes>,
      { route: '/campaigns/7' },
    );
  };

  it('opens the leads and Engage outcomes attributed to the campaign', async () => {
    renderDetail({ leads_captured: 4, outcome_events: 2 });

    expect(await screen.findByRole('link', { name: '4' })).toHaveAttribute('href', '/leads?campaign_id=7');
    expect(screen.getByRole('link', { name: '2' })).toHaveAttribute('href', '/leads/outcomes?campaign_id=7&days=all');
  });

  it('does not link zero counts', async () => {
    renderDetail({ leads_captured: 0, outcome_events: 0 });
    await screen.findByText('Leads captured');
    expect(screen.queryByRole('link', { name: '0' })).toBeNull();
  });
});
