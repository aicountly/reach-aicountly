import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import VerificationListPage from '../VerificationListPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view'],
  },
};

beforeEach(() => {
  api.get.mockReset();
});

const makeVerification = (status) => ({
  id: Math.floor(Math.random() * 1000),
  deployment_id: 1,
  status,
  http_status_code: status === 'verified' ? 200 : 404,
  checked_at: '2026-07-13T10:00:00Z',
});

describe('VerificationListPage status scenarios', () => {
  it.each([
    ['verified'],
    ['failed'],
    ['pending'],
    ['skipped'],
  ])('renders verification status: %s', async (status) => {
    api.get.mockResolvedValueOnce({ data: { data: [makeVerification(status)] } });
    const { unmount } = renderWithAuth(<VerificationListPage />, ctx);
    await waitFor(() => expect(screen.getByText(status)).toBeInTheDocument());
    unmount();
  });

  it('shows check time column', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [{ id: 1, deployment_id: 1, status: 'verified', http_status_code: 200, checked_at: '2026-07-13T10:00:00Z' }],
      },
    });
    renderWithAuth(<VerificationListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/2026/)).toBeInTheDocument());
  });

  it('shows multiple verification rows', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          makeVerification('verified'),
          makeVerification('failed'),
          makeVerification('pending'),
        ],
      },
    });
    renderWithAuth(<VerificationListPage />, ctx);
    await waitFor(() => expect(screen.getByText('verified')).toBeInTheDocument());
    expect(screen.getByText('failed')).toBeInTheDocument();
  });
});
