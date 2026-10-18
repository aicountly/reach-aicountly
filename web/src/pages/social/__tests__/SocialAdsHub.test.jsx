import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { Route, Routes } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../services/adminService', () => ({ adminService: { runLiveChecks: vi.fn() } }));
import api from '../../../services/api';
import { adminService } from '../../../services/adminService';
import SocialAdsLayout from '../SocialAdsLayout';
import SocialAccountsTab from '../SocialAccountsTab';
import SocialCalendarTab from '../SocialCalendarTab';
import SocialPerformanceTab from '../SocialPerformanceTab';
import SocialAdsPlaceholder from '../SocialAdsPlaceholder';
import { groupByDay, postedWithin, sortPosts, successRate, tabulate, canRetry } from '../socialAdsModel';

const ADMIN = { user: { id: 1, role: 'super_admin' }, permissions: ['*'] };

function inHub(element, route, auth = ADMIN) {
  return renderWithAuth(
    <Routes>
      <Route path="/social" element={<SocialAdsLayout />}>
        <Route path="accounts" element={<SocialAccountsTab />} />
        <Route path="calendar" element={<SocialCalendarTab />} />
        <Route path="performance" element={<SocialPerformanceTab />} />
        <Route path="ads" element={<SocialAdsPlaceholder />} />
        <Route path="*" element={element} />
      </Route>
    </Routes>,
    { auth, route },
  );
}

const READINESS = {
  generated_at: '2026-10-18 06:00:00+00',
  environment: 'production',
  production: true,
  channels: [
    { channel: 'email', label: 'Email', group: 'messaging', state: 'ready' },
    { channel: 'linkedin', label: 'LinkedIn', group: 'social', state: 'ready', provider: 'socialapi', reasons: [], checks: [], account: { id: 'acc_li', name: 'AICOUNTLY', username: 'aicountly', source: 'auto' } },
    { channel: 'facebook', label: 'Facebook', group: 'social', state: 'blocked', reasons: ['No active Facebook account in social-api.ai.'], checks: [{ key: 'social.socialapi.account.facebook', ok: false }] },
    { channel: 'youtube', label: 'YouTube', group: 'social', state: 'ready', account: { id: 'acc_yt', name: 'AICOUNTLY TV', source: 'setting' }, youtube_publisher: 'social_api', reasons: [] },
    { channel: 'google_ads', label: 'Google Ads', group: 'ads', state: 'not_supported' },
  ],
};

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
  adminService.runLiveChecks.mockReset();
});

describe('Social & Ads hub — Accounts', () => {
  it('shows each network with the account Reach posts to and the honest state', async () => {
    api.get.mockResolvedValueOnce(READINESS);
    inHub(null, '/social/accounts');
    expect(screen.getByRole('heading', { level: 1, name: 'Social & Ads' })).toBeInTheDocument();
    const li = await screen.findByTestId('account-linkedin');
    expect(api.get).toHaveBeenCalledWith('v1/distribution/readiness');
    expect(li).toHaveTextContent('Connected');
    expect(li).toHaveTextContent('AICOUNTLY');
    expect(li).toHaveTextContent('acc_li');
    const fb = screen.getByTestId('account-facebook');
    expect(fb).toHaveTextContent('Blocked');
    expect(fb).toHaveTextContent('No active Facebook account');
    expect(fb).toHaveTextContent('social.socialapi.account.facebook');
    expect(screen.getByTestId('account-youtube')).toHaveTextContent('video.youtube.publisher = social_api');
    expect(screen.queryByTestId('account-email')).not.toBeInTheDocument();
    expect(screen.queryByTestId('account-google_ads')).not.toBeInTheDocument();
    expect(screen.getByTestId('accounts-summary')).toHaveTextContent('2 of 3 networks ready');
  });

  it('runs the social-api.ai live check (never posts) and shows what it found', async () => {
    api.get.mockResolvedValue(READINESS);
    adminService.runLiveChecks.mockResolvedValueOnce({
      results: [{ target: 'social_api', ok: true, message: 'Key accepted; 2 connected account(s). Nothing was posted.', detail: { accounts: ['linkedin “AICOUNTLY” id=acc_li status=active'], targets: { linkedin: 'posts go to acc_li' } } }],
    });
    inHub(null, '/social/accounts');
    await screen.findByTestId('account-linkedin');
    fireEvent.click(screen.getByRole('button', { name: /Run live check/ }));
    const res = await screen.findByTestId('social-live-check');
    expect(adminService.runLiveChecks).toHaveBeenCalledWith(['social_api']);
    expect(res).toHaveTextContent('Nothing was posted');
  });

  it('hides the live check without settings.manage and explains a 403', async () => {
    const err = Object.assign(new Error('Forbidden'), { status: 403 });
    api.get.mockRejectedValueOnce(err);
    renderWithAuth(<SocialAccountsTab />, { auth: { user: { id: 2 }, permissions: ['social.view'] } });
    expect(await screen.findByRole('alert')).toHaveTextContent('distribution.read');
    expect(screen.queryByRole('button', { name: /Run live check/ })).not.toBeInTheDocument();
  });
});

