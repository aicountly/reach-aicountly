import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within, fireEvent } from '@testing-library/react';
import { Routes, Route, useLocation } from 'react-router-dom';
import { renderWithAuth } from '../../../../test/renderWithAuth';

vi.mock('../../../../services/adsService', () => ({
  adsService: { summary: vi.fn(), list: vi.fn(), get: vi.fn(), results: vi.fn(), deleteResult: vi.fn() },
}));

import { adsService } from '../../../../services/adsService';
import { SocialAdsPage } from '../SocialAdsPage';
import { AdCampaignDetailPage } from '../AdCampaignDetailPage';

const ADMIN = { user: { id: 1, role: 'super_admin' }, permissions: ['*'] };

function Where() {
  const { pathname, search } = useLocation();
  return <output data-testid="where">{pathname}{search}</output>;
}

function at(route) {
  return renderWithAuth(
    <Routes>
      <Route path="/social/ads" element={<><SocialAdsPage /><Where /></>} />
      <Route path="/social/ads/:id" element={<><AdCampaignDetailPage /><Where /></>} />
    </Routes>,
    { auth: ADMIN, route },
  );
}

const PERIOD = { from: '2026-09-24', to: '2026-10-23' };
const row = (over) => ({
  platform: 'google_search', status: 'live', start_date: '2026-10-01', end_date: null, daily_budget_inr: 600,
  utm: { source: 'google', medium: 'cpc', campaign: 'soft-launch-2026-10' },
  spend_inr: null, clicks: null, signups: null, leads: 0, ...over,
});
const SUMMARY = {
  period: PERIOD, has_results: true,
  totals: { spend_inr: 1300, clicks: 90, signups: 3, leads: 2 },
  budget: { live_daily_inr: 1400, launch_plan: { daily_total_inr: 2000 } },
  campaigns: [
    row({ id: 7, name: 'Search', spend_inr: 300, clicks: 60, signups: 3, leads: 2 }),
    row({ id: 8, name: 'Meta', platform: 'meta', status: 'paused', daily_budget_inr: 300, spend_inr: 1000, clicks: 30 }),
    row({ id: 9, name: 'LinkedIn', platform: 'linkedin', daily_budget_inr: 800 }),
  ],
};

beforeEach(() => {
  vi.clearAllMocks();
  adsService.summary.mockResolvedValue(SUMMARY);
  adsService.list.mockResolvedValue({ items: [] });
});

describe('Ads tiles → campaigns', () => {
  it('spend opens the campaigns with spend, largest first, each linking to its days', async () => {
    at('/social/ads');
    fireEvent.click(await screen.findByTestId('tile-spend'));

    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('/social/ads?metric=spend'));
    const panel = screen.getByTestId('ads-metric-campaigns');
    const links = within(panel).getAllByRole('link').filter((a) => a.getAttribute('href').startsWith('/social/ads/'));
    expect(links.map((a) => a.textContent)).toEqual(['Meta', 'Search']);
    expect(links[0]).toHaveAttribute('href', '/social/ads/8?from=2026-09-24&to=2026-10-23&metric=spend');
    expect(within(panel).getByText('Total (2)').closest('tr')).toHaveTextContent('₹1,300.00');
  });

  it('live budget opens only the live campaigns whose budget it adds', async () => {
    at('/social/ads?metric=budget');
    const panel = await screen.findByTestId('ads-metric-campaigns');
    const names = within(panel).getAllByRole('link').filter((a) => a.getAttribute('href').startsWith('/social/ads/')).map((a) => a.textContent);
    expect(names).toEqual(['LinkedIn', 'Search']);
    expect(within(panel).getByText('Total (2)').closest('tr')).toHaveTextContent('₹1,400.00');
  });

  it('a tile with nothing behind it does not drill', async () => {
    adsService.summary.mockResolvedValue({ ...SUMMARY, totals: { ...SUMMARY.totals, signups: null }, campaigns: [row({ id: 9, name: 'LinkedIn' })] });
    at('/social/ads');
    expect((await screen.findByTestId('tile-signups')).tagName).toBe('DIV');
  });
});

describe('Ad campaign → days → one day', () => {
  beforeEach(() => {
    adsService.get.mockResolvedValue({
      id: 7, name: 'Search', platform: 'google_search', status: 'live', daily_budget_inr: 600, start_date: '2026-10-01', end_date: null,
      utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'soft-launch-2026-10',
    });
    adsService.results.mockResolvedValue({
      items: [
        { id: 51, result_date: '2026-10-11', spend_inr: '200.00', impressions: 1000, clicks: 20, signups: null, metrics_source: 'google_ads_csv' },
        { id: 50, result_date: '2026-10-10', spend_inr: '100.00', impressions: 500, clicks: 40, signups: 3, metrics_source: 'manual', signups_source: 'manual' },
      ],
      leads_by_day: { '2026-10-10': 1, '2026-10-12': 1 },
      source: 'Figures are typed in.',
    });
  });

  it('lists the period days sorted by the drilled figure, with totals that match the tile row', async () => {
    at('/social/ads/7?from=2026-10-01&to=2026-10-20&metric=clicks');
    const table = await screen.findByTestId('ad-campaign-days');
    expect(adsService.results).toHaveBeenCalledWith('7', { from: '2026-10-01', to: '2026-10-20' });
    const days = within(table).getAllByRole('row').slice(1, -1).map((r) => r.getAttribute('aria-label'));
    expect(days).toEqual(['Results for 2026-10-10', 'Results for 2026-10-11', 'Results for 2026-10-12']);
    expect(within(table).getByText('no results entered')).toBeInTheDocument();
    const total = within(table).getByText('Total').closest('tr');
    expect(total).toHaveTextContent('₹300.00');
    expect(total).toHaveTextContent('60');
    expect(screen.getByRole('link', { name: '1 leads on 2026-10-12' })).toHaveAttribute('href',
      '/leads?utm_source=google&utm_medium=cpc&utm_campaign=soft-launch-2026-10&created_from=2026-10-12&created_to=2026-10-12');
    expect(screen.getByRole('link', { name: '2 leads for Search' })).toHaveAttribute('href',
      '/leads?utm_source=google&utm_medium=cpc&utm_campaign=soft-launch-2026-10&created_from=2026-10-01&created_to=2026-10-20');
  });

  it('a day opens its results row, which an editor can delete', async () => {
    adsService.deleteResult.mockResolvedValue({});
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    at('/social/ads/7?from=2026-10-01&to=2026-10-20');
    fireEvent.click(await screen.findByRole('row', { name: 'Results for 2026-10-10' }));

    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('day=2026-10-10'));
    const card = screen.getByTestId('ad-day');
    expect(card).toHaveTextContent('manual');
    expect(within(card).getByRole('link', { name: 'Open 1 leads on 2026-10-10' })).toBeInTheDocument();
    fireEvent.click(within(card).getByRole('button', { name: /delete this day/i }));
    await waitFor(() => expect(adsService.deleteResult).toHaveBeenCalledWith(50));
  });
});
