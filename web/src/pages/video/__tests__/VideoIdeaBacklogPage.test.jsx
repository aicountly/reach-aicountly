import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import VideoIdeaBacklogPage from '../VideoIdeaBacklogPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'super_admin' },
    permissions: ['video.read'],
  },
};

const emptyResponse = { data: { data: { data: [], total: 0 } } };

beforeEach(() => { api.get.mockReset(); });

describe('VideoIdeaBacklogPage', () => {
  it('renders the page heading', async () => {
    api.get.mockResolvedValueOnce(emptyResponse);
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    await waitFor(() => expect(screen.getByText('Video Idea Backlog')).toBeInTheDocument());
  });

  it('shows empty state when no ideas', async () => {
    api.get.mockResolvedValueOnce(emptyResponse);
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No video ideas found/i)).toBeInTheDocument());
  });

  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<VideoIdeaBacklogPage />, ctx);
    expect(screen.getByText(/Loading ideas/i)).toBeInTheDocument();
  });
});
