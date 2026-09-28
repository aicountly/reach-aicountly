import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), getPage: vi.fn(), post: vi.fn() },
}));
import api from '../../../services/api';
import CommunityPublishingMonitorPage from '../CommunityPublishingMonitorPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['community.view', 'community_answer.publish'],
  },
};

// What api.getPage() resolves to: the deployments endpoint's own body.
const page = (rows, meta = {}) => ({
  data: rows,
  meta: { current_page: 1, per_page: 25, total: rows.length, last_page: rows.length ? 1 : 0, ...meta },
});

// A deployment row as CommunityDeploymentController::index emits it. Its id
// is `uuid`; the page used to read external_id, so Retry and Verify posted to
// /deployments/undefined/…
const deployment = (status) => ({
  id: '3',
  uuid: '7d9f3c2a-1b4e-4c5d-8e6f-0a1b2c3d4e5f',
  answer_uuid: 'c0a8b4de-7f3e-4b8a-9c1d-2e5f6a7b8c9d',
  operation: 'publish',
  status,
  attempt_count: '2',
  updated_at: '2026-07-11 10:00:00+00',
});

beforeEach(() => {
  api.getPage.mockReset();
  api.post.mockReset();
});

describe('CommunityPublishingMonitorPage', () => {
  it('retries a failed deployment by its uuid', async () => {
    const row = deployment('failed');
    api.getPage.mockResolvedValue(page([row]));
    api.post.mockResolvedValueOnce({});
    renderWithAuth(<CommunityPublishingMonitorPage />, ctx);

    await userEvent.setup().click(await screen.findByRole('button', { name: 'Retry' }));

    expect(api.post).toHaveBeenCalledWith(`v1/community/deployments/${row.uuid}/retry`, {});
    expect(screen.getByText(row.uuid.slice(0, 8) + '…')).toBeInTheDocument();
  });

  it('verifies a confirmed deployment by its uuid', async () => {
    const row = deployment('confirmed');
    api.getPage.mockResolvedValue(page([row]));
    api.post.mockResolvedValueOnce({});
    renderWithAuth(<CommunityPublishingMonitorPage />, ctx);

    await userEvent.setup().click(await screen.findByRole('button', { name: 'Verify' }));

    expect(api.post).toHaveBeenCalledWith(`v1/community/deployments/${row.uuid}/verify`, {});
  });

  it('shows the pager when the API reports more than one page', async () => {
    api.getPage.mockResolvedValueOnce(page([deployment('sent')], { total: 30, last_page: 2 }));
    renderWithAuth(<CommunityPublishingMonitorPage />, ctx);
    await waitFor(() => expect(screen.getByText('Page 1 / 2')).toBeInTheDocument());
  });
});
