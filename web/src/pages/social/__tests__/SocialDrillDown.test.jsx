import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { Route, Routes, useLocation } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../services/socialInboxService', () => ({
  socialInboxService: { list: vi.fn(), summary: vi.fn(), retry: vi.fn() },
}));
vi.mock('../../../services/adsService', () => ({
  adsService: { summary: vi.fn(), list: vi.fn() },
}));

import api from '../../../services/api';
import { socialInboxService } from '../../../services/socialInboxService';
import { adsService } from '../../../services/adsService';
import SocialCalendarTab from '../SocialCalendarTab';
import SocialPerformanceTab from '../SocialPerformanceTab';
import { SocialInboxPage } from '../SocialInboxPage';
import { SocialAdsPage } from '../ads/SocialAdsPage';

const ADMIN = { user: { id: 1, role: 'super_admin' }, permissions: ['*'] };

function Where() {
  const { search } = useLocation();
  return <output data-testid="search">{search}</output>;
}

/** Render a page with a probe showing the current query string. */
function at(element, route, auth = ADMIN) {
  return renderWithAuth(
    <Routes><Route path="*" element={<>{element}<Where /></>} /></Routes>,
    { auth, route },
  );
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('Social calendar deep links', () => {
  it('applies ?status= (a chip) and ?channel= from the URL', async () => {
    api.get.mockResolvedValue({ items: [], total: 0 });
    at(<SocialCalendarTab />, '/social/calendar?status=failed&channel=linkedin');

    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(api.get).toHaveBeenCalledWith('v1/social/posts', expect.objectContaining({ status: 'failed', channel: 'linkedin' }));
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Failed' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('combobox', { name: 'Network' })).toHaveValue('linkedin');
  });

  it('narrows to a single post status and offers a way back to Upcoming', async () => {
    api.get.mockResolvedValue({ items: [], total: 0 });
    at(<SocialCalendarTab />, '/social/calendar?status=scheduled');

    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(api.get.mock.calls.map(([, p]) => p.status)).toEqual(['scheduled']);

    fireEvent.click(screen.getByRole('button', { name: /clear scheduled filter/i }));
    await waitFor(() => expect(api.get.mock.calls.map(([, p]) => p.status).sort())
      .toEqual(['approved', 'manual_queue', 'scheduled', 'scheduled']));
    expect(screen.getByTestId('search')).toHaveTextContent(/^$/);
  });

  it('writes chip and network changes back to the URL', async () => {
    api.get.mockResolvedValue({ items: [], total: 0 });
    at(<SocialCalendarTab />, '/social/calendar');
    await screen.findByTestId('calendar-empty');

    fireEvent.click(screen.getByRole('button', { name: 'Posted' }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Network' }), { target: { value: 'youtube' } });
    await waitFor(() => expect(screen.getByTestId('search')).toHaveTextContent('?status=posted&channel=youtube'));
  });
});

describe('Social performance drill-down', () => {
  it('links tiles and network × status cells to the filtered calendar; zeros stay plain', async () => {
    api.get.mockImplementation((path, { status }) => Promise.resolve({
      posted: { items: [{ id: 1, channel: 'linkedin', published_at: new Date().toISOString() }], total: 1 },
      failed: { items: [{ id: 3, channel: 'linkedin' }, { id: 4, channel: 'tiktok' }], total: 2 },
      publishing: { items: [], total: 0 },
      scheduled: { items: [{ id: 5, channel: 'youtube' }], total: 1 },
    }[status]));
    at(<SocialPerformanceTab />, '/social/performance');

    const tiles = await screen.findByTestId('performance-tiles');
    expect(within(tiles).getByRole('link', { name: '1' })).toHaveAttribute('href', '/social/calendar?status=posted');
    expect(within(tiles).getByRole('link', { name: '2' })).toHaveAttribute('href', '/social/calendar?status=failed');
    expect(within(tiles).getByRole('link', { name: '1 scheduled' })).toHaveAttribute('href', '/social/calendar?status=scheduled');
    expect(within(tiles).queryByRole('link', { name: '0' })).not.toBeInTheDocument();

    expect(screen.getByRole('link', { name: '1 failed on LinkedIn' })).toHaveAttribute('href', '/social/calendar?status=failed&channel=linkedin');
    expect(screen.getByRole('link', { name: '1 scheduled on YouTube' })).toHaveAttribute('href', '/social/calendar?status=scheduled&channel=youtube');
    expect(screen.queryByRole('link', { name: '0 posted on YouTube' })).not.toBeInTheDocument();
    // "Other" networks have no channel to filter the calendar by.
    expect(screen.queryByRole('link', { name: /on Other/ })).not.toBeInTheDocument();
  });
});

describe('Social inbox drill-down', () => {
  const summary = {
    config: { enabled: true, auto_reply: true, social_api_key: true, flow: 'configured' },
    last_24h: { received: 5, replies_sent: 2, tickets: 1, leads: 1, by_classification: { complaint: 1, unclassified: 2 } },
  };

  beforeEach(() => {
    socialInboxService.summary.mockResolvedValue(summary);
    socialInboxService.list.mockResolvedValue({ items: [], total: 0 });
  });

  it('applies the URL filters to the list call and shows tile-only ones as removable chips', async () => {
    at(<SocialInboxPage />, '/social/inbox?classification=complaint&has=ticket&since_hours=24');

    await waitFor(() => expect(socialInboxService.list).toHaveBeenCalled());
    expect(socialInboxService.list).toHaveBeenCalledWith(expect.objectContaining({
      classification: 'complaint', has: 'ticket', since_hours: '24', page: 1,
    }));
    expect(screen.getByLabelText('Classification')).toHaveValue('complaint');
    fireEvent.click(await screen.findByRole('button', { name: /clear has a flow ticket filter/i }));
    await waitFor(() => expect(socialInboxService.list).toHaveBeenLastCalledWith(
      expect.not.objectContaining({ has: 'ticket' }),
    ));
  });

  it('day tiles narrow the list to the rows they counted', async () => {
    at(<SocialInboxPage />, '/social/inbox?network=x');
    fireEvent.click(await screen.findByTestId('tile-replies'));

    await waitFor(() => expect(screen.getByTestId('search')).toHaveTextContent('?reply_status=sent&replied_since_hours=24'));
    expect(socialInboxService.list).toHaveBeenLastCalledWith(expect.objectContaining({
      reply_status: 'sent', replied_since_hours: '24', network: '',
    }));
    expect(screen.getByTestId('tile-replies')).toHaveAttribute('aria-pressed', 'true');

    fireEvent.click(screen.getByTestId('tile-leads'));
    await waitFor(() => expect(screen.getByTestId('search')).toHaveTextContent('?has=lead&since_hours=24'));
    expect(screen.getByRole('link', { name: /all social inbox leads/i })).toHaveAttribute('href', '/leads?source_kind=social_inbox');
  });

  it('classification counts drill; unclassified does not', async () => {
    at(<SocialInboxPage />, '/social/inbox');
    fireEvent.click(await screen.findByRole('button', { name: /show 1 complaint messages/i }));
    await waitFor(() => expect(screen.getByTestId('search')).toHaveTextContent('?classification=complaint&since_hours=24'));
    expect(screen.queryByRole('button', { name: /unclassified/i })).not.toBeInTheDocument();
  });

  it('opens the lead a message created', async () => {
    socialInboxService.list.mockResolvedValue({ items: [{ id: 9, network: 'linkedin', text: 'Pricing?', status: 'lead_created', lead_id: 55 }], total: 1 });
    at(<SocialInboxPage />, '/social/inbox');
    expect(await screen.findByRole('link', { name: 'Lead #55' })).toHaveAttribute('href', '/leads?lead_id=55');
  });
});

describe('Ads drill-down', () => {
  it('links a campaign’s leads to the leads it was credited with', async () => {
    adsService.list.mockResolvedValue({ items: [{ id: 7, platform: 'google_search', name: 'Soft launch', status: 'live', start_date: '2026-10-18', tracking_links: [] }] });
    adsService.summary.mockResolvedValue({
      period: { from: '2026-09-24', to: '2026-10-23' },
      totals: { leads: 3 },
      budget: { launch_plan: {} },
      campaigns: [{ id: 7, leads: 3, start_date: '2026-10-18', end_date: null, utm: { source: 'google', medium: 'cpc', campaign: 'soft-launch' } }],
    });
    at(<SocialAdsPage />, '/social/ads');

    const link = await screen.findByRole('link', { name: '3 leads for Soft launch' });
    expect(link).toHaveAttribute('href',
      '/leads?utm_source=google&utm_medium=cpc&utm_campaign=soft-launch&created_from=2026-10-18&created_to=2026-10-23');
    expect(screen.getByTestId('tile-leads')).toHaveAttribute('href', '#ads-campaign-register');
  });

  it('does not link a campaign with no leads', async () => {
    adsService.list.mockResolvedValue({ items: [{ id: 7, platform: 'google_search', name: 'Soft launch', status: 'live', start_date: '2026-10-18', tracking_links: [] }] });
    adsService.summary.mockResolvedValue({ totals: { leads: 0 }, budget: { launch_plan: {} }, campaigns: [{ id: 7, leads: 0, utm: { source: 'google' } }] });
    at(<SocialAdsPage />, '/social/ads');

    await screen.findByTestId('ads-campaigns');
    expect(screen.queryByRole('link', { name: /leads for Soft launch/ })).not.toBeInTheDocument();
    expect(screen.getByTestId('tile-leads').tagName).toBe('DIV');
  });
});
