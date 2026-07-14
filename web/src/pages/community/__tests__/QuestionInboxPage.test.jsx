import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import QuestionInboxPage from '../QuestionInboxPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['community.view'],
  },
};

beforeEach(() => { api.get.mockReset(); });

describe('QuestionInboxPage', () => {
  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<QuestionInboxPage />, ctx);
    expect(screen.getByText(/Loading/i)).toBeInTheDocument();
  });

  it('renders "No questions" when inbox is empty', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [], meta: { last_page: 1 } } });
    renderWithAuth(<QuestionInboxPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No questions/i)).toBeInTheDocument());
  });

  it('renders question rows when data is present', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [{
          id: 1,
          external_id: 'q-uuid-1',
          title: 'How to file GST?',
          status: 'new',
          risk_classification: 'low',
          triage_score: 45,
          source_received_at: '2026-07-10T10:00:00Z',
          space_slug: 'gst',
        }],
        meta: { last_page: 1 },
      },
    });
    renderWithAuth(<QuestionInboxPage />, ctx);
    await waitFor(() => expect(screen.getByText('How to file GST?')).toBeInTheDocument());
    expect(screen.getByText('new')).toBeInTheDocument();
    expect(screen.getByText('45')).toBeInTheDocument();
  });

  it('shows error on API failure', async () => {
    api.get.mockRejectedValueOnce(new Error('fetch failed'));
    renderWithAuth(<QuestionInboxPage />, ctx);
    await waitFor(() => expect(screen.getByText(/fetch failed/i)).toBeInTheDocument());
  });

  it('renders page heading', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [], meta: { last_page: 1 } } });
    renderWithAuth(<QuestionInboxPage />, ctx);
    await waitFor(() => expect(screen.getByText('Question Inbox')).toBeInTheDocument());
  });
});
