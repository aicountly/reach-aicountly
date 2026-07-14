import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import CommunityOverviewPage from '../CommunityOverviewPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['community.view', 'community_analytics.view'],
  },
};

beforeEach(() => { api.get.mockReset(); });

describe('CommunityOverviewPage', () => {
  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<CommunityOverviewPage />, ctx);
    expect(screen.getByText(/Loading overview/i)).toBeInTheDocument();
  });

  it('renders stat cards when data loads', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          questions_by_status: { new: 5 },
          answers_by_status: { draft: 2, published: 10 },
          published_answers: 10,
          pending_approval: 3,
          open_moderation_flags: 1,
        },
      },
    });
    renderWithAuth(<CommunityOverviewPage />, ctx);
    // '10' may appear in multiple stat cards (e.g. published_answers and answers_by_status)
    await waitFor(() => expect(screen.getAllByText('10').length).toBeGreaterThanOrEqual(1));
    expect(screen.getByText('Published answers')).toBeInTheDocument();
    expect(screen.getAllByText('3').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Pending approval')).toBeInTheDocument();
  });

  it('shows error when API fails', async () => {
    api.get.mockRejectedValueOnce(new Error('Network error'));
    renderWithAuth(<CommunityOverviewPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Network error/i)).toBeInTheDocument());
  });

  it('renders page heading', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } });
    renderWithAuth(<CommunityOverviewPage />, ctx);
    await waitFor(() => expect(screen.getByText('Community Control Centre')).toBeInTheDocument());
  });
});
