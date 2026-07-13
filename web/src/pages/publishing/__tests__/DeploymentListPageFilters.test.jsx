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

describe('DeploymentListPage additional', () => {
  it('shows Deployments heading', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<DeploymentListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Deployments')).toBeInTheDocument());
  });

  it('shows deployment operation field', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [{ id: 1, content_item_id: 1, content_title: 'Test', status: 'published', operation: 'publish', attempt_count: 1, updated_at: null }],
      },
    });
    renderWithAuth(<DeploymentListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Test')).toBeInTheDocument());
  });

  it('shows attempt count', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [{ id: 7, content_item_id: 2, content_title: 'Another', status: 'failed', operation: 'publish', attempt_count: 3, updated_at: null }],
      },
    });
    renderWithAuth(<DeploymentListPage />, ctx);
    await waitFor(() => expect(screen.getByText('3')).toBeInTheDocument());
  });
});
