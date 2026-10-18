import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../../test/renderWithAuth';

vi.mock('../../../../services/adsService', () => ({
  adsService: {
    summary: vi.fn(), list: vi.fn(), create: vi.fn(), update: vi.fn(), archive: vi.fn(),
    launchPlan: vi.fn(), saveResult: vi.fn(), importCsv: vi.fn(),
  },
}));

import { adsService } from '../../../../services/adsService';
import { SocialAdsPage } from '../SocialAdsPage';

const EMPTY_SUMMARY = {
  has_results: false, api_connected: false,
  totals: { spend_inr: null, impressions: null, clicks: null, signups: null, leads: 0, ctr: null, cpc_inr: null, cost_per_lead_inr: null, cost_per_signup_inr: null },
  budget: { live_daily_inr: 0, planned_daily_inr: 0, launch_plan: { daily_total_inr: 2000 } },
  campaigns: [],
};

const CAMPAIGN = {
  id: 7, platform: 'google_search', name: 'Soft launch — Search', status: 'live', daily_budget_inr: 600,
  start_date: '2026-10-18', end_date: null, landing_url: 'https://aicountly.com/free-accounting-software',
  utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'soft-launch-2026-10', creatives: [{ id: 'gs-rsa-01' }],
  tracking_links: [{ creative_id: 'gs-rsa-01', url: 'https://aicountly.com/free-accounting-software?utm_source=google&utm_medium=cpc&utm_campaign=soft-launch-2026-10&utm_content=gs-rsa-01' }],
};

const editor = { permissions: ['campaign.view', 'campaign.edit', 'campaign.create'] };

beforeEach(() => {
  adsService.summary.mockResolvedValue(EMPTY_SUMMARY);
  adsService.list.mockResolvedValue({ items: [] });
});

