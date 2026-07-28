import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}));
import api from '../../../services/api';
import SuppressionPage from '../SuppressionPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['distribution.read_suppression', 'distribution.manage_suppression'],
  },
};

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
  api.delete.mockReset();
});

describe('SuppressionPage', () => {
  it('calls the v1 suppressions endpoint', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0, page: 1, per_page: 25 });
    renderWithAuth(<SuppressionPage />, ctx);
    await waitFor(() =>
      expect(api.get).toHaveBeenCalledWith('v1/distribution/suppressions', {
        page: 1,
        per_page: 25,
      }),
    );
  });

  it('does not call the legacy non-v1 path', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<SuppressionPage />, ctx);
    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(
      api.get.mock.calls.some(([path]) => String(path) === '/distribution/suppressions'
        || String(path).startsWith('/distribution/suppressions?')),
    ).toBe(false);
  });

  it('renders suppressions from API payload', async () => {
    api.get.mockResolvedValueOnce({
      data: [{
        id: 3,
        channel: 'email',
        address_masked: 'ab***@example.com',
        reason: 'bounce',
        suppressed_at: '2026-07-01T00:00:00Z',
      }],
      total: 1,
      page: 1,
      per_page: 25,
    });
    renderWithAuth(<SuppressionPage />, ctx);
    await waitFor(() => expect(screen.getByText('ab***@example.com')).toBeInTheDocument());
    expect(screen.getByText('bounce')).toBeInTheDocument();
  });

  it('shows empty state on 404 instead of hard error', async () => {
    const err = new Error('Request failed with status 404');
    err.status = 404;
    api.get.mockRejectedValueOnce(err);
    renderWithAuth(<SuppressionPage />, ctx);
    await waitFor(() =>
      expect(screen.getByText(/No suppressions/i)).toBeInTheDocument(),
    );
    expect(screen.queryByText(/Request failed with status 404/i)).not.toBeInTheDocument();
  });
});
