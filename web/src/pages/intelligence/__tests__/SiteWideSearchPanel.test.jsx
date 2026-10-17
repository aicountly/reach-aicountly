import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';

const daily = vi.fn();
vi.mock('../../../services/intelligenceService.js', () => ({
  getSiteSearchDaily: (...a) => daily(...a),
  listSiteSearchQueries: vi.fn(async () => ({ rows: [{ dimension_value: 'aicountly', clicks: 40, impressions: 100, ctr: 0.4, avg_position: 1.2 }] })),
  listSiteSearchPages: vi.fn(async () => ({ rows: [{ dimension_value: 'https://www.aicountly.com/pricing', clicks: 5, impressions: 50, ctr: 0.1, avg_position: 3 }] })),
  getSiteSearchPositions: vi.fn(async () => ({ buckets: [{ bucket: '1-3', queries: 1, clicks: 40, impressions: 100 }, { bucket: '4-10', queries: 1, clicks: 5, impressions: 50 }] })),
}));

import SiteWideSearchPanel from '../SiteWideSearchPanel';

describe('SiteWideSearchPanel', () => {
  beforeEach(() => daily.mockReset());

  it('explains an empty store instead of showing zeros', async () => {
    daily.mockResolvedValue({ available: false, message: 'No site-wide Search Console rows have been ingested yet.', days: [] });
    render(<SiteWideSearchPanel configured={false} />);

    expect(await screen.findByTestId('site-wide-empty')).toHaveTextContent('SEARCH_CONSOLE_SETUP.md');
  });

  it('renders totals, days, buckets, top queries and pages', async () => {
    daily.mockResolvedValue({
      available: true, latest_date: '2026-10-14', period: { from: '2026-09-17', to: '2026-10-14' },
      totals: { clicks: 45, impressions: 150, ctr: 0.3, avg_position: 1.9, days: 1 },
      days: [{ date: '2026-10-14', clicks: 45, impressions: 150, ctr: 0.3, avg_position: 1.9 }],
    });
    render(<SiteWideSearchPanel configured />);

    expect(await screen.findByTestId('site-wide-panel')).toBeInTheDocument();
    expect(screen.getAllByText('45').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('aicountly')).toBeInTheDocument();
    expect(screen.getByText('aicountly.com/pricing')).toBeInTheDocument();
    expect(screen.getByText('Position 1-3')).toBeInTheDocument();
    expect(screen.getAllByText('50% of queries · 40 clicks').length).toBe(1);
  });
});
