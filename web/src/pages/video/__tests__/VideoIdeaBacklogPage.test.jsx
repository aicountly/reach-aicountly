import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));
import api from '../../../services/api';
import VideoIdeaBacklogPage from '../VideoIdeaBacklogPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'super_admin' },
    permissions: ['video.read', 'video.update', 'video.create'],
  },
};

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

describe('VideoIdeaBacklogPage', () => {
  it('calls the v1 video ideas endpoint', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0, page: 1, per_page: 25 });
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    await waitFor(() =>
      expect(api.get).toHaveBeenCalledWith('v1/video/ideas', {
        page: 1,
        per_page: 25,
      }),
    );
  });

  it('does not call the legacy non-v1 path', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(
      api.get.mock.calls.some(([path]) =>
        String(path) === '/video/ideas'
        || String(path).startsWith('/video/ideas?')),
    ).toBe(false);
  });

  it('renders the page heading', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    await waitFor(() => expect(screen.getByText('Video Idea Backlog')).toBeInTheDocument());
  });

  it('shows empty state when no ideas', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No video ideas found/i)).toBeInTheDocument());
  });

  it('renders ideas from API payload', async () => {
    api.get.mockResolvedValueOnce({
      data: [{
        uuid: 'idea-1',
        title: 'GST Explainer',
        status: 'ready',
        score_total: 88,
      }],
      total: 1,
    });
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    await waitFor(() => expect(screen.getByText('GST Explainer')).toBeInTheDocument());
    expect(screen.getByText('88/100')).toBeInTheDocument();
  });

  it('shows empty state on 404 instead of hard error', async () => {
    const err = new Error('Request failed with status 404');
    err.status = 404;
    api.get.mockRejectedValueOnce(err);
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No video ideas found/i)).toBeInTheDocument());
    expect(screen.queryByText(/Request failed with status 404/i)).not.toBeInTheDocument();
  });

  it('shows empty state on 500 instead of hard error', async () => {
    const err = new Error('Request failed with status 500');
    err.status = 500;
    api.get.mockRejectedValueOnce(err);
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No video ideas found/i)).toBeInTheDocument());
    expect(screen.queryByText(/Request failed with status 500/i)).not.toBeInTheDocument();
  });

  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    expect(screen.getByText(/Loading ideas/i)).toBeInTheDocument();
  });
});