describe('Social & Ads hub — Calendar & queue', () => {
  const upcoming = [
    { id: 11, channel: 'linkedin', status: 'scheduled', content: 'Launch post\nmore text', scheduled_at: '2026-10-20 05:30:00+00', approval_status: 'approved' },
    { id: 12, channel: 'youtube', status: 'scheduled', title: 'GST in 60 seconds #Shorts', content: 'desc', scheduled_at: '2026-10-19 05:30:00+00', approval_status: 'approved' },
  ];

  it('lists upcoming posts (incl. YouTube Shorts) grouped by day across the three upcoming statuses', async () => {
    api.get.mockImplementation((path, params) => Promise.resolve(params.status === 'scheduled' ? { items: upcoming, total: 2 } : { items: [], total: 0 }));
    inHub(null, '/social/calendar');
    expect(await screen.findByText('GST in 60 seconds #Shorts')).toBeInTheDocument();
    const statuses = api.get.mock.calls.map(([, p]) => p.status).sort();
    expect(statuses).toEqual(['approved', 'manual_queue', 'scheduled']);
    expect(api.get.mock.calls.every(([p]) => p === 'v1/social/posts')).toBe(true);
    const days = screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent);
    expect(days).toEqual(['19-10-2026', '20-10-2026']);
    expect(screen.getByText('Launch post')).toBeInTheDocument();
  });

  it('shows an empty state per filter and network', async () => {
    api.get.mockResolvedValue({ items: [], total: 0 });
    inHub(null, '/social/calendar');
    expect(await screen.findByTestId('calendar-empty')).toHaveTextContent('No upcoming posts');
    fireEvent.change(screen.getByRole('combobox', { name: 'Network' }), { target: { value: 'instagram' } });
    await waitFor(() => expect(screen.getByTestId('calendar-empty')).toHaveTextContent('No upcoming posts on Instagram'));
    expect(api.get).toHaveBeenLastCalledWith('v1/social/posts', expect.objectContaining({ channel: 'instagram' }));
  });

  it('failed posts: link to the live post, two-step retry, video retries in the pipeline', async () => {
    const failed = [
      { id: 21, channel: 'linkedin', status: 'failed', approval_status: 'approved', content: 'Oops post', provider_error_code: 'publishing.delivery.timeout', error_message: 'No confirmation within 30 minutes.', submitted_at: '2026-10-18 04:00:00+00' },
      { id: 22, channel: 'youtube', status: 'failed', approval_status: 'approved', title: 'Weekly vlog', submitted_at: '2026-10-18 03:00:00+00' },
    ];
    api.get.mockImplementation((path, params) => Promise.resolve(params.status === 'failed' ? { items: failed, total: 2 } : { items: [], total: 0 }));
    api.post.mockResolvedValueOnce({ status: 'publishing' });
    inHub(null, '/social/calendar');
    await screen.findByTestId('calendar-empty');
    fireEvent.click(screen.getByRole('button', { name: 'Failed' }));
    const row = await screen.findByTestId('post-21');
    expect(row).toHaveTextContent('publishing.delivery.timeout');
    expect(within(screen.getByTestId('post-22')).getByRole('link', { name: 'Retry in Video pipeline' })).toHaveAttribute('href', '/video');
    expect(within(screen.getByTestId('post-22')).queryByRole('button', { name: /Retry/ })).not.toBeInTheDocument();

    fireEvent.click(within(row).getByRole('button', { name: /Retry/ }));
    expect(api.post).not.toHaveBeenCalled();
    fireEvent.click(within(row).getByRole('button', { name: 'Confirm retry' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('v1/distribution/social/dispatch/21'));
    expect(await screen.findByText(/shows Posted only once social-api.ai confirms/)).toBeInTheDocument();
  });

  it('no retry button without distribution.dispatch; posted rows link to the post', async () => {
    const posted = [{ id: 31, channel: 'facebook', status: 'posted', content: 'Hello FB', remote_url: 'https://facebook.com/p/31', published_at: '2026-10-17 10:00:00+00' }];
    api.get.mockImplementation((path, params) => Promise.resolve(params.status === 'posted' ? { items: posted, total: 1 } : { items: [], total: 0 }));
    renderWithAuth(<SocialCalendarTab />, { auth: { user: { id: 3 }, permissions: ['social.view'] } });
    await screen.findByTestId('calendar-empty');
    fireEvent.click(screen.getByRole('button', { name: 'Posted' }));
    const row = await screen.findByTestId('post-31');
    expect(within(row).getByRole('link', { name: /View post/ })).toHaveAttribute('href', 'https://facebook.com/p/31');
    expect(within(row).queryByRole('button')).not.toBeInTheDocument();
  });
});

describe('Social & Ads hub — Performance & Ads', () => {
  it('counts outcomes per network and says engagement data is not connected', async () => {
    api.get.mockImplementation((path, { status }) => Promise.resolve({
      posted: { items: [{ id: 1, channel: 'linkedin', published_at: new Date().toISOString() }, { id: 2, channel: 'youtube', published_at: '2020-01-01' }], total: 2 },
      failed: { items: [{ id: 3, channel: 'linkedin' }], total: 1 },
      publishing: { items: [], total: 0 },
      scheduled: { items: [], total: 0 },
    }[status]));
    inHub(null, '/social/performance');
    const tiles = await screen.findByTestId('performance-tiles');
    expect(tiles).toHaveTextContent('Posted2');
    expect(tiles).toHaveTextContent('1 in the last 7 days');
    expect(tiles).toHaveTextContent('67%');
    const li = screen.getByRole('row', { name: /LinkedIn/ });
    expect(within(li).getAllByRole('cell').map((c) => c.textContent)).toEqual(['1', '1', '0', '0']);
    expect(screen.getByTestId('engagement-empty')).toHaveTextContent('no data source connected');
  });

  it('empty performance: no fake numbers', async () => {
    api.get.mockResolvedValue({ items: [], total: 0 });
    inHub(null, '/social/performance');
    const tiles = await screen.findByTestId('performance-tiles');
    expect(tiles).toHaveTextContent('Delivery success—');
    expect(screen.getByText('No social posts have been sent yet.')).toBeInTheDocument();
  });

  it('Ads tab: not connected, manual placement, nothing pretends to be live', () => {
    inHub(null, '/social/ads');
    expect(screen.getByTestId('ads-not-connected')).toHaveTextContent('Google Ads, Meta Ads, LinkedIn Ads: API not connected');
    expect(screen.getByText(/developer token/)).toBeInTheDocument();
    expect(screen.getByText(/system user/)).toBeInTheDocument();
    expect(api.get).not.toHaveBeenCalled();
  });

  it('tabs follow permissions', () => {
    api.get.mockResolvedValue({ items: [], total: 0 });
    inHub(null, '/social/calendar', { user: { id: 5 }, permissions: ['social.view'] });
    const nav = screen.getByRole('navigation', { name: 'Social & Ads navigation' });
    expect(within(nav).queryByText('Accounts')).not.toBeInTheDocument();
    expect(within(nav).getByText('Calendar & queue')).toBeInTheDocument();
    expect(within(nav).getByText('Ads')).toBeInTheDocument();
  });
});

describe('socialAdsModel', () => {
  it('sorts, groups and rates', () => {
    const posts = [
      { id: 1, status: 'scheduled', scheduled_at: '2026-10-20 10:00:00+00' },
      { id: 2, status: 'scheduled', scheduled_at: null, created_at: null },
      { id: 3, status: 'scheduled', scheduled_at: '2026-10-19T10:00:00Z' },
    ];
    expect(sortPosts(posts, 'asc').map((p) => p.id)).toEqual([3, 1, 2]);
    expect(groupByDay(sortPosts(posts)).map((g) => g.posts.length)).toEqual([1, 1, 1]);
    expect(successRate(0, 0)).toBeNull();
    expect(successRate(3, 1)).toBe(75);
    expect(postedWithin([{ published_at: new Date(Date.now() - 2 * 86_400_000).toISOString() }], 7)).toBe(1);
    expect(canRetry({ status: 'failed', approval_status: 'approved', channel: 'x' })).toBe(true);
    expect(canRetry({ status: 'failed', approval_status: 'pending', channel: 'linkedin' })).toBe(false);
    const t = tabulate({ posted: { items: [{ channel: 'tiktok' }], total: 5 } });
    expect(t.rows.find((r) => r.key === 'other').counts.posted).toBe(1);
    expect(t.truncated).toEqual(['posted']);
  });
});
