import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../test/renderWithAuth';

vi.mock('../../services/analyticsService', () => ({
  analyticsService: { summary: vi.fn() },
}));
// The GA4 and Engage sections have their own tests; keep this one about the internal tiles.
vi.mock('../../components/analytics/TrafficAnalyticsSection', () => ({ TrafficAnalyticsSection: () => null }));
vi.mock('../../components/outcomes/OutcomesAnalyticsSection', () => ({ OutcomesAnalyticsSection: () => null }));

import { analyticsService } from '../../services/analyticsService';
import { AnalyticsPage } from '../AnalyticsPage';

describe('AnalyticsPage tiles', () => {
  it('link to the records they counted, and zeros stay plain', async () => {
    analyticsService.summary.mockResolvedValue({
      window: '30d', campaigns_created: 3, posts_planned: 5, posts_published: 0, leads_generated: 12,
      blog_drafts: 2, blog_published: 1, approval_pending: 4, engagement_imported: 9,
    });
    renderWithAuth(<AnalyticsPage />, { route: '/analytics' });

    const tile = async (label) => (await screen.findByText(label)).closest('.stat-tile');
    expect(await tile('Campaigns created (30d)')).toHaveAttribute('href', '/campaigns?days=30');
    expect(await tile('Leads generated (30d)')).toHaveAttribute('href', '/leads?days=30');
    expect(await tile('Posts planned (30d)')).toHaveAttribute('href', '/social/calendar');
    expect((await tile('Posts published (30d)')).tagName).toBe('DIV');
    // Legacy blog tiles open the legacy-table list with the same predicate the count used.
    expect(await tile('Blog drafts')).toHaveAttribute('href', '/analytics/blog-posts?bucket=drafts');
    expect(await tile('Blog published (30d)')).toHaveAttribute('href', '/analytics/blog-posts?bucket=published&days=30');
    expect(await tile('Approvals pending')).toHaveAttribute('href', '/analytics/blog-posts?bucket=approval_pending');
    expect(await tile('Engagement imported')).toHaveAttribute('href', '/analytics/snapshots?source=external&days=30');
  });

  it('keeps zero legacy and engagement tiles plain', async () => {
    analyticsService.summary.mockResolvedValue({
      window: '30d', blog_drafts: 0, blog_published: 0, approval_pending: 0, engagement_imported: 0,
    });
    renderWithAuth(<AnalyticsPage />, { route: '/analytics' });

    for (const label of ['Blog drafts', 'Blog published (30d)', 'Approvals pending', 'Engagement imported']) {
      expect((await screen.findByText(label)).closest('.stat-tile').tagName).toBe('DIV');
    }
  });
});
