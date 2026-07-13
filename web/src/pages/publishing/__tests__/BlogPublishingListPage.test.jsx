import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import BlogPublishingListPage from '../BlogPublishingListPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view'],
  },
};

beforeEach(() => {
  api.get.mockReset();
});

describe('BlogPublishingListPage', () => {
  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<BlogPublishingListPage />, ctx);
    expect(screen.getByText(/Loading blog deployments/i)).toBeInTheDocument();
  });

  it('shows empty state when no deployments', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<BlogPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No blog deployments/i)).toBeInTheDocument());
  });

  it('renders deployment rows when data available', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          {
            id: 1,
            content_item_id: 42,
            content_title: 'GST Filing Guide',
            status: 'published',
            canonical_url: 'https://aicountly.com/blog/gst-filing',
            attempt_count: 1,
            updated_at: '2026-07-13T10:00:00Z',
          },
        ],
      },
    });
    renderWithAuth(<BlogPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText('GST Filing Guide')).toBeInTheDocument());
    expect(screen.getByText('published')).toBeInTheDocument();
  });

  it('shows error message when API fails', async () => {
    api.get.mockRejectedValueOnce(new Error('API error'));
    renderWithAuth(<BlogPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/API error/i)).toBeInTheDocument());
  });

  it('renders page header', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<BlogPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Blog Publishing')).toBeInTheDocument());
  });
});
