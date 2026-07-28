import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));
import api from '../../../services/api';
import SocialOperationsPage from '../SocialOperationsPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['*'],
  },
};

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

describe('SocialOperationsPage', () => {
  it('calls v1/social/posts (not /social-posts)', async () => {
    api.get.mockResolvedValueOnce({ items: [], total: 0 });
    renderWithAuth(<SocialOperationsPage />, ctx);
    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(api.get).toHaveBeenCalledWith('v1/social/posts', expect.objectContaining({
      status: 'approved',
      page: 1,
      limit: 25,
    }));
    expect(api.get.mock.calls.some(([path]) => String(path).includes('social-posts'))).toBe(false);
  });

  it('renders posts from the API', async () => {
    api.get.mockResolvedValueOnce({
      items: [
        { id: 3, channel: 'linkedin', content: 'Hello LinkedIn world', status: 'approved', provider: null },
      ],
      total: 1,
    });
    renderWithAuth(<SocialOperationsPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Hello LinkedIn world/)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /dispatch/i })).toBeInTheDocument();
  });
});
