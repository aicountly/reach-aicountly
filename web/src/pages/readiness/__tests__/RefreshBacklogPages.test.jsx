import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { Route, Routes } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/readinessService.js', () => ({
  listRefreshRecommendations: vi.fn(),
  getRefreshRecommendation: vi.fn(),
  listRefreshWorkflows: vi.fn(),
  getMigrationStatus: vi.fn(),
}));

import * as readiness from '../../../services/readinessService.js';
import RefreshRecommendationsPage from '../RefreshRecommendationsPage';
import RefreshRecommendationDetailPage from '../RefreshRecommendationDetailPage';
import RefreshWorkflowsPage from '../RefreshWorkflowsPage';
import MigrationStatusPage from '../MigrationStatusPage';

const ctx = { auth: { user: { id: 1 }, permissions: ['*'] } };

const rec = {
  id: 5, content_identity_id: 33, status: 'recommended', risk_classification: 'high', confidence: 0.75,
  total_score: 0.078, workflow_id: null, content_title: 'GST guide', canonical_url: 'https://example.com/blogs/gst',
  content_type: 'blog', content_item_id: 12, created_at: '2026-10-01T00:00:00Z',
};

beforeEach(() => {
  vi.clearAllMocks();
  readiness.listRefreshRecommendations.mockResolvedValue({ items: [rec], total: 1 });
  readiness.listRefreshWorkflows.mockResolvedValue({ items: [], total: 0 });
});

describe('refresh recommendations list', () => {
  it('the backlog card link applies the backlog scope and shows it as a chip', async () => {
    renderWithAuth(<RefreshRecommendationsPage />, { ...ctx, route: '/readiness/recommendations?backlog=1&risk=high' });
    await waitFor(() => expect(readiness.listRefreshRecommendations).toHaveBeenCalledWith({ page: 1, limit: 25, backlog: '1', risk: 'high' }));
    expect(screen.getByRole('button', { name: 'Clear backlog filter' })).toBeInTheDocument();
    expect(screen.getByLabelText('Risk')).toHaveValue('high');
  });

  it('applies status and content identity, and the content links to its analytics and item', async () => {
    renderWithAuth(<RefreshRecommendationsPage />, { ...ctx, route: '/readiness/recommendations?status=triaged&content_identity_id=33' });
    await waitFor(() => expect(readiness.listRefreshRecommendations).toHaveBeenCalledWith({ page: 1, limit: 25, status: 'triaged', content_identity_id: '33' }));
    expect(await screen.findByRole('link', { name: 'GST guide' })).toHaveAttribute('href', '/intelligence/content/33');
    expect(screen.getByRole('link', { name: 'item #12' })).toHaveAttribute('href', '/content/12');
  });

  it('a row opens the recommendation detail', async () => {
    renderWithAuth(
      <Routes>
        <Route path="/readiness/recommendations" element={<RefreshRecommendationsPage />} />
        <Route path="/readiness/recommendations/:id" element={<p>detail page</p>} />
      </Routes>,
      { ...ctx, route: '/readiness/recommendations' },
    );
    fireEvent.click(await screen.findByRole('row', { name: 'Open recommendation 5' }));
    expect(await screen.findByText('detail page')).toBeInTheDocument();
  });
});

describe('refresh recommendation detail', () => {
  it('shows score components, evidence, the workflow brief and links each outcome window', async () => {
    readiness.getRefreshRecommendation.mockResolvedValue({
      ...rec,
      content: { content_identity_id: 33, title: 'GST guide', canonical_url: 'https://example.com/blogs/gst', content_item_id: 12, content_type: 'blog' },
      score_components: [{ id: 1, factor: 'declining_clicks', raw_value: 0.4, weight: 0.12, contribution: 0.048, evidence_source: 'google_search_console', evidence_period: '28d' }],
      evidence: { evidence_date: '2026-10-01', window_days: 28, completeness_score: 0.75, missing_domains: ['engagement'], evidence_packet: { search: {} } },
      workflows: [{
        id: 8, status: 'published', refresh_objective: 'Win back clicks',
        brief: { refresh_objective: 'Win back clicks', key_changes: ['Update pricing table'], target_sections: [], source_requirements: [] },
        version_links: [],
        publication_links: [{ id: 3, delivery_status: 'delivered', outcome_windows: [{ id: 21, content_identity_id: 33, measurement_status: 'pending', post_from: '2026-10-01', post_to: '2026-10-28' }] }],
      }],
      related: [{ id: 4, status: 'superseded', risk_classification: 'low' }],
    });
    renderWithAuth(
      <Routes><Route path="/readiness/recommendations/:id" element={<RefreshRecommendationDetailPage />} /></Routes>,
      { ...ctx, route: '/readiness/recommendations/5' },
    );
    expect(await screen.findByText('declining clicks')).toBeInTheDocument();
    expect(readiness.getRefreshRecommendation).toHaveBeenCalledWith('5');
    expect(screen.getByText('Update pricing table')).toBeInTheDocument();
    expect(screen.getByText('engagement')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Window #21' })).toHaveAttribute('href', '/readiness/outcomes?window_id=21&content_identity_id=33');
    expect(screen.getByRole('link', { name: '#4' })).toHaveAttribute('href', '/readiness/recommendations/4');
    expect(screen.getByRole('link', { name: 'All recommendations for this content' })).toHaveAttribute('href', '/readiness/recommendations?content_identity_id=33');
  });
});

describe('refresh workflows list', () => {
  it('the active workflows card link applies ?active=1; rows open their recommendation', async () => {
    readiness.listRefreshWorkflows.mockResolvedValue({ items: [{ id: 8, recommendation_id: 5, content_identity_id: 33, status: 'in_review', recommendation_status: 'accepted' }], total: 1 });
    renderWithAuth(
      <Routes>
        <Route path="/readiness/workflows" element={<RefreshWorkflowsPage />} />
        <Route path="/readiness/recommendations/:id" element={<p>recommendation page</p>} />
      </Routes>,
      { ...ctx, route: '/readiness/workflows?active=1' },
    );
    await waitFor(() => expect(readiness.listRefreshWorkflows).toHaveBeenCalledWith({ page: 1, limit: 25, active: '1' }));
    expect(screen.getByRole('button', { name: 'Clear Active only filter' })).toBeInTheDocument();
    fireEvent.click(await screen.findByRole('row', { name: 'Open workflow 8' }));
    expect(await screen.findByText('recommendation page')).toBeInTheDocument();
  });
});

describe('migration status', () => {
  it('lists migrations; a count filters to that state', async () => {
    readiness.getMigrationStatus.mockResolvedValue({
      summary: { total: 2, applied: 1, pending: 1, orphaned: 0, last_batch: 3, last_applied_at: '2026-10-20T00:00:00Z' },
      items: [
        { version: '2026-10-21-000001', name: 'AddThing', state: 'pending', batch: null, applied_at: null },
        { version: '2026-10-20-000001', name: 'CreateThing', state: 'applied', batch: 3, applied_at: '2026-10-20T00:00:00Z' },
      ],
    });
    renderWithAuth(<MigrationStatusPage />, { ...ctx, route: '/readiness/migrations' });
    expect(await screen.findByText('AddThing')).toBeInTheDocument();
    expect(readiness.getMigrationStatus).toHaveBeenCalledWith({});
    fireEvent.click(screen.getByRole('button', { name: /Pending: 1 — list them/ }));
    await waitFor(() => expect(readiness.getMigrationStatus).toHaveBeenLastCalledWith({ state: 'pending' }));
  });
});
