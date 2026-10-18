import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/dashboardService', () => ({
  dashboardService: { summary: vi.fn(), counts: vi.fn(), commandCentre: vi.fn() },
}));
// The embedded pipeline tiles have their own tests (DashboardPage.test.jsx).
vi.mock('../../DashboardPage', () => ({ DashboardPage: () => <div data-testid="pipeline-stub" /> }));
vi.mock('../../../services/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn() },
  default: {},
}));

import { dashboardService } from '../../../services/dashboardService';
import { api } from '../../../services/api';
import { CommandCentrePage } from '../CommandCentrePage';

const now = new Date().toISOString();
const days = (n, f) => Array.from({ length: n }, (_, i) => f(i));

const full = {
  generated_at: now,
  health: {
    status: 'ok', built_at: now, bot_mode: 'auto', live_checks_at: now,
    integrations: [
      { id: 'gemini', label: 'Gemini (vetting, insights)', state: 'ok', message: 'Connected.', settings: '/admin/ai-automation#ai-providers' },
      { id: 'dataforseo', label: 'DataForSEO (rank tracker)', state: 'not_configured', message: 'Not configured.', settings: '/admin/ai-automation#seo' },
    ],
    queues: { completed: 812, failed: 3, queued: 2, stalled: 0, oldest_queued_secs: 90, queues: [], filters: { completed: 'status=completed&since_hours=24', failed: 'status=dead_letter,failed&since_hours=24', queued: 'ready=1', stalled: 'stalled=1' } },
    routines: [{ id: 'blog', label: 'Blog', enabled: true, last_at: now, last_24h: 4, stale: false }],
  },
  recommendations: {
    status: 'ok', built_at: now,
    cards: [
      { id: 'integration-dataforseo', severity: 'high', title: 'DataForSEO (rank tracker) is not configured', detail: 'Features that depend on it are skipped.', action: null, link: '/admin/ai-automation#seo', source: 'settings' },
      { id: 'insight-4', severity: 'low', title: 'Write a GST e-invoicing guide', detail: 'impact high', action: { label: 'Mark done', method: 'PATCH', endpoint: 'v1/intelligence/insights/actions/4', body: { status: 'done' }, permission: 'intelligence.manage' }, link: '/intelligence/insights', source: 'ai_insights' },
    ],
  },
  activity: {
    status: 'ok', built_at: now,
    counters: {
      blog_published: { label: 'Blog published', h24: 2, d7: 9, d30: 30 },
      vetting_rejected: { label: 'Rejected by vetting', h24: 0, d7: 1, d30: 4 },
    },
    events: [
      { kind: 'blog_published', label: 'Blog published', title: 'GST e-invoicing explained', at: now, link: '/content/7', external: false, tone: 'positive' },
      { kind: 'vetting_rejected', label: 'Rejected by vetting', title: 'blog blog:9', detail: 'unsupported claim', at: new Date(Date.now() - 3 * 86400e3).toISOString(), link: null, tone: 'negative' },
    ],
  },
  impact: {
    status: 'ok', built_at: now,
    search: {
      available: true, latest_date: '2026-10-15', window: { from: '2026-09-18', to: '2026-10-15' },
      current: { clicks: 56, impressions: 1120, ctr: 0.05, avg_position: 12 }, previous: { clicks: 40 },
      change: { clicks: 0.4, impressions: null },
      daily: days(40, (i) => ({ date: `d${i}`, clicks: 2, impressions: 40 })),
      top_queries: [{ dimension_value: 'gst billing software', clicks: 20, impressions: 300 }],
      top_pages: [{ dimension_value: 'https://aicountly.com/billing', clicks: 20, impressions: 300 }],
      reach_share: { clicks: 4, impressions: 80, pages: 2, share_of_clicks: 0.0714 },
      low_volume: true,
    },
    leads: { available: true, current: 3, previous: 1, change: 2, pushed: 3, push_problems: 0 },
    outcomes: { available: true, events: 2, conversions: 1, revenue_inr: 11800, content_attributed: 1, campaign_attributed: 0 },
    social: { available: false }, ga4: { available: false },
  },
  rankings: {
    status: 'ok', built_at: now,
    tracker: { available: true, latest_date: '2026-10-18', compared_with: '2026-10-11', keywords_checked: 2, ranked: 1, top10: 1, top10_share: 0.5, avg_rank: 8,
      movers_up: [{ keyword: 'gst software', rank: 8, previous_rank: 15, change: 7 }], movers_down: [], competitors: [{ name: 'Rival', domain: 'rival.example', keywords: 2, top10: 2, avg_rank: 3 }] },
    visibility: { available: false, note: 'No AI-visibility answers recorded yet.' },
  },
  projections: {
    status: 'ok', built_at: now, note: 'Forecasts appear once there are at least 28 days of data.',
    forecasts: {
      clicks: { label: 'Search clicks (site-wide)', available: true, method: 'Least-squares linear trend over the last 40 days with a day-of-week adjustment; 80% band from the residual spread.', points: 40, trend: 'flat', low_volume: true, horizon_days: 28, actual_last: { days: 28, total: 56 }, projected: { total: 56, low: 40, high: 72 }, series: days(28, (i) => ({ date: `f${i}`, value: 2, low: 1, high: 3 })) },
      leads: { label: 'Leads captured', available: false, points: 3, reason: 'Not enough data yet: 3 of 28 days needed for a forecast.' },
    },
    roadmap: { days: 14, items: [{ id: 1, title: 'Scheduled post', type: 'blog', date: '2026-10-25', source: 'deployment' }], total: 1, by_type: { blog: 1 } },
    quotas: [{ id: 'blog', label: 'Blogs', enabled: true, per_day: 6, next_14_days: 84 }],
  },
};

