import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual('react-router-dom');
  return {
    ...actual,
    useParams: () => ({ deploymentId: '5' }),
  };
});
import api from '../../../services/api';
import DeploymentDetailPage from '../DeploymentDetailPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view', 'publishing.rollback', 'publishing.verify'],
  },
};

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

const makeDeployment = () => ({
  id: 5,
  uuid: 'dep-uuid-5',
  content_item_id: 42,
  status: 'verified',
  operation: 'publish',
  attempt_count: 1,
  canonical_url: 'https://aicountly.com/blog/test',
  created_at: '2026-07-13T10:00:00Z',
  updated_at: '2026-07-13T11:00:00Z',
});

describe('DeploymentDetailPage', () => {
  it('shows loading state', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<DeploymentDetailPage />, ctx);
    expect(screen.getByText(/Loading/i)).toBeInTheDocument();
  });

  it('renders deployment detail after loading', async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: makeDeployment() } })
      .mockResolvedValueOnce({ data: { data: [] } });

    renderWithAuth(<DeploymentDetailPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Deployment #5/i)).toBeInTheDocument());
  });

  it('shows deployment status badge', async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: makeDeployment() } })
      .mockResolvedValueOnce({ data: { data: [] } });

    renderWithAuth(<DeploymentDetailPage />, ctx);
    await waitFor(() => expect(screen.getByText('verified')).toBeInTheDocument());
  });

  it('shows canonical URL when present', async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: makeDeployment() } })
      .mockResolvedValueOnce({ data: { data: [] } });

    renderWithAuth(<DeploymentDetailPage />, ctx);
    await waitFor(() => expect(screen.getByText('https://aicountly.com/blog/test')).toBeInTheDocument());
  });

  it('handles API error', async () => {
    api.get.mockRejectedValueOnce(new Error('Not found'));
    renderWithAuth(<DeploymentDetailPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Not found/i)).toBeInTheDocument());
  });
});
