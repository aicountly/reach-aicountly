import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import VideoOverviewPage from '../VideoOverviewPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'super_admin' },
    permissions: ['video.read'],
  },
};

beforeEach(() => { api.get.mockReset(); });

describe('VideoOverviewPage', () => {
  it('renders the page heading', async () => {
    api.get
      .mockResolvedValueOnce({ data: [], total: 3 })
      .mockResolvedValueOnce({ data: [], total: 5 });
    renderWithAuth(<VideoOverviewPage />, ctx);
    await waitFor(() => expect(screen.getByText('Video Automation')).toBeInTheDocument());
  });

  it('requests ideas and projects under v1/', async () => {
    api.get
      .mockResolvedValueOnce({ data: [], total: 0 })
      .mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<VideoOverviewPage />, ctx);
    await waitFor(() => {
      expect(api.get).toHaveBeenCalledWith('v1/video/ideas', { per_page: 1 });
      expect(api.get).toHaveBeenCalledWith('v1/video/projects', { per_page: 1 });
    });
  });

  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<VideoOverviewPage />, ctx);
    expect(screen.getByText(/loading video overview/i)).toBeInTheDocument();
  });
});
