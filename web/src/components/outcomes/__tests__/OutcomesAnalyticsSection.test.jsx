import { describe, it, expect, vi, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/analyticsService', () => ({
  analyticsService: { outcomes: vi.fn() },
}));

import { analyticsService } from '../../../services/analyticsService';
import { OutcomesAnalyticsSection } from '../OutcomesAnalyticsSection';

const base = {
  events: 5, matched: 4, unmatched: 1, conversions: 2,
  revenue_inr: '15000.00', converted_value_inr: '20000.00',
  by_stage: { qualified: 3, converted: 2 },
  by_source: [{ source: 'google', events: 3, conversions: 2, revenue_inr: '15000.00', converted_value_inr: '20000.00' }],
  by_campaign: [{ campaign_id: 7, campaign_name: 'Diwali GST push', events: 3, conversions: 2, revenue_inr: '15000.00', converted_value_inr: '20000.00' }],
  by_content: [],
  by_seller: [{ seller_code: 'SLR-000123', events: 1, conversions: 1, revenue_inr: '0.00', converted_value_inr: '5000.00' }],
  inbound_configured: true,
  last_received_at: '2026-10-17T05:00:00+00:00',
};

afterEach(() => vi.clearAllMocks());

describe('OutcomesAnalyticsSection', () => {
  it('shows conversions and revenue per campaign, source and seller', async () => {
    analyticsService.outcomes.mockResolvedValue(base);
    renderWithAuth(<OutcomesAnalyticsSection />);

    await waitFor(() => expect(screen.getByText('Diwali GST push (#7)')).toBeInTheDocument());
    expect(screen.getByText('google')).toBeInTheDocument();
    expect(screen.getByText('SLR-000123')).toBeInTheDocument();
    expect(screen.getAllByText('₹15,000.00').length).toBeGreaterThan(0);
    const call = analyticsService.outcomes.mock.calls[0][0];
    expect(call.from).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(call.to).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('says plainly when the Engage inbound is not configured', async () => {
    analyticsService.outcomes.mockResolvedValue({
      ...base, events: 0, matched: 0, unmatched: 0, conversions: 0, revenue_inr: '0.00', converted_value_inr: '0.00',
      by_source: [], by_campaign: [], by_seller: [], inbound_configured: false, last_received_at: null,
    });
    renderWithAuth(<OutcomesAnalyticsSection />);

    await waitFor(() => expect(screen.getByText(/REACH_INBOUND_ENGAGE_TOKEN is not set/)).toBeInTheDocument());
    expect(screen.getAllByText('Nothing to show for this period.').length).toBe(2);
  });
});
