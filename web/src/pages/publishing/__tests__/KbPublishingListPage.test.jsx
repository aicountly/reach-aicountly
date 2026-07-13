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
    permissions: ['kb_publishing.view'],
  },
};

beforeEach(() => {
  api.get.mockReset();
});

describe('KbPublishingListPage', () => {
  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<KbPublishingListPage />, ctx);
    expect(screen.getByText(/Loading KB deployments/i)).toBeInTheDocument();
  });

  it('shows empty state when no deployments', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<KbPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No knowledge-base deployments/i)).toBeInTheDocument());
  });

  it('renders KB deployment with article type', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          {
            id: 3,
            content_item_id: 55,
            content_title: 'Bank Reconciliation Setup',
            status: 'verified',
            article_type: 'how_to',
            attempt_count: 1,
            updated_at: '2026-07-13T10:00:00Z',
          },
        ],
      },
    });
    renderWithAuth(<KbPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Bank Reconciliation Setup')).toBeInTheDocument());
    expect(screen.getByText('how_to')).toBeInTheDocument();
    expect(screen.getByText('verified')).toBeInTheDocument();
  });

  it('renders page header', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<KbPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Knowledge Base Publishing')).toBeInTheDocument());
  });

  it('shows error state when API fails', async () => {
    api.get.mockRejectedValueOnce(new Error('KB API error'));
    renderWithAuth(<KbPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/KB API error/i)).toBeInTheDocument());
  });
});
