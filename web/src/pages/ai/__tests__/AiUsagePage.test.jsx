import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiUsagePage from '../AiUsagePage';

vi.mock('../../../services/aiService.js', () => ({
  listAiUsage: vi.fn(),
  default: {},
}));

import { listAiUsage } from '../../../services/aiService.js';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'admin' },
    permissions: ['ai_provider.manage'],
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  listAiUsage.mockResolvedValue({
    usage: [
      { id: 1, usage_date: '2026-07-13', task_type: 'draft_generation', content_type: 'blog_post', input_tokens: 500, output_tokens: 300, estimated_cost: '0.001234', currency: 'USD' },
    ],
  });
});

describe('AiUsagePage', () => {
  it('renders usage records', async () => {
    renderWithAuth(<AiUsagePage />, ctx);
    await waitFor(() => expect(screen.getByText('draft_generation')).toBeInTheDocument());
    expect(screen.getByText('blog_post')).toBeInTheDocument();
  });

  it('shows AI Usage Ledger heading', async () => {
    renderWithAuth(<AiUsagePage />, ctx);
    await waitFor(() => expect(screen.getByText(/AI Usage Ledger/i)).toBeInTheDocument());
  });
});
