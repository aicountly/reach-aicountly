import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/leadsService', () => ({
  leadsService: { list: vi.fn(), push: vi.fn(), create: vi.fn() },
}));

import { leadsService } from '../../../services/leadsService';
import { LeadsPage } from '../LeadsPage';

const lead = {
  id: 41, name: 'Asha Rao', email: 'asha@example.test', source_kind: 'landing', campaign_id: 7,
  engage_push_status: 'pushed', engage_lead_code: 'LD-0041', engage_stage: 'converted',
};

beforeEach(() => {
  vi.clearAllMocks();
  leadsService.list.mockResolvedValue({ items: [lead], total: 1 });
});

describe('LeadsPage drill-down', () => {
  it("narrows to an ad campaign's leads in the window the ads summary counted", async () => {
    renderWithAuth(<LeadsPage />, { route: '/leads?utm_source=facebook&utm_medium=paid_social&utm_campaign=gst-oct&created_from=2026-10-01&created_to=2026-10-14' });

    await waitFor(() => expect(leadsService.list).toHaveBeenCalled());
    expect(leadsService.list).toHaveBeenCalledWith(expect.objectContaining({
      utm_source: 'facebook', utm_medium: 'paid_social', utm_campaign: 'gst-oct', created_from: '2026-10-01', created_to: '2026-10-14',
    }));
    expect(screen.getByRole('link', { name: /clear utm_campaign filter/i })).toBeInTheDocument();
  });

  it('applies every filter a drill link can carry', async () => {
    renderWithAuth(<LeadsPage />, { route: '/leads?campaign_id=7&days=30&engage_push_status=failed&source_kind=social&search=asha' });

    await waitFor(() => expect(leadsService.list).toHaveBeenCalled());
    expect(leadsService.list).toHaveBeenCalledWith(expect.objectContaining({
      campaign_id: '7', days: '30', engage_push_status: 'failed', source_kind: 'social', search: 'asha',
    }));
    expect(screen.getByLabelText('Engage status')).toHaveValue('failed');
    expect(screen.getByLabelText('Source')).toHaveValue('social');
    expect(screen.getByRole('link', { name: /clear campaign filter/i })).toHaveAttribute('href', '/leads?days=30&engage_push_status=failed&source_kind=social&search=asha');
    expect(screen.getByRole('link', { name: /clear captured in the last filter/i })).toHaveTextContent('Captured in the last 30 days');
  });

  it('opens a single lead by id (there is no lead detail page)', async () => {
    renderWithAuth(<LeadsPage />, { route: '/leads?lead_id=41' });
    await waitFor(() => expect(leadsService.list).toHaveBeenCalledWith(expect.objectContaining({ lead_id: '41' })));
    expect(await screen.findByText('Asha Rao')).toBeInTheDocument();
    expect(screen.getByText(/1 matching lead$/)).toBeInTheDocument();
  });

  it('rows drill to the campaign, the source and the lead’s Engage outcomes', async () => {
    renderWithAuth(<LeadsPage />, { route: '/leads' });

    expect(await screen.findByRole('link', { name: 'Campaign #7' })).toHaveAttribute('href', '/campaigns/7');
    expect(screen.getByRole('link', { name: 'landing' })).toHaveAttribute('href', '/leads?source_kind=landing');
    expect(screen.getByRole('link', { name: /stage: converted/ })).toHaveAttribute('href', '/leads/outcomes?lead_id=41&days=all');
  });

  it('keeps a changed filter in the query', async () => {
    renderWithAuth(<LeadsPage />, { route: '/leads' });
    await waitFor(() => expect(leadsService.list).toHaveBeenCalledTimes(1));

    await userEvent.selectOptions(screen.getByLabelText('Priority'), 'urgent');
    await waitFor(() => expect(leadsService.list).toHaveBeenLastCalledWith(expect.objectContaining({ priority: 'urgent' })));
  });
});
