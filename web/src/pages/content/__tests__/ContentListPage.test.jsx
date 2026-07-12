import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { ContentListPage } from '../ContentListPage';

// Mock global fetch so contentService doesn't fail
beforeEach(() => {
  global.fetch = vi.fn().mockResolvedValue({
    ok: true,
    json: () => Promise.resolve({ ok: true, data: { items: [], total: 0 } }),
  });
});

const ctx = {
  auth: {
    user: { id: 1, email: 'manager@test.com', role: 'marketing_manager' },
    permissions: ['content.view', 'content.create'],
  },
};

describe('ContentListPage', () => {
  it('renders page heading', async () => {
    renderWithAuth(<ContentListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Content Studio/i)).toBeInTheDocument());
  });

  it('shows New Content button for users with content.create', async () => {
    renderWithAuth(<ContentListPage />, ctx);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /new content/i })).toBeInTheDocument();
    });
  });
});
