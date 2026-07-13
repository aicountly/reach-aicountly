import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiGenerationsPage from '../AiGenerationsPage';

vi.mock('../../../services/aiService.js', () => ({
  listGenerations: vi.fn(),
  default: {},
}));

import { listGenerations } from '../../../services/aiService.js';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'admin' },
    permissions: ['ai.view', 'ai.generate'],
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  listGenerations.mockResolvedValue({
    requests: [
      {
        uuid: 'test-uuid-0001-0001',
        task_type: 'draft_generation',
        content_type: 'blog_post',
        status: 'completed',
        requested_actor_type: 'user',
        created_at: '2026-07-13T06:00:00Z',
      },
    ],
    total: 1,
  });
});

describe('AiGenerationsPage', () => {
  it('renders generation requests table', async () => {
    renderWithAuth(<AiGenerationsPage />, ctx);
    await waitFor(() => expect(screen.getByText('blog_post')).toBeInTheDocument());
    expect(screen.getByText('draft_generation')).toBeInTheDocument();
  });

  it('shows total count', async () => {
    renderWithAuth(<AiGenerationsPage />, ctx);
    await waitFor(() => expect(screen.getByText('1 total')).toBeInTheDocument());
  });
});
