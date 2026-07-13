import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiPromptsPage from '../AiPromptsPage';

vi.mock('../../../services/aiService.js', () => ({
  listPrompts: vi.fn(),
  default: {},
}));

import { listPrompts } from '../../../services/aiService.js';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'admin' },
    permissions: ['ai_prompt.view', 'ai_prompt.approve', 'ai_prompt.manage'],
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  listPrompts.mockResolvedValue({
    templates: [
      { id: 1, name: 'Blog Post Draft', slug: 'blog-post-draft', task_type: 'draft_generation', content_type: 'blog_post', status: 'approved' },
    ],
  });
});

describe('AiPromptsPage', () => {
  it('renders prompt templates', async () => {
    renderWithAuth(<AiPromptsPage />, ctx);
    await waitFor(() => expect(screen.getByText('Blog Post Draft')).toBeInTheDocument());
    expect(screen.getByText('blog-post-draft')).toBeInTheDocument();
  });

  it('shows immutability notice', async () => {
    renderWithAuth(<AiPromptsPage />, ctx);
    await waitFor(() => expect(screen.getByText(/immutable/i)).toBeInTheDocument());
  });
});