const ctx = { auth: { user: { id: 1, email: 'o@x', role: 'super_admin' }, permissions: ['*'] } };

beforeEach(() => {
  vi.clearAllMocks();
  dashboardService.summary.mockResolvedValue({ blog: {}, jobs: {}, calendar_upcoming: [] });
});

describe('CommandCentrePage', () => {
  it('renders every section from the API', async () => {
    dashboardService.commandCentre.mockResolvedValue(full);
    renderWithAuth(<CommandCentrePage />, ctx);

    await waitFor(() => expect(screen.getByText('GST e-invoicing explained')).toBeInTheDocument());
    const health = screen.getByTestId('cc-health');
    expect(within(health).getByText('AUTO')).toBeInTheDocument();
    expect(within(health).getByText('3 failed').closest('a')).toHaveAttribute('href', '/admin/jobs?status=dead_letter,failed&since_hours=24');

    expect(screen.getByText('DataForSEO (rank tracker) is not configured')).toBeInTheDocument();
    expect(screen.getByText('gst billing software')).toBeInTheDocument();
    expect(screen.getByText(/Low volume/)).toBeInTheDocument();
    expect(screen.getByText('gst software')).toBeInTheDocument();
    expect(screen.getByText(/Least-squares linear trend over the last 40 days/)).toBeInTheDocument();
    expect(screen.getByText('Not enough data yet: 3 of 28 days needed for a forecast.')).toBeInTheDocument();
    expect(screen.getByText('Scheduled post')).toBeInTheDocument();
    expect(screen.getByText('6/day · 84 in 14 d')).toBeInTheDocument();
  });

  it('filters the activity feed by window and kind', async () => {
    dashboardService.commandCentre.mockResolvedValue(full);
    const user = userEvent.setup();
    renderWithAuth(<CommandCentrePage />, ctx);
    await waitFor(() => expect(screen.getByText('GST e-invoicing explained')).toBeInTheDocument());

    const feed = screen.getByTestId('cc-activity');
    expect(within(feed).getByText('blog blog:9')).toBeInTheDocument();          // 3 days ago, in 7 d
    await user.click(within(feed).getByRole('button', { name: '24 h' }));
    expect(within(feed).queryByText('blog blog:9')).not.toBeInTheDocument();
    await user.click(within(feed).getByRole('button', { name: '30 days' }));
    await user.click(within(feed).getByRole('button', { name: /Blog published/ }));
    expect(within(feed).queryByText('blog blog:9')).not.toBeInTheDocument();
    expect(within(feed).getByText('GST e-invoicing explained')).toBeInTheDocument();
  });

  it('runs a one-click action through the existing API', async () => {
    dashboardService.commandCentre.mockResolvedValue(full);
    api.patch.mockResolvedValue({});
    const user = userEvent.setup();
    renderWithAuth(<CommandCentrePage />, ctx);
    await waitFor(() => expect(screen.getByText('Write a GST e-invoicing guide')).toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: 'Mark done' }));
    expect(api.patch).toHaveBeenCalledWith('v1/intelligence/insights/actions/4', { status: 'done' });
    await waitFor(() => expect(dashboardService.commandCentre).toHaveBeenCalledWith({ fresh: 1 }));
  });

  it('hides actions the viewer may not perform', async () => {
    dashboardService.commandCentre.mockResolvedValue(full);
    renderWithAuth(<CommandCentrePage />, { auth: { user: { id: 2, email: 'v@x', role: 'viewer' }, permissions: ['dashboard.view'] } });
    await waitFor(() => expect(screen.getByText('Write a GST e-invoicing guide')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Mark done' })).not.toBeInTheDocument();
  });

  it('shows honest empty states and isolates a failed section', async () => {
    dashboardService.commandCentre.mockResolvedValue({
      generated_at: now,
      health: { ...full.health },
      recommendations: { status: 'empty', note: 'No action needed right now — every signal Reach checks is healthy.', cards: [] },
      activity: { status: 'empty', note: 'Nothing automated has been recorded in the last 30 days yet.', counters: {}, events: [] },
      impact: { status: 'empty', note: 'No traffic, lead or outcome data has been collected yet.' },
      rankings: { status: 'error', message: 'This section could not be loaded: boom' },
      projections: { status: 'empty', forecasts: { clicks: { label: 'Search clicks (site-wide)', available: false, reason: 'No Search Console data ingested yet.' } }, roadmap: { items: [] }, quotas: [] },
    });
    renderWithAuth(<CommandCentrePage />, ctx);

    await waitFor(() => expect(screen.getByText('No traffic, lead or outcome data has been collected yet.')).toBeInTheDocument());
    expect(screen.getByText('Nothing automated has been recorded in the last 30 days yet.')).toBeInTheDocument();
    expect(screen.getByText('No Search Console data ingested yet.')).toBeInTheDocument();
    expect(within(screen.getByTestId('cc-rankings')).getByRole('alert')).toHaveTextContent('boom');
    expect(screen.getByText(/No action needed right now/)).toBeInTheDocument();
  });

  it('shows skeletons while loading', () => {
    dashboardService.commandCentre.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<CommandCentrePage />, ctx);
    expect(screen.getAllByLabelText('Loading').length).toBeGreaterThan(3);
  });
});
