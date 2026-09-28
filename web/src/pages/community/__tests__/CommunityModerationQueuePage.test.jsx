import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), getPage: vi.fn(), post: vi.fn() },
}));
import api from '../../../services/api';
import CommunityModerationQueuePage from '../CommunityModerationQueuePage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['community_question.moderate'],
  },
};

// What api.getPage() resolves to: the queue endpoint's own { data, meta } body.
const page = (rows, meta = {}) => ({
  data: rows,
  meta: { current_page: 1, per_page: 25, total: rows.length, last_page: rows.length ? 1 : 0, ...meta },
});

// A finding row as CommunityModerationController::queue emits it: the column
// is `details` (JSONB, serialised as text), not `detail`.
const FINDING = {
  id: '5',
  answer_version_id: '10',
  question_id: null,
  finding_type: 'prompt_injection',
  severity: 'critical',
  details: '{"reason": "Detected injection attempt"}',
  status: 'open',
  created_at: '2026-07-11 10:00:00+00',
  version_number: '2',
};

beforeEach(() => { api.getPage.mockReset(); });

describe('CommunityModerationQueuePage', () => {
  it('shows "Queue is empty" when no findings', async () => {
    api.getPage.mockResolvedValueOnce(page([]));
    renderWithAuth(<CommunityModerationQueuePage />, ctx);
    await waitFor(() => expect(screen.getByText(/Queue is empty/i)).toBeInTheDocument());
  });

  it('renders finding rows with their details', async () => {
    api.getPage.mockResolvedValueOnce(page([FINDING]));
    renderWithAuth(<CommunityModerationQueuePage />, ctx);
    await waitFor(() => expect(screen.getByText('prompt_injection')).toBeInTheDocument());
    expect(screen.getByText('critical')).toBeInTheDocument();
    expect(screen.getByText(/Detected injection attempt/)).toBeInTheDocument();
  });

  it('shows the pager when the API reports more than one page', async () => {
    api.getPage.mockResolvedValueOnce(page([FINDING], { total: 40, last_page: 2 }));
    renderWithAuth(<CommunityModerationQueuePage />, ctx);
    expect(await screen.findByText('Page 1 / 2')).toBeInTheDocument();
  });

  it('renders page heading', async () => {
    api.getPage.mockResolvedValueOnce(page([]));
    renderWithAuth(<CommunityModerationQueuePage />, ctx);
    await waitFor(() => expect(screen.getByText('Moderation Queue')).toBeInTheDocument());
  });
});
