import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import KbPublishingListPage from '../KbPublishingListPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view'],
  },
};

beforeEach(() => {
  api.get.mockReset();
});

const makeKbRow = (type) => ({
  id: Math.floor(Math.random() * 1000),
  content_item_id: 1,
  content_title: `${type} Article`,
  article_type: type,
  status: 'published',
  canonical_url: null,
  attempt_count: 1,
  updated_at: null,
});

describe('KbPublishingListPage article types', () => {
  it.each([
    ['how_to'],
    ['reference'],
    ['troubleshooting'],
    ['concept'],
    ['tutorial'],
  ])('displays article type %s', async (type) => {
    api.get.mockResolvedValueOnce({ data: { data: [makeKbRow(type)] } });
    const { unmount } = renderWithAuth(<KbPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText(type)).toBeInTheDocument());
    unmount();
  });

  it('shows KB page header', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<KbPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Knowledge Base Publishing/i)).toBeInTheDocument());
  });

  it('shows multiple KB items', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          makeKbRow('how_to'),
          makeKbRow('concept'),
          makeKbRow('reference'),
        ],
      },
    });
    renderWithAuth(<KbPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getAllByText('published').length).toBeGreaterThanOrEqual(1));
  });
});
