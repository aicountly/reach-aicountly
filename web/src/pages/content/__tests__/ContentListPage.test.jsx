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

  // Dashboard tiles open the rows they counted.
  it('applies workflow_status and content_type from the URL', async () => {
    renderWithAuth(<ContentListPage />, { ...ctx, route: '/content?workflow_status=scheduled,ready_for_publication&content_type=blog' });
    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    const url = String(global.fetch.mock.calls[0][0]);
    expect(url).toContain('workflow_status=scheduled%2Cready_for_publication');
    expect(url).toContain('content_type=blog');
  });
});
