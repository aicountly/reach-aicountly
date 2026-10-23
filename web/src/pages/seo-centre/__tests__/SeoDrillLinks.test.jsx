import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

const getTracker = vi.fn();
vi.mock('../../../services/seoService', () => ({
  seoService: {
    getTracker: (...a) => getTracker(...a),
    runTracker: vi.fn(), createTrackedKeyword: vi.fn(), updateTrackedKeyword: vi.fn(),
  },
}));
vi.mock('../../../services/api', () => ({ api: { get: vi.fn(), post: vi.fn() } }));

import { api } from '../../../services/api';
import { SeoTrackerPage } from '../SeoTrackerPage';
import { SeoOverviewPage } from '../SeoOverviewPage';
import { TrackedKeywordsPage } from '../TrackedKeywordsPage';
import { SeoSuggestionsPage } from '../SeoSuggestionsPage';

const status = { status: 'ready', detail: 'ok', location_code: 2356, language_code: 'en', depth: 100 };
const kw = (id, keyword, extra) => ({ id, keyword, enabled: true, check_status: 'ok', our_rank: null, change: null, competitor_ranks: {}, winner: null, ...extra });
const tracker = {
  status, date: '2026-10-17',
  competitors: [{ id: 3, name: 'Vyapar', domain: 'vyaparapp.in' }],
  keywords: [
    kw(1, 'gst billing software', { our_rank: 7, change: 8, winner: { name: 'aicountly', rank: 7, ours: true } }),
    kw(2, 'tally alternative', { our_rank: 40, change: -3, competitor_ranks: { 3: 2 }, winner: { name: 'Vyapar', rank: 2, ours: false } }),
    kw(3, 'inventory app', { check_status: 'failed' }),
  ],
  summary: { checked: 2, we_rank: 2, top10: 1, up: 1, down: 1, competitor_wins: 1 },
  top10_share: [
    { id: null, name: 'aicountly', domain: 'aicountly.com', ours: true, top10: 1, share: 0.5 },
    { id: 3, name: 'Vyapar', domain: 'vyaparapp.in', ours: false, top10: 1, share: 0.5 },
  ],
};
const viewer = { auth: { user: { id: 1 }, permissions: ['seo.view'] } };

beforeEach(() => {
  vi.clearAllMocks();
  getTracker.mockResolvedValue(tracker);
});

describe('SEO Tracker tiles filter the keyword table', () => {
  it('applies ?show= from the URL with the same predicate the tile counted', async () => {
    renderWithAuth(<SeoTrackerPage />, { ...viewer, route: '/seo-centre/tracker?show=competitor_leads' });
    expect(await screen.findByText('tally alternative')).toBeInTheDocument();
    expect(screen.queryByText('gst billing software')).not.toBeInTheDocument();
    expect(screen.getByText(/Tracked keywords \(1 of 3\)/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /clear competitor leads filter/i }));
    expect(await screen.findByText('gst billing software')).toBeInTheDocument();
  });

  it('a tile narrows the table; a share row narrows to that competitor', async () => {
    renderWithAuth(<SeoTrackerPage />, viewer);
    fireEvent.click(await screen.findByRole('button', { name: /We are top 10: 1/ }));
    expect(screen.queryByText('tally alternative')).not.toBeInTheDocument();
    expect(screen.getByText('gst billing software')).toBeInTheDocument();
    // Share of top 10 → Vyapar's top-10 keywords.
    const vyaparRow = screen.getByText('vyaparapp.in').closest('tr');
    fireEvent.click(vyaparRow.querySelector('button.drill-link'));
    expect(await screen.findByText('tally alternative')).toBeInTheDocument();
    expect(screen.queryByText('gst billing software')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /clear competitor filter/i })).toBeInTheDocument();
  });

  it('keyword rows open the Search Console pages for that keyword', async () => {
    renderWithAuth(<SeoTrackerPage />, viewer);
    expect(await screen.findByRole('link', { name: 'gst billing software' })).toHaveAttribute(
      'href', '/seo-centre/search?query=gst+billing+software&by=page',
    );
  });
});

describe('SEO centre links', () => {
  it('overview tiles open the explorer; zero tiles do not', async () => {
    api.get.mockResolvedValue({ search_28d: { clicks: 12, impressions: 0 }, visibility_28d: { runs: 4 }, connections: { search_console: true } });
    renderWithAuth(<SeoOverviewPage />);
    expect(await screen.findByRole('link', { name: /Clicks \(28d\): 12/ })).toHaveAttribute('href', '/seo-centre/search?by=query&sort=clicks');
    expect(screen.getByRole('link', { name: /AI visibility runs \(28d\): 4/ })).toHaveAttribute('href', '/intelligence/visibility');
    expect(screen.queryByRole('link', { name: /Impressions/ })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /All snapshots/ })).toHaveAttribute('href', '/seo-centre/backlinks');
  });

  it('tracked keyword and best page cells drill into Search Console', async () => {
    api.get.mockResolvedValue({ keywords: [{ id: 1, keyword: 'gst app', status: 'active', latest_snapshot: { best_page_url: 'https://aicountly.com/gst' } }] });
    renderWithAuth(<TrackedKeywordsPage />);
    expect(await screen.findByRole('link', { name: 'gst app' })).toHaveAttribute('href', '/seo-centre/search?query=gst+app&by=page');
    expect(screen.getByRole('link', { name: 'https://aicountly.com/gst' })).toHaveAttribute(
      'href', '/seo-centre/search?page=https%3A%2F%2Faicountly.com%2Fgst&by=query',
    );
  });

  it('suggestion pages open their queries', async () => {
    api.get.mockResolvedValue({ suggestions: [{ page_url: 'https://aicountly.com/x', impressions: 200, clicks: 1 }] });
    renderWithAuth(<SeoSuggestionsPage />);
    expect(await screen.findByRole('link', { name: 'https://aicountly.com/x' })).toHaveAttribute(
      'href', '/seo-centre/search?page=https%3A%2F%2Faicountly.com%2Fx&by=query',
    );
  });
});