describe('SocialAdsPage', () => {
  it('says plainly that no ads API is connected and shows unknown figures as —, not 0', async () => {
    renderWithAuth(<SocialAdsPage />, { auth: editor });
    await waitFor(() => expect(screen.getByTestId('ads-summary')).toBeInTheDocument());
    expect(screen.getByTestId('ads-not-connected')).toHaveTextContent(/Not connected to the Google Ads, Meta or LinkedIn ads APIs/);
    expect(within(screen.getByTestId('tile-spend')).getByText('—')).toBeInTheDocument();
    expect(screen.getByTestId('tile-spend')).toHaveTextContent('no results entered yet');
    expect(within(screen.getByTestId('tile-signups')).getByText('—')).toBeInTheDocument();
    expect(screen.getByTestId('tile-budget')).toHaveTextContent('a plan, not spend');
    expect(screen.getByText(/No paid campaigns registered/)).toBeInTheDocument();
  });

  it('shows the register with real figures and tracking links', async () => {
    adsService.list.mockResolvedValue({ items: [CAMPAIGN] });
    adsService.summary.mockResolvedValue({
      ...EMPTY_SUMMARY, has_results: true,
      totals: { ...EMPTY_SUMMARY.totals, spend_inr: 1212, clicks: 80, signups: 4, leads: 1, cost_per_signup_inr: 303 },
      campaigns: [{ id: 7, spend_inr: 1212, clicks: 80, leads: 1, signups: 3, days_missing_results: 2 }],
    });
    renderWithAuth(<SocialAdsPage />, { auth: editor });
    const table = await screen.findByTestId('ads-campaigns');
    expect(within(table).getByText('Soft launch — Search')).toBeInTheDocument();
    expect(within(table).getByText('₹1,212.00')).toBeInTheDocument();
    expect(within(table).getByText('2 day(s) without results')).toBeInTheDocument();
    expect(within(table).getByText('gs-rsa-01')).toBeInTheDocument();
    expect(screen.getByTestId('tile-signups')).toHaveTextContent('Cost per sign-up ₹303.00');
  });

  it('registers a campaign with the platform conventions and suggested budget', async () => {
    adsService.create.mockResolvedValue({ id: 1 });
    const user = userEvent.setup();
    renderWithAuth(<SocialAdsPage />, { auth: editor });
    await user.click(await screen.findByRole('button', { name: 'Register campaign' }));
    const form = screen.getByRole('form', { name: 'Ad campaign' });
    await user.selectOptions(within(form).getByLabelText('Platform'), 'linkedin');
    expect(within(form).getByLabelText('Daily budget (₹)')).toHaveValue(800);
    expect(within(form).getByText(/utm_source=linkedin, utm_medium=paid_social/)).toBeInTheDocument();
    await user.type(within(form).getByLabelText('Name'), 'Soft launch LinkedIn');
    await user.click(within(form).getByRole('button', { name: 'Register' }));
    await waitFor(() => expect(adsService.create).toHaveBeenCalled());
    const body = adsService.create.mock.calls[0][0];
    expect(body).toMatchObject({ platform: 'linkedin', name: 'Soft launch LinkedIn', daily_budget_inr: '800', utm_campaign: 'soft-launch-2026-10', creatives: [{ id: 'li-01' }] });
    expect(body).not.toHaveProperty('id');
  });

  it('builds UTM links under the conventions and flags a bad creative id', async () => {
    const user = userEvent.setup();
    renderWithAuth(<SocialAdsPage />, { auth: editor });
    const builder = await screen.findByTestId('utm-builder');
    await user.selectOptions(within(builder).getByLabelText('Platform'), 'meta');
    expect(screen.getByTestId('utm-result')).toHaveTextContent('utm_source=meta&utm_medium=paid_social&utm_campaign=soft-launch-2026-10&utm_content=fb-01');
    const creative = within(builder).getByLabelText('Creative id (utm_content)');
    await user.clear(creative);
    await user.type(creative, 'Reel 1');
    expect(within(builder).getByText(/creative id/)).toBeInTheDocument();
  });

  it('previews a CSV before importing it and lists unmatched rows', async () => {
    adsService.importCsv
      .mockResolvedValueOnce({ dry_run: true, format: 'google_ads', rows_used: 2, unmatched_total: 1, rejected_total: 0, skipped: 1, stored_days: 0, campaigns: [{ ad_campaign_id: 7, name: 'Soft launch — Search', days: 2, spend_inr: 1212, clicks: 80, signups: null }], unmatched: [{ line: 5, campaign: 'Other', reason: 'No registered campaign has this ads-manager name.' }], rejected: [] })
      .mockResolvedValueOnce({ dry_run: false, format: 'google_ads', rows_used: 2, unmatched_total: 1, rejected_total: 0, skipped: 1, stored_days: 2, campaigns: [], unmatched: [], rejected: [] });
    const user = userEvent.setup();
    renderWithAuth(<SocialAdsPage />, { auth: editor });
    const box = await screen.findByTestId('csv-import');
    expect(within(box).getByRole('button', { name: 'Import' })).toBeDisabled();
    const file = new File(['Day,Campaign,Cost\n'], 'report.csv', { type: 'text/csv' });
    fireEvent.change(within(box).getByLabelText(/File/), { target: { files: [file] } });
    await user.click(within(box).getByRole('button', { name: 'Preview' }));
    const report = await screen.findByTestId('csv-report');
    expect(report).toHaveTextContent('Preview (google_ads): 2 row(s) matched, 1 unmatched');
    expect(report).toHaveTextContent('Line 5 — Other: No registered campaign');
    expect(adsService.importCsv).toHaveBeenLastCalledWith(file, 'auto', true);
    await user.click(within(box).getByRole('button', { name: 'Import' }));
    await waitFor(() => expect(adsService.importCsv).toHaveBeenLastCalledWith(file, 'auto', false));
    expect(await screen.findByText('Imported 2 campaign-day(s).')).toBeInTheDocument();
  });

  it('is read-only without edit permission', async () => {
    renderWithAuth(<SocialAdsPage />, { auth: { permissions: ['social.view'] } });
    await screen.findByTestId('ads-summary');
    expect(screen.queryByRole('button', { name: 'Register campaign' })).not.toBeInTheDocument();
    expect(screen.queryByTestId('csv-import')).not.toBeInTheDocument();
    expect(screen.getByTestId('utm-builder')).toBeInTheDocument();
  });
});
