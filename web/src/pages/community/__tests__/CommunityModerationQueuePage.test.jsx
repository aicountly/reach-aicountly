import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import CommunityModerationQueuePage from '../CommunityModerationQueuePage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['community_question.moderate'],
  },
};

beforeEach(() => { api.get.mockReset(); });

describe('CommunityModerationQueuePage', () => {
  it('shows "Queue is empty" when no findings', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [], meta: { last_page: 1 } } });
    renderWithAuth(<CommunityModerationQueuePage />, ctx);
    await waitFor(() => expect(screen.getByText(/Queue is empty/i)).toBeInTheDocument());
  });

  it('renders finding rows when findings present', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [{
          id: 5,
          finding_type: 'prompt_injection',
          severity: 'critical',
          answer_version_id: 10,
          version_number: 2,
          detail: 'Detected injection attempt',
          created_at: '2026-07-11T10:00:00Z',
        }],
        meta: { last_page: 1 },
      },
    });
    renderWithAuth(<CommunityModerationQueuePage />, ctx);
    await waitFor(() => expect(screen.getByText('prompt_injection')).toBeInTheDocument());
    expect(screen.getByText('critical')).toBeInTheDocument();
  });

  it('renders page heading', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [], meta: { last_page: 1 } } });
    renderWithAuth(<CommunityModerationQueuePage />, ctx);
    await waitFor(() => expect(screen.getByText('Moderation Queue')).toBeInTheDocument());
  });
});
