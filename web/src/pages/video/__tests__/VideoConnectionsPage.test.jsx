import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import VideoConnectionsPage from '../VideoConnectionsPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'super_admin' },
    permissions: ['video_connections.read', 'video_connections.manage'],
  },
};

beforeEach(() => { api.get.mockReset(); });

describe('VideoConnectionsPage', () => {
  it('renders the page heading', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<VideoConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText('YouTube Connections')).toBeInTheDocument());
  });

  it('shows empty state when no connections', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<VideoConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText(/no youtube connections configured/i)).toBeInTheDocument());
  });

  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<VideoConnectionsPage />, ctx);
    expect(screen.getByText(/loading connections/i)).toBeInTheDocument();
  });
});
