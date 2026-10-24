import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import { Routes, Route, useLocation } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/analyticsService', () => ({
  analyticsService: {
    trafficConfigStatus: vi.fn(), trafficOverview: vi.fn(), trafficSources: vi.fn(), trafficLeads: vi.fn(),
  },
}));

import { analyticsService } from '../../../services/analyticsService';
import { TrafficAnalyticsSection } from '../TrafficAnalyticsSection';

function Probe() {
  const loc = useLocation();
  return <div data-testid="location">{loc.pathname}{loc.search}</div>;
}

function renderSection() {
  return renderWithAuth(
    <Routes>
      <Route path="/analytics" element={<TrafficAnalyticsSection />} />
      <Route path="/analytics/traffic" element={<Probe />} />
    </Routes>,
    { route: '/analytics' },
  );
}

beforeEach(() => {
  analyticsService.trafficConfigStatus.mockResolvedValue({ ready: true, streams: [], default_stream: 'all' });
  analyticsService.trafficOverview.mockResolvedValue({ totals: { sessions: 10 }, trend: [], top_pages: [] });
  analyticsService.trafficSources.mockResolvedValue({ channels: [] });
  analyticsService.trafficLeads.mockResolvedValue({ lead_form_submit: 0 });
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

  it('opens the explorer narrowed to an event bar', async () => {
    analyticsService.trafficLeads.mockResolvedValue({ lead_form_submit: 3, signup_click: 0 });
    renderSection();

    fireEvent.click(await screen.findByRole('button', { name: /contact form submits: 3/i }));
    expect(screen.getByTestId('location')).toHaveTextContent('/analytics/traffic?event=lead_form_submit&by=date');
  });
});

describe('TrafficAnalyticsSection drill-down', () => {
  it('KPI cards open the explorer and zero cards stay plain', async () => {
    renderSection();

    const sessions = await screen.findByRole('link', { name: /sessions/i });
    expect(sessions).toHaveAttribute('href', '/analytics/traffic?by=channel&sort=sessions');
    expect(screen.getByText('Pageviews').closest('a')).toBeNull();
  });

  it('a trend day and a source slice open the explorer narrowed to them', async () => {
    analyticsService.trafficOverview.mockResolvedValue({
      totals: { sessions: 30 },
      trend: [{ date: '20261020', sessions: 10 }, { date: '20261021', sessions: 20 }],
      top_pages: [],
    });
    analyticsService.trafficSources.mockResolvedValue({ channels: [{ channel: 'Organic Search', sessions: 30 }] });
    const { unmount } = renderSection();

    fireEvent.click(await screen.findByRole('button', { name: /21\/10: 20/ }));
    expect(screen.getByTestId('location')).toHaveTextContent('/analytics/traffic?date=20261021&by=channel');
    unmount();

    renderSection();
    fireEvent.click((await screen.findAllByRole('button', { name: /organic search: 30/i }))[0]);
    expect(screen.getByTestId('location')).toHaveTextContent('/analytics/traffic?channel=Organic+Search&by=source');
  });

  it('a top page opens that page in the property it came from', async () => {
    analyticsService.trafficOverview.mockResolvedValue({
      totals: { sessions: 5, pageviews: 9 },
      trend: [],
      top_pages: [{ path: '/pricing', title: 'Pricing', pageviews: 9, site: 'https://aicountly.com', stream: 'marketing_site' }],
    });
    renderSection();

    expect(await screen.findByRole('link', { name: 'Pricing' }))
      .toHaveAttribute('href', '/analytics/traffic?stream=marketing_site&page=%2Fpricing&by=date');
  });

  it('offers no drill when GA4 is not configured', async () => {
    analyticsService.trafficOverview.mockResolvedValue({ totals: { sessions: 4 }, trend: [], top_pages: [], _unconfigured: true, _reason: 'nope' });
    renderSection();

    await screen.findByText(/nope/);
    expect(screen.getByText('Sessions').closest('a')).toBeNull();
  });
});
