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

const makeVerification = (overrides = {}) => ({
  id: 1,
  deployment_id: 10,
  verification_type: 'public_status',
  status: 'passed',
  expected_value: 'published',
  actual_value: 'published',
  checked_at: '2026-07-13T10:00:00Z',
  ...overrides,
});

describe('VerificationListPage', () => {
  it('shows loading state', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<VerificationListPage />, ctx);
    expect(screen.getByText(/Loading verifications/i)).toBeInTheDocument();
  });

  it('renders page header', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<VerificationListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Verification Results')).toBeInTheDocument());
  });

  it('shows empty state message', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<VerificationListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No verification results/i)).toBeInTheDocument());
  });

  it('renders verification row', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [makeVerification()] } });
    renderWithAuth(<VerificationListPage />, ctx);
    await waitFor(() => expect(screen.getByText('public_status')).toBeInTheDocument());
    expect(screen.getByText('passed')).toBeInTheDocument();
  });

  it('renders failed verification with error badge', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [makeVerification({ status: 'failed', actual_value: 'draft' })] } });
    renderWithAuth(<VerificationListPage />, ctx);
    await waitFor(() => expect(screen.getByText('failed')).toBeInTheDocument());
  });

  it('handles API error', async () => {
    api.get.mockRejectedValueOnce(new Error('API error'));
    renderWithAuth(<VerificationListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/API error/i)).toBeInTheDocument());
  });
});
