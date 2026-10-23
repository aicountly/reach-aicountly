import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/analyticsService', () => ({
  analyticsService: {
    trafficConfigStatus: vi.fn(), trafficOverview: vi.fn(), trafficSources: vi.fn(), trafficLeads: vi.fn(),
  },
}));

import { analyticsService } from '../../../services/analyticsService';
import { TrafficAnalyticsSection } from '../TrafficAnalyticsSection';

beforeEach(() => {
  analyticsService.trafficConfigStatus.mockResolvedValue({ ready: true, streams: [], default_stream: 'all' });
  analyticsService.trafficOverview.mockResolvedValue({ totals: { sessions: 10 }, trend: [], top_pages: [] });
  analyticsService.trafficSources.mockResolvedValue({ channels: [] });
});

describe('TrafficAnalyticsSection lead events', () => {
  it('links GA4 form submits to the leads Reach captured in the same window', async () => {
    analyticsService.trafficLeads.mockResolvedValue({ lead_form_submit: 3, signup_click: 8 });
    renderWithAuth(<TrafficAnalyticsSection />);

    expect(await screen.findByRole('link', { name: /leads reach captured in the last 30 days/i }))
      .toHaveAttribute('href', '/leads?days=30');
  });

  it('offers no link when only clicks and views were recorded', async () => {
    analyticsService.trafficLeads.mockResolvedValue({ lead_form_submit: 0, signup_click: 8 });
    renderWithAuth(<TrafficAnalyticsSection />);

    await screen.findByText('Lead events (GA4)');
    await screen.findByText('Sign-up clicks');
    expect(screen.queryByRole('link', { name: /leads reach captured/i })).toBeNull();
  });
});
