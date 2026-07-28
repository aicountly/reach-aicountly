import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import VideoPublicationListPage from '../VideoPublicationListPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'super_admin' },
    permissions: ['video.publish'],
  },
};

beforeEach(() => { api.get.mockReset(); });

describe('VideoPublicationListPage', () => {
  it('calls the v1 publications endpoint', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<VideoPublicationListPage />, ctx);
    await waitFor(() =>
      expect(api.get).toHaveBeenCalledWith('v1/video/publications', {
        page: 1,
        per_page: 25,
      }),
    );
  });

  it('renders the page heading', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<VideoPublicationListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Video Publications')).toBeInTheDocument());
  });

  it('shows empty state when no publications', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<VideoPublicationListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/no publications yet/i)).toBeInTheDocument());
  });

  it('shows empty state for nested legacy payloads', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [], total: 0 } });
    renderWithAuth(<VideoPublicationListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/no publications yet/i)).toBeInTheDocument());
  });

  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<VideoPublicationListPage />, ctx);
    expect(screen.getByText(/loading publications/i)).toBeInTheDocument();
  });
});
