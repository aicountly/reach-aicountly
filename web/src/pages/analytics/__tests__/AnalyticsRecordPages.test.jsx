import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/analyticsService', () => ({
  analyticsService: { snapshots: vi.fn(), legacyBlogPosts: vi.fn() },
}));
vi.mock('../../../services/blogService', () => ({
  blogService: { approve: vi.fn(), reject: vi.fn() },
}));

import { analyticsService } from '../../../services/analyticsService';
import { blogService } from '../../../services/blogService';
import { AnalyticsSnapshotsPage } from '../AnalyticsSnapshotsPage';
import { LegacyBlogPostsPage } from '../LegacyBlogPostsPage';

beforeEach(() => {
  vi.clearAllMocks();
});

describe('AnalyticsSnapshotsPage', () => {
  it('applies the tile filters and opens a snapshot to its payload', async () => {
    analyticsService.snapshots.mockResolvedValue({
      items: [{ id: 7, source: 'ga4', captured_at: '2026-10-20T10:00:00Z', created_at: '2026-10-20T10:00:00Z', metrics: { sessions: 120, users: 80 } }],
      total: 1, page: 1, limit: 25, by_source: { ga4: 1, internal: 2 },
    });
    renderWithAuth(<AnalyticsSnapshotsPage />, { route: '/analytics/snapshots?source=external&days=30' });

    const row = await screen.findByRole('row', { name: /snapshot 7 from ga4/i });
    expect(analyticsService.snapshots).toHaveBeenCalledWith(expect.objectContaining({ source: 'external', days: '30', page: 1 }));
    expect(screen.getByRole('navigation', { name: /drill-down path/i })).toHaveTextContent('Source: All imported (not internal)');
    expect(row).toHaveTextContent('sessions: 120 · users: 80');

    fireEvent.click(row);
    expect(screen.getByTestId('snapshot-7')).toHaveTextContent('"users": 80');

    fireEvent.click(screen.getByRole('button', { name: /internal · 2/i }));
    await waitFor(() => expect(analyticsService.snapshots).toHaveBeenLastCalledWith(expect.objectContaining({ source: 'internal' })));
  });

  it('renders an honest empty state', async () => {
    analyticsService.snapshots.mockResolvedValue({ items: [], total: 0, page: 1, limit: 25, by_source: {} });
    renderWithAuth(<AnalyticsSnapshotsPage />, { route: '/analytics/snapshots' });

    expect(await screen.findByText('No engagement snapshots have been imported yet.')).toBeInTheDocument();
  });
});

describe('LegacyBlogPostsPage', () => {
  const post = {
    id: 3, title: 'Bot draft', slug: 'bot-draft', status: 'draft', approval_status: 'pending', bot_generated: true,
    content_item_id: null, content_preview: 'Body text', content_length: 9, updated_at: '2026-10-20T10:00:00Z',
  };

  it('lists the bucket a tile linked to and acts on a pending post', async () => {
    analyticsService.legacyBlogPosts.mockResolvedValue({
      items: [post], total: 1, page: 1, limit: 25, counts: { drafts: 4, published: 1, approval_pending: 1 }, days: 30,
    });
    blogService.approve.mockResolvedValue({});
    renderWithAuth(<LegacyBlogPostsPage />, {
      route: '/analytics/blog-posts?bucket=approval_pending',
      auth: { user: { id: 1 }, permissions: ['blog.approve'] },
    });

    const row = await screen.findByRole('row', { name: /legacy blog post bot draft/i });
    expect(analyticsService.legacyBlogPosts).toHaveBeenCalledWith(expect.objectContaining({ bucket: 'approval_pending', page: 1 }));
    expect(screen.getByRole('navigation', { name: /drill-down path/i })).toHaveTextContent('Tile: Approvals pending');
    expect(row).toHaveTextContent('Not migrated');

    fireEvent.click(row);
    expect(screen.getByTestId('legacy-post-3')).toHaveTextContent('Body text');
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(blogService.approve).toHaveBeenCalledWith(3));
    await waitFor(() => expect(analyticsService.legacyBlogPosts).toHaveBeenCalledTimes(2));
  });

  it('links a migrated post to Content Studio and hides actions without permission', async () => {
    analyticsService.legacyBlogPosts.mockResolvedValue({
      items: [{ ...post, content_item_id: 55 }], total: 1, page: 1, limit: 25, counts: {}, days: 30,
    });
    renderWithAuth(<LegacyBlogPostsPage />, { route: '/analytics/blog-posts?bucket=drafts' });

    expect(await screen.findByRole('link', { name: 'Open ›' })).toHaveAttribute('href', '/content/55');
    fireEvent.click(screen.getByRole('row', { name: /legacy blog post bot draft/i }));
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
  });
});
