import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import VideoProjectListPage from '../VideoProjectListPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'super_admin' },
    permissions: ['video.read'],
  },
};

const emptyResponse = { data: { data: { data: [], total: 0 } } };

beforeEach(() => { api.get.mockReset(); });

describe('VideoProjectListPage', () => {
  it('renders the page heading', async () => {
    api.get.mockResolvedValueOnce(emptyResponse);
    renderWithAuth(<VideoProjectListPage />, ctx);
    await waitFor(() => expect(screen.getByText('Video Projects')).toBeInTheDocument());
  });

  it('shows empty state when no projects', async () => {
    api.get.mockResolvedValueOnce(emptyResponse);
    renderWithAuth(<VideoProjectListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No video projects found/i)).toBeInTheDocument());
  });

  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<VideoProjectListPage />, ctx);
    expect(screen.getByText(/Loading projects/i)).toBeInTheDocument();
  });
});
