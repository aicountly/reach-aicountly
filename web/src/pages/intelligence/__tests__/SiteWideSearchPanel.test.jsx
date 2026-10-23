import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';

const daily = vi.fn();
const explore = vi.fn();
vi.mock('../../../services/intelligenceService.js', () => ({
  getSiteSearchDaily: (...a) => daily(...a),
  exploreSiteSearch: (...a) => explore(...a),
  listSiteSearchQueries: vi.fn(async () => ({ rows: [{ dimension_value: 'aicountly', clicks: 40, impressions: 100, ctr: 0.4, avg_position: 1.2 }] })),
  listSiteSearchPages: vi.fn(async () => ({ rows: [{ dimension_value: 'https://www.aicountly.com/pricing', clicks: 5, impressions: 50, ctr: 0.1, avg_position: 3 }] })),
  getSiteSearchPositions: vi.fn(async () => ({ buckets: [{ bucket: '1-3', queries: 1, clicks: 40, impressions: 100 }, { bucket: '4-10', queries: 1, clicks: 5, impressions: 50 }] })),
}));

import SiteWideSearchPanel from '../SiteWideSearchPanel';

function Where() {
  const loc = useLocation();
  return <output data-testid="where">{loc.search}</output>;
}

function renderAt(route, props = { configured: true }) {
  return render(
    <MemoryRouter initialEntries={[route]}>
      <Routes>
        <Route path="*" element={<><SiteWideSearchPanel {...props} /><Where /></>} />
      </Routes>
    </MemoryRouter>,
  );
}

const DAY = {
  available: true, latest_date: '2026-10-14', period: { from: '2026-09-17', to: '2026-10-14' },
  totals: { clicks: 45, impressions: 150, ctr: 0.3, avg_position: 1.9, days: 1 },
  days: [{ date: '2026-10-14', clicks: 45, impressions: 150, ctr: 0.3, avg_position: 1.9 }],
};

const slice = (rows, extra = {}) => ({
  available: true, latest_date: '2026-10-14', period: { from: '2026-09-17', to: '2026-10-14' },
  rows, total_rows: rows.length,
  totals: { clicks: rows.reduce((s, r) => s + r.clicks, 0), impressions: 100, ctr: 0.1, avg_position: 4, rows: rows.length },
  ...extra,
});

describe('SiteWideSearchPanel', () => {
  beforeEach(() => { daily.mockReset(); explore.mockReset(); });

  it('explains an empty store instead of showing zeros', async () => {
    daily.mockResolvedValue({ available: false, message: 'No site-wide Search Console rows have been ingested yet.', days: [] });
    renderAt('/', { configured: false });

    expect(await screen.findByTestId('site-wide-empty')).toHaveTextContent('SEARCH_CONSOLE_SETUP.md');
  });

  it('renders totals, days, buckets, top queries and pages', async () => {
    daily.mockResolvedValue(DAY);
    renderAt('/');

    expect(await screen.findByTestId('site-wide-panel')).toBeInTheDocument();
    expect(screen.getAllByText('45').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('aicountly')).toBeInTheDocument();
    expect(screen.getByText('aicountly.com/pricing')).toBeInTheDocument();
    expect(screen.getByText('Position 1-3')).toBeInTheDocument();
    expect(screen.getAllByText('50% of queries · 40 clicks').length).toBe(1);
  });

  it('drills from a day to its queries, then to the pages for one query', async () => {
    daily.mockResolvedValue(DAY);
    explore.mockImplementation(async (p) => (p.group_by === 'query'
      ? slice([{ dimension_value: 'gst billing software', clicks: 4, impressions: 80, ctr: 0.05, avg_position: 6, days: 1 }])
      : slice([{ dimension_value: 'https://www.aicountly.com/billing', clicks: 4, impressions: 80, ctr: 0.05, avg_position: 6, days: 1 }])));
    const user = userEvent.setup();
    renderAt('/');

    await user.click(await screen.findByRole('button', { name: '2026-10-14' }));
    expect(await screen.findByTestId('search-explorer')).toBeInTheDocument();
    expect(explore).toHaveBeenLastCalledWith(expect.objectContaining({ group_by: 'query', date: '2026-10-14' }));
    const path = screen.getByRole('navigation', { name: 'Drill-down path' });
    expect(within(path).getByText('Day: 2026-10-14')).toBeInTheDocument();

    await user.click(await screen.findByRole('button', { name: 'gst billing software' }));
    await waitFor(() => expect(explore).toHaveBeenLastCalledWith(expect.objectContaining({
      group_by: 'page', date: '2026-10-14', query: 'gst billing software',
    })));
    expect(await screen.findByText('aicountly.com/billing')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /search google/i })).toHaveAttribute('href', expect.stringContaining('gst%20billing%20software'));

    // Back up one level from the trail.
    await user.click(within(screen.getByRole('navigation', { name: 'Drill-down path' })).getByRole('button', { name: 'Day: 2026-10-14' }));
    await waitFor(() => expect(screen.getByTestId('where').textContent).not.toContain('query='));
  });

  it('opens the queries in a position band from its tile', async () => {
    daily.mockResolvedValue(DAY);
    explore.mockResolvedValue(slice([{ dimension_value: 'aicountly', clicks: 40, impressions: 100, ctr: 0.4, avg_position: 1.2, days: 1 }]));
    const user = userEvent.setup();
    renderAt('/');

    await user.click(await screen.findByRole('button', { name: /position 1-3: 1 queries/i }));
    await waitFor(() => expect(explore).toHaveBeenCalledWith(expect.objectContaining({ bucket: '1-3', group_by: 'query' })));
  });

  it('starts drilled when the URL carries the filters, and shows the single row at the bottom', async () => {
    explore.mockResolvedValue(slice([]));
    renderAt('/?date=2026-10-14&query=aicountly&page=https%3A%2F%2Fwww.aicountly.com%2F&device=MOBILE&country=IND');

    expect(await screen.findByTestId('search-leaf')).toBeInTheDocument();
    expect(daily).not.toHaveBeenCalled();
    expect(screen.getByText('Mobile')).toBeInTheDocument();
  });
});
