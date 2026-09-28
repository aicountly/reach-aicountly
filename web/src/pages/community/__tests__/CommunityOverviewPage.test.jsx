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
      questions_by_status: { intake: 5, draft_requested: 8 },
      answers_by_status: { draft_generated: 2, published: 10 },
      published_answers: 10,
      pending_approval: 3,
      open_moderation_flags: 1,
    });
    renderWithAuth(<CommunityOverviewPage />, ctx);
    // '10' may appear in multiple stat cards (e.g. published_answers and answers_by_status)
    await waitFor(() => expect(screen.getAllByText('10').length).toBeGreaterThanOrEqual(1));
    expect(screen.getByText('Published answers')).toBeInTheDocument();
    expect(screen.getAllByText('3').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Pending approval')).toBeInTheDocument();
  });

  // The tile read questions_by_status.new and linked ?status=new; there is no
  // such status, so it showed 0 and its link filtered to nothing.
  it('counts New questions from intake and links each tile to a real filter', async () => {
    api.get.mockResolvedValueOnce({
      questions_by_status: { intake: 7, draft_requested: 8 },
      answers_by_status: {},
      published_answers: 0,
      pending_approval: 4,
      open_moderation_flags: 0,
    });
    renderWithAuth(<CommunityOverviewPage />, ctx);

    const newTile = (await screen.findByText('New questions')).closest('.stat-card');
    expect(newTile).toHaveTextContent('7');
    const hrefs = screen.getAllByRole('link').map((a) => a.getAttribute('href'));
    expect(hrefs).toContain('/community/questions?status=intake');
    expect(hrefs).toContain('/community/answers?status=awaiting_approval');
    expect(hrefs).not.toContain('/community/questions?status=new');
    expect(hrefs).not.toContain('/community/answers?status=pending_approval');
  });

  it('shows error when API fails', async () => {
    api.get.mockRejectedValueOnce(new Error('Network error'));
    renderWithAuth(<CommunityOverviewPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Network error/i)).toBeInTheDocument());
  });

  it('shows empty status breakdowns when there is no data', async () => {
    api.get.mockResolvedValueOnce({});
    renderWithAuth(<CommunityOverviewPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No questions yet/i)).toBeInTheDocument());
    expect(screen.getByText(/No answers yet/i)).toBeInTheDocument();
    expect(screen.getByText('Questions by status')).toBeInTheDocument();
  });
});
