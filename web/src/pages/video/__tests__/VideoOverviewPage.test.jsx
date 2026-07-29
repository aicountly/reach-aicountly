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
  it('renders the page heading and section cards', async () => {
    api.get
      .mockResolvedValueOnce({ data: [], total: 3 })
      .mockResolvedValueOnce({ data: [], total: 5 });
    renderWithAuth(<VideoOverviewPage />, ctx);
    expect(screen.getByRole('heading', { name: 'Overview' })).toBeInTheDocument();
    expect(screen.getByText('Idea Backlog')).toBeInTheDocument();
    expect(screen.getByText('Projects')).toBeInTheDocument();
    expect(screen.getByText('Render Queue')).toBeInTheDocument();
    expect(screen.getByText('Publications')).toBeInTheDocument();
    expect(screen.getByText('YouTube Connections')).toBeInTheDocument();
    expect(screen.getByText('Operations')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('3')).toBeInTheDocument());
    expect(screen.getByText('5')).toBeInTheDocument();
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

  it('keeps section cards visible while stats load', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<VideoOverviewPage />, ctx);
    expect(screen.getByRole('heading', { name: 'Overview' })).toBeInTheDocument();
    expect(screen.getByText('Idea Backlog')).toBeInTheDocument();
    expect(screen.getAllByText('…').length).toBeGreaterThan(0);
  });

  it('links Open backlog to the ideas route', async () => {
    api.get
      .mockResolvedValueOnce({ data: [], total: 0 })
      .mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<VideoOverviewPage />, ctx);
    const link = screen.getByText('Open backlog').closest('a');
    expect(link?.getAttribute('href')).toBe('/video/ideas');
    await waitFor(() => expect(api.get).toHaveBeenCalled());
  });
});
