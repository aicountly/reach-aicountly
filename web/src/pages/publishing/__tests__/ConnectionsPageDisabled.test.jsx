import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
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

describe('ConnectionsPage disabled connection', () => {
  it('shows Disabled badge for disabled connection', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [{
          id: 2,
          connection_key: 'staging-site',
          display_name: 'Staging Site',
          api_version: 1,
          authentication_type: 'hmac_sha256',
          enabled: false,
          health_status: 'unknown',
        }],
      },
    });
    renderWithAuth(<ConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText('Disabled')).toBeInTheDocument());
  });

  it('shows degraded health badge', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [{
          id: 3,
          connection_key: 'prod',
          display_name: 'Production',
          api_version: 1,
          authentication_type: 'hmac_sha256',
          enabled: true,
          health_status: 'degraded',
        }],
      },
    });
    renderWithAuth(<ConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText('degraded')).toBeInTheDocument());
  });

  it('shows last_health_error when present', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [{
          id: 4,
          connection_key: 'prod2',
          display_name: 'Prod 2',
          api_version: 1,
          authentication_type: 'hmac_sha256',
          enabled: true,
          health_status: 'unhealthy',
          last_health_error: 'Connection timeout after 15s',
        }],
      },
    });
    renderWithAuth(<ConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText('Connection timeout after 15s')).toBeInTheDocument());
  });

  it('page header is present', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<ConnectionsPage />, ctx);
    await waitFor(() => expect(screen.getByText('Publication Connections')).toBeInTheDocument());
  });
});
