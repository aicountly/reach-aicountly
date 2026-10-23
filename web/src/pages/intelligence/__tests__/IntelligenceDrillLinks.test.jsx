import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/intelligenceService.js', () => ({
  getAttributionOverview: vi.fn(),
  listCompetitors: vi.fn(),
  listConnectors: vi.fn(),
  listVisibilityRuns: vi.fn(),
  listSearchMetrics: vi.fn(),
  listContentMetrics: vi.fn(),
}));
vi.mock('../../../services/growthIntelService.js', () => ({
  getVisibilitySummary: vi.fn(),
  listVisibilityAnswers: vi.fn(),
  runVisibilityBatch: vi.fn(),
}));

import * as intel from '../../../services/intelligenceService.js';
import * as growth from '../../../services/growthIntelService.js';
import IntelligenceOverviewPage from '../IntelligenceOverviewPage';
import AttributionOverviewPage from '../AttributionOverviewPage';
import IntelligenceOperationsPage from '../IntelligenceOperationsPage';
import ContentPerformancePage from '../ContentPerformancePage';
import SearchQueryPerformancePage from '../SearchQueryPerformancePage';
import SearchPagePerformancePage from '../SearchPagePerformancePage';
import VisibilityOverviewPage from '../VisibilityOverviewPage';

const connectors = [
  { id: 1, name: 'GSC', health_status: 'healthy' },
  { id: 2, name: 'GA4', health_status: 'failed' },
];

beforeEach(() => {
  vi.clearAllMocks();
  intel.getAttributionOverview.mockResolvedValue({ stats: { total_conversions: 5, attributed: 3, unattributed: 2, attribution_rate: 60 } });
  intel.listVisibilityRuns.mockResolvedValue([]);
  intel.listCompetitors.mockResolvedValue([{ id: 1 }]);
  intel.listConnectors.mockResolvedValue(connectors);
  intel.listSearchMetrics.mockResolvedValue({ rows: [] });
  intel.listContentMetrics.mockResolvedValue([]);
  growth.getVisibilitySummary.mockResolvedValue({ brand: 'AICOUNTLY', by_provider: [{ provider: 'gemini', answers: 3, entities: {} }], gaps: [] });
  growth.listVisibilityAnswers.mockResolvedValue([]);
});

describe('intelligence tiles', () => {
  it('overview tiles link to their pages; a zero tile does not', async () => {
    renderWithAuth(<IntelligenceOverviewPage />);
    expect(await screen.findByRole('link', { name: /Attributed Conversions: 3/ })).toHaveAttribute('href', '/intelligence/attribution');
    expect(screen.getByRole('link', { name: /Competitors Tracked: 1/ })).toHaveAttribute('href', '/intelligence/competitors');
    expect(screen.getByRole('link', { name: /Connectors: 2/ })).toHaveAttribute('href', '/intelligence/connectors');
    expect(screen.queryByRole('link', { name: /AI Visibility Runs/ })).not.toBeInTheDocument();
  });

  it('attribution "Unattributed" opens the unattributed list', async () => {
    renderWithAuth(<AttributionOverviewPage />);
    expect(await screen.findByRole('link', { name: /Unattributed: 2/ })).toHaveAttribute('href', '/intelligence/attribution/unattributed');
  });

  it('operations tiles narrow the connector table via ?health=', async () => {
    renderWithAuth(<IntelligenceOperationsPage />, { route: '/intelligence/operations?health=unhealthy' });
    expect(await screen.findByText('GA4')).toBeInTheDocument();
    expect(screen.queryByText('GSC')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /clear unhealthy filter/i }));
    expect(await screen.findByText('GSC')).toBeInTheDocument();
  });
});

describe('intelligence rows', () => {
  it('query rows open the pages ranking for that query', async () => {
    intel.listSearchMetrics.mockResolvedValue({ rows: [{ query: 'gst billing', clicks: 3, impressions: 9 }] });
    renderWithAuth(<SearchQueryPerformancePage />);
    expect(await screen.findByRole('link', { name: 'gst billing' })).toHaveAttribute('href', '/seo-centre/search?query=gst+billing&by=page');
  });

  it('page rows open the queries for that page', async () => {
    intel.listSearchMetrics.mockResolvedValue({ rows: [{ page_url: 'https://aicountly.com/blog/gst', clicks: 3, impressions: 9 }] });
    renderWithAuth(<SearchPagePerformancePage />);
    expect(await screen.findByRole('link', { name: 'https://aicountly.com/blog/gst' })).toHaveAttribute(
      'href', '/seo-centre/search?page=https%3A%2F%2Faicountly.com%2Fblog%2Fgst&by=query',
    );
  });

  it('content rows open the content analytics detail', async () => {
    intel.listContentMetrics.mockResolvedValue([{ id: 1, content_identity_id: 77, title: 'GST guide', sessions: 4 }]);
    renderWithAuth(
      <Routes>
        <Route path="/intelligence/content" element={<ContentPerformancePage />} />
        <Route path="/intelligence/content/:id" element={<p>content detail</p>} />
      </Routes>,
      { route: '/intelligence/content' },
    );
    fireEvent.click(await screen.findByRole('row', { name: /Open analytics for GST guide/ }));
    expect(await screen.findByText('content detail')).toBeInTheDocument();
  });

  it('visibility narrows latest answers from the URL and from an assistant row', async () => {
    renderWithAuth(<VisibilityOverviewPage />, { route: '/intelligence/visibility?prompt_id=4' });
    await waitFor(() => expect(growth.listVisibilityAnswers).toHaveBeenCalledWith({ limit: 30, prompt_id: '4' }));
    fireEvent.click(await screen.findByRole('button', { name: '3' }));
    await waitFor(() => expect(growth.listVisibilityAnswers).toHaveBeenCalledWith({ limit: 30, prompt_id: '4', provider: 'gemini' }));
  });
});
