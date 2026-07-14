import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import VideoRenderQueuePage from '../VideoRenderQueuePage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'super_admin' },
    permissions: ['video.render'],
  },
};

beforeEach(() => { api.get.mockReset(); });

describe('VideoRenderQueuePage', () => {
  it('renders the page heading', async () => {
    api.get.mockResolvedValueOnce({ data: { data: { data: [], total: 0 } } });
    renderWithAuth(<VideoRenderQueuePage />, ctx);
    await waitFor(() => expect(screen.getByText('Render Queue')).toBeInTheDocument());
  });

  it('shows empty state when no jobs', async () => {
    api.get.mockResolvedValueOnce({ data: { data: { data: [], total: 0 } } });
    renderWithAuth(<VideoRenderQueuePage />, ctx);
    await waitFor(() => expect(screen.getByText(/no render jobs/i)).toBeInTheDocument());
  });

  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<VideoRenderQueuePage />, ctx);
    expect(screen.getByText(/loading render queue/i)).toBeInTheDocument();
  });
});
