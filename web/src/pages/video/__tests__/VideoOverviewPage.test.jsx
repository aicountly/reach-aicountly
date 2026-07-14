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

const mockIdeas    = { data: { data: { total: 3 } } };
const mockProjects = { data: { data: { total: 5 } } };

beforeEach(() => { api.get.mockReset(); });

describe('VideoOverviewPage', () => {
  it('renders the page heading', async () => {
    api.get.mockResolvedValueOnce(mockIdeas).mockResolvedValueOnce(mockProjects);
    renderWithAuth(<VideoOverviewPage />, ctx);
    await waitFor(() => expect(screen.getByText('Video Automation')).toBeInTheDocument());
  });

  it('shows loading state initially', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<VideoOverviewPage />, ctx);
    expect(screen.getByText(/loading video overview/i)).toBeInTheDocument();
  });
});
