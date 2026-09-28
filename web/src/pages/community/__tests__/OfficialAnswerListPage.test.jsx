import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), getPage: vi.fn() },
}));
import api from '../../../services/api';
import OfficialAnswerListPage from '../OfficialAnswerListPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['community.view'],
  },
};

// What api.getPage() resolves to: the endpoint's own { data, meta } body.
const page = (rows, meta = {}) => ({
  data: rows,
  meta: { current_page: 1, per_page: 25, total: rows.length, last_page: rows.length ? 1 : 0, ...meta },
});

const ANSWER = {
  id: '1',
  uuid: 'c0a8b4de-7f3e-4b8a-9c1d-2e5f6a7b8c9d',
  status: 'published',
  risk_classification: 'low',
  ai_assisted: true,
  human_reviewed: true,
  updated_at: '2026-07-11 10:00:00+00',
};

beforeEach(() => { api.getPage.mockReset(); });

describe('OfficialAnswerListPage', () => {
  it('renders "No answers" when list is empty', async () => {
    api.getPage.mockResolvedValueOnce(page([]));
    renderWithAuth(<OfficialAnswerListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No answers/i)).toBeInTheDocument());
  });

  it('renders answer rows and links them by uuid', async () => {
    api.getPage.mockResolvedValueOnce(page([ANSWER]));
    renderWithAuth(<OfficialAnswerListPage />, ctx);
    await waitFor(() => expect(screen.getByText('published')).toBeInTheDocument());
    expect(screen.getAllByText('Yes').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByRole('link', { name: 'Edit' })).toHaveAttribute('href', `/community/answers/${ANSWER.uuid}`);
  });

  it('starts on the filter the Pending approval tile links to', async () => {
    api.getPage.mockResolvedValueOnce(page([]));
    renderWithAuth(<OfficialAnswerListPage />, { ...ctx, route: '/community/answers?status=awaiting_approval' });

    await waitFor(() => expect(api.getPage).toHaveBeenCalledWith(
      'v1/community/answers',
      { status: 'awaiting_approval', page: 1 },
    ));
    expect(screen.getByDisplayValue('Awaiting approval')).toBeInTheDocument();
  });

  it('shows the pager when the API reports more than one page', async () => {
    api.getPage.mockResolvedValueOnce(page([ANSWER], { total: 30, last_page: 2 }));
    renderWithAuth(<OfficialAnswerListPage />, ctx);
    expect(await screen.findByText('Page 1 / 2')).toBeInTheDocument();
  });

  it('renders page heading', async () => {
    api.getPage.mockResolvedValueOnce(page([]));
    renderWithAuth(<OfficialAnswerListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Official Answers')).toBeInTheDocument());
  });

  it('shows error when API fails', async () => {
    api.getPage.mockRejectedValueOnce(new Error('server error'));
    renderWithAuth(<OfficialAnswerListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/server error/i)).toBeInTheDocument());
  });
});
