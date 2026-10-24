import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/analyticsService', () => ({
  analyticsService: { trafficExplore: vi.fn() },
}));

import { analyticsService } from '../../../services/analyticsService';
import { TrafficExplorerPage } from '../TrafficExplorerPage';

const totals = { sessions: 50, users: 40, pageviews: 90, bounce_rate: 30, events: 120 };
const lastCall = () => analyticsService.trafficExplore.mock.calls.at(-1)[0];

beforeEach(() => {
  analyticsService.trafficExplore.mockReset();
  analyticsService.trafficExplore.mockResolvedValue({
    rows: [{ dimension_value: 'Organic Search', sessions: 30, users: 25, pageviews: 60, bounce_rate: 20, events: 70 },
      { dimension_value: 'Direct', sessions: 20, users: 15, pageviews: 30, bounce_rate: 45, events: 50 }],
    total_rows: 3, totals, period: { from: '2026-09-23', to: '2026-10-23' }, sites: ['https://aicountly.com'],
  });
});

describe('TrafficExplorerPage', () => {
  it('reads the slice from the URL and drills a row into the next dimension', async () => {
    renderWithAuth(<TrafficExplorerPage />, { route: '/analytics/traffic?days=7&stream=marketing_site&date=20261020' });

    await screen.findByRole('button', { name: 'Organic Search' });
    expect(lastCall()).toMatchObject({ group_by: 'channel', days: 7, stream: 'marketing_site', date: '20261020', offset: 0 });
    expect(screen.getByRole('navigation', { name: /drill-down path/i })).toHaveTextContent('Day: 2026-10-20');

    fireEvent.click(screen.getByRole('button', { name: 'Organic Search' }));
    await waitFor(() => expect(lastCall()).toMatchObject({ group_by: 'source', channel: 'Organic Search', date: '20261020' }));
    expect(screen.getByRole('navigation', { name: /drill-down path/i })).toHaveTextContent('Channel: Organic Search');
  });

  it('sorts, switches dimension and loads more', async () => {
    renderWithAuth(<TrafficExplorerPage />, { route: '/analytics/traffic?by=channel' });
    await screen.findByRole('button', { name: 'Organic Search' });

    fireEvent.click(screen.getByRole('button', { name: /bounce rate/i }));
    await waitFor(() => expect(lastCall()).toMatchObject({ sort: 'bounce_rate' }));

    fireEvent.click(screen.getByRole('tab', { name: /by country/i }));
    await waitFor(() => expect(lastCall()).toMatchObject({ group_by: 'country' }));

    const more = await screen.findByRole('button', { name: /load 1 more/i });
    analyticsService.trafficExplore.mockResolvedValueOnce({
      rows: [{ dimension_value: 'India', sessions: 1, users: 1, pageviews: 1, bounce_rate: 0, events: 1 }], total_rows: 3, totals,
    });
    fireEvent.click(more);
    await waitFor(() => expect(lastCall()).toMatchObject({ offset: 2, group_by: 'country' }));
    expect(await screen.findByRole('button', { name: 'India' })).toBeInTheDocument();
    expect(screen.getByText(/showing 3 of 3 countries/i)).toBeInTheDocument();
  });

  it('shows the single GA4 row at the leaf with page, content and leads actions', async () => {
    analyticsService.trafficExplore.mockResolvedValue({
      rows: [], total_rows: 1, totals, sites: ['https://aicountly.com'], page_content_identity_id: 42,
    });
    renderWithAuth(<TrafficExplorerPage />, {
      route: '/analytics/traffic?date=20261020&channel=Organic+Search&source=google+%2F+organic&page=%2Fblogs%2Fgst&device=mobile&country=India&event=lead_form_submit',
    });

    const leaf = await screen.findByTestId('traffic-leaf');
    expect(leaf).toHaveTextContent('Single GA4 report row');
    expect(leaf).toHaveTextContent('google / organic');
    expect(screen.getByRole('link', { name: /open page/i })).toHaveAttribute('href', 'https://aicountly.com/blogs/gst');
    expect(screen.getByRole('link', { name: /reach content analytics/i })).toHaveAttribute('href', '/intelligence/content/42');
    expect(screen.getByRole('link', { name: /leads reach captured on 2026-10-20/i }))
      .toHaveAttribute('href', '/leads?created_from=2026-10-20&created_to=2026-10-20');
  });

  it('links a page row to its Reach content identity', async () => {
    analyticsService.trafficExplore.mockResolvedValue({
      rows: [{ dimension_value: '/blogs/gst', sessions: 3, users: 3, pageviews: 4, bounce_rate: 0, events: 5, content_identity_id: 9 }],
      total_rows: 1, totals,
    });
    renderWithAuth(<TrafficExplorerPage />, { route: '/analytics/traffic?by=page' });

    expect(await screen.findByRole('link', { name: /content analytics/i })).toHaveAttribute('href', '/intelligence/content/9');
  });

  it('shows why GA4 is unavailable instead of numbers', async () => {
    analyticsService.trafficExplore.mockResolvedValue({
      rows: [], total_rows: 0, totals: { sessions: 0 }, _unconfigured: true, _reason: 'GA4 property ID is missing.',
    });
    renderWithAuth(<TrafficExplorerPage />, { route: '/analytics/traffic' });

    expect(await screen.findByTestId('traffic-unconfigured')).toHaveTextContent('GA4 property ID is missing.');
    expect(screen.queryByText('Sessions')).toBeNull();
  });
});
