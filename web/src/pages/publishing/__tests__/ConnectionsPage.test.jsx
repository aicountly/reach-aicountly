import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));
import api from '../../../services/api';
import ConnectionsPage from '../ConnectionsPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.manage_connections'],
  },
};

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

const makeConnection = (overrides = {}) => ({
  id: 1,
  connection_key: 'aicountly-production',
  display_name: 'aicountly.com Production',
  api_version: 1,
  authentication_type: 'hmac_sha256',
  enabled: true,
  health_status: 'healthy',
  last_health_error: null,
  ...overrides,
});

describe('ConnectionsPage', () => {
  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<ConnectionsPage />, ctx);
    expect(screen.getByText(/Loading connections/i)).toBeInTheDocument();
  });

  it('shows empty state when no connections', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<ConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No connections configured/i)).toBeInTheDocument());
  });

  it('renders connection card', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [makeConnection()] } });
    renderWithAuth(<ConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText('aicountly.com Production')).toBeInTheDocument());
    expect(screen.getByText('aicountly-production')).toBeInTheDocument();
    expect(screen.getByText('Enabled')).toBeInTheDocument();
  });

  it('shows health badge', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [makeConnection()] } });
    renderWithAuth(<ConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText('healthy')).toBeInTheDocument());
  });

  it('note about credentials from environment', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [makeConnection()] } });
    renderWithAuth(<ConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText(/environment variables/i)).toBeInTheDocument());
  });

  it('shows Check Health button', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [makeConnection()] } });
    renderWithAuth(<ConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText('Check Health')).toBeInTheDocument());
  });

  it('calls health check API on button click', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [makeConnection()] } });
    api.post.mockResolvedValueOnce({ data: { data: { health_status: 'healthy' } } });

    renderWithAuth(<ConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText('Check Health')).toBeInTheDocument());

    fireEvent.click(screen.getByText('Check Health'));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      '/publishing/connections/aicountly-production/health-check'
    ));
  });
});
