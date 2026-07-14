import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import OfficialAnswerListPage from '../OfficialAnswerListPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['community.view'],
  },
};

beforeEach(() => { api.get.mockReset(); });

describe('OfficialAnswerListPage', () => {
  it('renders "No answers" when list is empty', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [], meta: { last_page: 1 } } });
    renderWithAuth(<OfficialAnswerListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No answers/i)).toBeInTheDocument());
  });

  it('renders answer rows when data present', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [{
          id: 1,
          external_id: 'ans-uuid-abc12345',
          status: 'published',
          risk_classification: 'low',
          ai_assisted: true,
          human_reviewed: true,
          updated_at: '2026-07-11T10:00:00Z',
        }],
        meta: { last_page: 1 },
      },
    });
    renderWithAuth(<OfficialAnswerListPage />, ctx);
    await waitFor(() => expect(screen.getByText('published')).toBeInTheDocument());
    expect(screen.getAllByText('Yes').length).toBeGreaterThanOrEqual(1);
  });

  it('renders page heading', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [], meta: { last_page: 1 } } });
    renderWithAuth(<OfficialAnswerListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Official Answers')).toBeInTheDocument());
  });

  it('shows error when API fails', async () => {
    api.get.mockRejectedValueOnce(new Error('server error'));
    renderWithAuth(<OfficialAnswerListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/server error/i)).toBeInTheDocument());
  });
});
