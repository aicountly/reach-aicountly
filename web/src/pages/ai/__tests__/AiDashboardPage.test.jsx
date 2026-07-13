import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiDashboardPage from '../AiDashboardPage';

vi.mock('../../../services/aiService.js', () => ({
  getAiDashboard: vi.fn(),
  default: {},
}));

import { getAiDashboard } from '../../../services/aiService.js';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'admin' },
    permissions: ['ai.view', 'ai.generate', 'ai_provider.manage'],
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  getAiDashboard.mockResolvedValue({
    stats: { total_generations: 42, completed_today: 10, failed_today: 2, today_cost: '1.2345' },
    recent_requests: [
      { uuid: 'abc-123-uuid-0001', content_type: 'blog_post', status: 'completed', created_at: '2026-07-13T00:00:00Z' },
    ],
  });
});

describe('AiDashboardPage', () => {
  it('renders stats after load', async () => {
    renderWithAuth(<AiDashboardPage />, ctx);
    await waitFor(() => expect(screen.getByText('42')).toBeInTheDocument());
    expect(screen.getByText('10')).toBeInTheDocument();
  });

  it('renders recent requests table', async () => {
    renderWithAuth(<AiDashboardPage />, ctx);
    await waitFor(() => expect(screen.getByText(/blog_post/i)).toBeInTheDocument());
  });

  it('shows AI Dashboard heading', async () => {
    renderWithAuth(<AiDashboardPage />, ctx);
    await waitFor(() => expect(screen.getByText(/AI Dashboard/i)).toBeInTheDocument());
  });
});
