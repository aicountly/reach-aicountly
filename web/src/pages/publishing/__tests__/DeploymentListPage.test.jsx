import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import DeploymentListPage from '../DeploymentListPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view'],
  },
};

beforeEach(() => {
  api.get.mockReset();
});

const makeDeployment = (overrides = {}) => ({
  id: 1,
  content_item_id: 10,
  content_title: 'Test Article',
  status: 'published',
  operation: 'publish',
  attempt_count: 1,
  canonical_url: 'https://aicountly.com/blog/test',
  updated_at: '2026-07-13T10:00:00Z',
  ...overrides,
});

describe('DeploymentListPage', () => {
  it('shows loading state', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<DeploymentListPage />, ctx);
    expect(screen.getByText(/Loading/i)).toBeInTheDocument();
  });

  it('shows empty state', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [], meta: { last_page: 1 } } });
    renderWithAuth(<DeploymentListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No deployments/i)).toBeInTheDocument());
  });

  it('renders deployment rows', async () => {
    api.get.mockResolvedValueOnce({
      data: { data: [makeDeployment()], meta: { last_page: 1 } },
    });
    renderWithAuth(<DeploymentListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Test Article')).toBeInTheDocument());
  });

  it('shows all status labels defined', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [makeDeployment({ status: 'verified' })],
        meta: { last_page: 1 },
      },
    });
    renderWithAuth(<DeploymentListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Verified')).toBeInTheDocument());
  });

  it('handles API error gracefully', async () => {
    api.get.mockRejectedValueOnce(new Error('Network error'));
    renderWithAuth(<DeploymentListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Network error/i)).toBeInTheDocument());
  });
});
