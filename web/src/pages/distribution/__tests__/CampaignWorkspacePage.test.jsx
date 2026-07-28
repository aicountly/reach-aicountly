import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));
import api from '../../../services/api';
import CampaignWorkspacePage from '../CampaignWorkspacePage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['distribution.read', 'distribution.create'],
  },
};

function renderAt(path) {
  return renderWithAuth(
    <Routes>
      <Route path="/distribution/campaigns/:id" element={<CampaignWorkspacePage />} />
      <Route path="/distribution/campaigns" element={<CampaignWorkspacePage />} />
    </Routes>,
    { ...ctx, route: path },
  );
}

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

describe('CampaignWorkspacePage', () => {
  it('loads campaign and versions when id is present', async () => {
    api.get
      .mockResolvedValueOnce({ id: 9, name: 'Launch', status: 'draft' })
      .mockResolvedValueOnce([{ id: 1, version_number: 1, created_at: '2026-07-01T00:00:00Z' }]);

    renderAt('/distribution/campaigns/9');

    await waitFor(() => {
      expect(api.get).toHaveBeenCalledWith('v1/campaigns/9');
      expect(api.get).toHaveBeenCalledWith('v1/campaigns/9/versions');
    });
    expect(screen.getByText('Launch')).toBeInTheDocument();
    expect(screen.getByText(/Version 1/i)).toBeInTheDocument();
  });

  it('does not call campaigns/undefined when id is missing', async () => {
    renderAt('/distribution/campaigns');
    await waitFor(() => expect(screen.getByText(/Campaign id is missing/i)).toBeInTheDocument());
    expect(api.get).not.toHaveBeenCalled();
  });
});
