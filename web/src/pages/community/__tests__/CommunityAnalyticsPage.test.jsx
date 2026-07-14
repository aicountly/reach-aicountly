import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import CommunityAnalyticsPage from '../CommunityAnalyticsPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['community_analytics.view'],
  },
};

const mockData = {
  overview: {
    data: {
      data: {
        published_answers: 42,
        pending_approval: 5,
        open_moderation_flags: 2,
        questions_by_status: { new: 10 },
        answers_by_status: { published: 42, draft: 3 },
      },
    },
  },
  engagement: { data: { data: [], days: 30 } },
  coverage: { data: { data: [] } },
};

beforeEach(() => { api.get.mockReset(); });

describe('CommunityAnalyticsPage', () => {
  it('renders stat cards with published answers count', async () => {
    api.get
      .mockResolvedValueOnce(mockData.overview)
      .mockResolvedValueOnce(mockData.engagement)
      .mockResolvedValueOnce(mockData.coverage);
    renderWithAuth(<CommunityAnalyticsPage />, ctx);
    await waitFor(() => expect(screen.getByText('42')).toBeInTheDocument());
    expect(screen.getByText('Published answers')).toBeInTheDocument();
  });

  it('shows "No validated engagement" when empty', async () => {
    api.get
      .mockResolvedValueOnce(mockData.overview)
      .mockResolvedValueOnce(mockData.engagement)
      .mockResolvedValueOnce(mockData.coverage);
    renderWithAuth(<CommunityAnalyticsPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No validated engagement/i)).toBeInTheDocument());
  });

  it('shows "No coverage data" when empty', async () => {
    api.get
      .mockResolvedValueOnce(mockData.overview)
      .mockResolvedValueOnce(mockData.engagement)
      .mockResolvedValueOnce(mockData.coverage);
    renderWithAuth(<CommunityAnalyticsPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No coverage data/i)).toBeInTheDocument());
  });

  it('renders page heading', async () => {
    api.get
      .mockResolvedValueOnce(mockData.overview)
      .mockResolvedValueOnce(mockData.engagement)
      .mockResolvedValueOnce(mockData.coverage);
    renderWithAuth(<CommunityAnalyticsPage />, ctx);
    await waitFor(() => expect(screen.getByText('Community Analytics')).toBeInTheDocument());
  });
});
