import { describe, it, expect, vi, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/leadsService', () => ({
  leadsService: { outcomes: vi.fn(), outcomeSummary: vi.fn() },
}));

import { leadsService } from '../../../services/leadsService';
import { LeadOutcomesPage } from '../LeadOutcomesPage';

const summary = {
  events: 2, matched: 1, unmatched: 1, conversions: 1,
  revenue_inr: '4999.00', converted_value_inr: '4999.00',
  by_stage: { qualified: 1, demo: 0, proposal_sent: 0, converted: 1, lost: 0, payment_confirmed: 0 },
  by_source: [], by_campaign: [], by_content: [], by_seller: [],
  inbound_configured: true, last_received_at: null,
};

const items = [
  {
    id: 2, event_id: 'evt-2', lead_id: 41, lead_name: 'Asha Rao', lead_email: 'asha@example.test',
    engage_lead_code: 'LD-0041', stage: 'converted', sales_status: 'won', value_inr: '4999.00',
    product: 'books', campaign_id: 7, campaign_name: 'Diwali GST push', source: 'google',
    attribution_method: 'lead_campaign', match_method: 'reach_lead_id', seller_code: null,
    occurred_at: '2026-10-16T10:00:00+00:00',
  },
  {
    id: 1, event_id: 'evt-1', lead_id: null, engage_lead_code: 'LD-0099', stage: 'qualified', sales_status: null,
    value_inr: null, product: null, campaign_id: null, source: null, attribution_method: 'unattributed',
    match_method: 'unmatched', seller_code: 'SLR-000123', occurred_at: '2026-10-15T10:00:00+00:00',
  },
];

afterEach(() => vi.clearAllMocks());

describe('LeadOutcomesPage', () => {
  it('lists events with their match and attribution, and the period totals', async () => {
    leadsService.outcomeSummary.mockResolvedValue(summary);
    leadsService.outcomes.mockResolvedValue({ items, total: 2 });
    renderWithAuth(<LeadOutcomesPage />);

    await waitFor(() => expect(screen.getByText('#41 · Asha Rao')).toBeInTheDocument());
    expect(screen.getByText('Not matched')).toBeInTheDocument();
    expect(screen.getByText('Diwali GST push')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'SLR-000123' })).toBeInTheDocument();
    expect(screen.getByText('Events (2)')).toBeInTheDocument();
    expect(screen.getByTestId('stage-converted')).toHaveTextContent('converted: 1');
    expect(screen.getByText('No outcome has been received from Engage yet.')).toBeInTheDocument();
  });

  it('filters by stage', async () => {
    leadsService.outcomeSummary.mockResolvedValue(summary);
    leadsService.outcomes.mockResolvedValue({ items: [], total: 0 });
    renderWithAuth(<LeadOutcomesPage />);
    await waitFor(() => expect(leadsService.outcomes).toHaveBeenCalledTimes(1));

    await userEvent.selectOptions(screen.getByLabelText('Stage'), 'converted');
    await waitFor(() => expect(leadsService.outcomes).toHaveBeenLastCalledWith(expect.objectContaining({ stage: 'converted' })));
    expect(await screen.findByText('No outcome events for this filter.')).toBeInTheDocument();
  });

  it('applies the drill filters in the URL, and "all time" lists without bounds', async () => {
    leadsService.outcomeSummary.mockResolvedValue(summary);
    leadsService.outcomes.mockResolvedValue({ items, total: 2 });
    renderWithAuth(<LeadOutcomesPage />, { route: '/leads/outcomes?campaign_id=7&days=all&stage=converted&seller_code=SLR-1&paid=1' });

    await waitFor(() => expect(leadsService.outcomes).toHaveBeenCalled());
    const call = leadsService.outcomes.mock.calls[0][0];
    expect(call).toEqual(expect.objectContaining({ campaign_id: '7', stage: 'converted', seller_code: 'SLR-1', paid: '1' }));
    expect(call.from).toBeUndefined();
    expect(leadsService.outcomeSummary.mock.calls[0][0].from).toBe('2000-01-01');
    expect(screen.getByLabelText('Stage')).toHaveValue('converted');
    expect(screen.getByLabelText('Window')).toHaveValue('all');
    // The chip names the campaign once its events are on screen.
    expect(await screen.findByRole('link', { name: /clear campaign filter/i })).toHaveTextContent('Campaign: Diwali GST push');
    expect(screen.getByRole('link', { name: /clear payments only filter/i })).toHaveAttribute('href', '/leads/outcomes?campaign_id=7&days=all&stage=converted&seller_code=SLR-1');
    expect(screen.getByText(/the event list below is narrowed/)).toBeInTheDocument();
  });

  it('drills from totals, stage chips and event cells', async () => {
    leadsService.outcomeSummary.mockResolvedValue(summary);
    leadsService.outcomes.mockResolvedValue({ items, total: 2 });
    renderWithAuth(<LeadOutcomesPage />, { route: '/leads/outcomes?days=7' });

    await screen.findByText('#41 · Asha Rao');
    expect(screen.getByText('Conversions').closest('a')).toHaveAttribute('href', '/leads/outcomes?days=7&stage=converted');
    expect(screen.getByText('Revenue (payments)').closest('a')).toHaveAttribute('href', '/leads/outcomes?days=7&paid=1');
    expect(screen.getByRole('link', { name: '1 unmatched' })).toHaveAttribute('href', '/leads/outcomes?days=7&match=unmatched');
    expect(screen.getByTestId('stage-qualified').querySelector('a')).toHaveAttribute('href', '/leads/outcomes?days=7&stage=qualified');
    expect(screen.getByTestId('stage-demo').querySelector('a')).toBeNull();
    // A matched lead opens the lead list narrowed to it; an unmatched code narrows this list.
    expect(screen.getByRole('link', { name: '#41 · Asha Rao' })).toHaveAttribute('href', '/leads/41');
    expect(screen.getByRole('link', { name: 'LD-0099' })).toHaveAttribute('href', '/leads/outcomes?days=7&search=LD-0099');
    expect(screen.getByRole('link', { name: 'Diwali GST push' })).toHaveAttribute('href', '/campaigns/7');
    expect(screen.getByRole('link', { name: 'SLR-000123' })).toHaveAttribute('href', '/leads/outcomes?days=7&seller_code=SLR-000123');
  });
});
