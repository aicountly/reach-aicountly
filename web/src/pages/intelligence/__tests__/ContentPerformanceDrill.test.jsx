import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import { Route, Routes, useLocation } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/intelligenceService.js', () => ({
  listContentMetrics: vi.fn(),
}));
vi.mock('../../../services/api.js', () => ({
  default: { get: vi.fn() },
}));
import * as intel from '../../../services/intelligenceService.js';
import api from '../../../services/api.js';
import ContentPerformancePage from '../ContentPerformancePage';
import ContentDetailAnalyticsPage from '../ContentDetailAnalyticsPage';

function Where() {
  const l = useLocation();
  return <p>at {l.pathname}{l.search}</p>;
}

beforeEach(() => vi.clearAllMocks());

/**
 * Field names end-to-end with ContentAnalyticsController::metrics /
 * ::contentMetrics (ContentSearchPerformanceService): rows[].content_identity_id,
 * clicks, impressions, ctr, avg_position; daily[].date, page_url, rows.
 */
describe('content performance drill-down', () => {
  it('lists search performance per content; a row opens its daily rows', async () => {
    intel.listContentMetrics.mockResolvedValue({
      available: true, period: { from: '2026-09-24', to: '2026-10-21' },
      rows: [{ content_identity_id: 77, title: 'GST guide', url: 'https://example.com/blogs/gst', page_urls: ['https://example.com/blogs/gst'], content_item_id: 12, clicks: 1234, impressions: 20000, ctr: 0.0617, avg_position: 4.21, days: 28 }],
      unmapped: { pages: 3, clicks: 50, impressions: 900 },
    });
    renderWithAuth(
      <Routes>
        <Route path="/intelligence/content" element={<ContentPerformancePage />} />
        <Route path="/intelligence/content/:id" element={<Where />} />
      </Routes>,
      { route: '/intelligence/content' },
    );
    expect(await screen.findByText('1,234')).toBeInTheDocument();
    expect(screen.getByText('6.2%')).toBeInTheDocument();
    expect(screen.getByText('4.2')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Content item #12' })).toHaveAttribute('href', '/content/12');
    expect(screen.getByRole('link', { name: 'site-wide Search explorer' })).toHaveAttribute('href', '/seo-centre/search?by=page');
    fireEvent.click(screen.getByRole('row', { name: 'Open analytics for GST guide' }));
    expect(await screen.findByText('at /intelligence/content/77')).toBeInTheDocument();
  });

  it('honest empty state when nothing is ingested', async () => {
    intel.listContentMetrics.mockResolvedValue({ available: false, rows: [] });
    renderWithAuth(<ContentPerformancePage />);
    expect(await screen.findByText(/No Search Console rows have been ingested yet/)).toBeInTheDocument();
  });

  it('a daily row opens the Search explorer at that page and day', async () => {
    api.get.mockResolvedValue({
      period: { from: '2026-09-24', to: '2026-10-21' }, latest_date: '2026-10-21',
      content: { content_identity_id: 77, title: 'GST guide', canonical_url: 'https://example.com/blogs/gst', content_item_id: 12 },
      summary: { clicks: 15, impressions: 140, ctr: 0.107, avg_position: 3.2 },
      daily: [{ date: '2026-10-21', page_url: 'https://example.com/blogs/gst', clicks: 5, impressions: 40, ctr: 0.125, avg_position: 4, rows: 2 }],
    });
    renderWithAuth(
      <Routes>
        <Route path="/intelligence/content/:id" element={<ContentDetailAnalyticsPage />} />
        <Route path="/seo-centre/search" element={<Where />} />
      </Routes>,
      { route: '/intelligence/content/77' },
    );
    expect(api.get).toHaveBeenCalledWith('v1/intelligence/content/metrics/77');
    expect(await screen.findByRole('heading', { name: 'GST guide' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Refresh recommendations' })).toHaveAttribute('href', '/readiness/recommendations?content_identity_id=77');
    fireEvent.click(screen.getByRole('row', { name: /Queries for example.com\/blogs\/gst on/ }));
    expect(await screen.findByText(
      `at /seo-centre/search?page=${encodeURIComponent('https://example.com/blogs/gst')}&date=2026-10-21&by=query`,
    )).toBeInTheDocument();
  });
});
