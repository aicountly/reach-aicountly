import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));
import api from '../../../services/api';
import DispatchOrchestrationPage from '../DispatchOrchestrationPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['distribution.read', 'distribution.retry'],
  },
};

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

describe('DispatchOrchestrationPage', () => {
  it('calls the v1 dispatches endpoint', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0, page: 1, per_page: 25 });
    renderWithAuth(<DispatchOrchestrationPage />, ctx);
    await waitFor(() =>
      expect(api.get).toHaveBeenCalledWith('v1/distribution/dispatches', {
        page: 1,
        per_page: 25,
      }),
    );
  });

  it('does not call the legacy non-v1 path', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<DispatchOrchestrationPage />, ctx);
    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(
      api.get.mock.calls.some(([path]) =>
        String(path) === '/distribution/dispatches'
        || String(path).startsWith('/distribution/dispatches?')),
    ).toBe(false);
  });

  it('renders dispatch rows', async () => {
    api.get.mockResolvedValueOnce({
      data: [{
        id: 7,
        campaign_id: 42,
        channel: 'email',
        status: 'completed',
        total_recipients: 100,
        delivered_count: 98,
        failed_count: 2,
      }],
      total: 1,
    });
    renderWithAuth(<DispatchOrchestrationPage />, ctx);
    await waitFor(() => expect(screen.getByText('42')).toBeInTheDocument());
    const table = within(screen.getByRole('table'));
    expect(table.getByText('email')).toBeInTheDocument();
    expect(table.getByText('completed')).toBeInTheDocument();
    // The campaign cell opens that campaign's workspace.
    expect(table.getByRole('link', { name: '42' })).toHaveAttribute('href', '/distribution/campaigns/42');
  });

  it('applies status and channel from the URL (dashboard links ?status=queued)', async () => {
    api.get.mockResolvedValueOnce({ data: [], total: 0 });
    renderWithAuth(<DispatchOrchestrationPage />, { ...ctx, route: '/distribution/orchestration?status=queued&channel=sms' });
    await waitFor(() =>
      expect(api.get).toHaveBeenCalledWith('v1/distribution/dispatches', {
        page: 1, per_page: 25, status: 'queued', channel: 'sms',
      }),
    );
    expect(screen.getByLabelText('Status')).toHaveValue('queued');
    expect(screen.getByLabelText('Channel')).toHaveValue('sms');
  });

  it('shows empty state on 404 instead of hard error', async () => {
    const err = new Error('Request failed with status 404');
    err.status = 404;
    api.get.mockRejectedValueOnce(err);
    renderWithAuth(<DispatchOrchestrationPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No dispatches found/i)).toBeInTheDocument());
    expect(screen.queryByText(/Request failed with status 404/i)).not.toBeInTheDocument();
  });
});
