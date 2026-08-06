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
          uuid: 'q-uuid-1',
          title: 'How to file GST?',
          status: 'draft_generated',
          risk_classification: 'low',
          triage_score: 45,
          intake_timestamp: '2026-07-10T10:00:00Z',
          space_slug: 'gst',
        }],
        meta: { last_page: 1 },
      },
    });
    renderWithAuth(<QuestionInboxPage />, ctx);
    await waitFor(() => expect(screen.getByText('How to file GST?')).toBeInTheDocument());
    expect(screen.getAllByText('draft_generated').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('45').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByRole('link', { name: 'Open' })).toHaveAttribute(
      'href',
      '/community/questions/q-uuid-1',
    );
  });

  it('links a published question to its public page on aicountly.com', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [{
          id: 2,
          uuid: 'q-uuid-2',
          title: 'How is interest calculated when TDS is deposited late?',
          status: 'published',
          triage_score: 10,
          public_url: 'https://aicountly.com/community/question/tds-late-interest',
        }],
        meta: { last_page: 1 },
      },
    });
    renderWithAuth(<QuestionInboxPage />, ctx);
    await waitFor(() => expect(screen.getByText('View on aicountly.com')).toBeInTheDocument());
    expect(screen.getByText('View on aicountly.com')).toHaveAttribute(
      'href',
      'https://aicountly.com/community/question/tds-late-interest',
    );
  });

  it('offers the real lifecycle statuses as filters', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [], meta: { last_page: 1 } } });
    renderWithAuth(<QuestionInboxPage />, ctx);
    await waitFor(() => expect(screen.getByText('Draft requested')).toBeInTheDocument());
    expect(screen.getByText('Published')).toBeInTheDocument();
    expect(screen.queryByText('In progress')).not.toBeInTheDocument();
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
