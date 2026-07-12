import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/blogService', () => ({
  blogService: {
    list: vi.fn(),
  },
}));

import { blogService } from '../../../services/blogService';
import { BlogListPage } from '../BlogListPage';

beforeEach(() => {
  blogService.list.mockReset();
});

const authAsAdmin = {
  auth: {
    user: { id: 1, email: 'root@aicountly.org', role: 'super_admin' },
    permissions: ['*'],
  },
};

describe('BlogListPage', () => {
  it('renders posts returned from the API', async () => {
    blogService.list.mockResolvedValueOnce({
      items: [
        { id: 7, title: 'Why cash flow matters', slug: 'why-cash-flow', status: 'draft', approval_status: 'pending', publishing_status: 'none', bot_generated: false, updated_at: '2026-07-01T09:00:00Z' },
      ],
      total: 1,
    });
    renderWithAuth(<BlogListPage />, authAsAdmin);
    await waitFor(() => expect(screen.getByText('Why cash flow matters')).toBeInTheDocument());
  });

  it('shows a danger Alert when the API rejects', async () => {
    const err = Object.assign(new Error('Backend unavailable'), { status: 503 });
    blogService.list.mockRejectedValueOnce(err);
    renderWithAuth(<BlogListPage />, authAsAdmin);
    await waitFor(() => expect(screen.getByText(/Backend unavailable/)).toBeInTheDocument());
    // Alert has role="alert" via the Alert component
    const alert = screen.getByText(/Backend unavailable/).closest('.alert');
    expect(alert).toBeTruthy();
  });
});
