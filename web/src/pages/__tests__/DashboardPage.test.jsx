import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../test/renderWithAuth';

vi.mock('../../services/dashboardService', () => ({
  dashboardService: { summary: vi.fn(), counts: vi.fn() },
}));

import { dashboardService } from '../../services/dashboardService';
import { DashboardPage } from '../DashboardPage';

const summary = {
  blog: {
    total: 12, ideas: 2, drafts: 3, in_review: 4, approved: 1,
    scheduled: 1, published: 2, pending_publishing: 1, needs_attention: 0,
  },
  campaigns: { total: 5, running: 2, dispatches_failed: 0 },
  social: { total: 9, queue: 6, posted: 3 },
  leads: { total: 20, pending_push: 7, pushed: 13 },
  approvals: { pending: 4, total: 9 },
  bot: {
    reports_total: 8, reports_pending: 2, queue_running: 3,
    queue_queued: 5, queue_completed: 11, queue_failed: 1,
  },
  content: { total: 30, in_review: 5, scheduled: 2, published: 6, approved: 3, drafts: 9 },
  calendar_upcoming: [
    { id: 'schedule-1', title: 'August product launch', date: '2026-08-20', item_kind: 'blog' },
  ],
  generated_at: '2026-08-06T10:30:00+00:00',
};

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  dashboardService.summary.mockResolvedValue(summary);
});

afterEach(() => {
  vi.useRealTimers();
  vi.clearAllMocks();
});

describe('DashboardPage', () => {
  it('renders the real figures returned by the API', async () => {
    renderWithAuth(<DashboardPage />);

    // Blog in-progress = ideas + drafts + in_review.
    await waitFor(() => expect(screen.getByText('Blog — in progress')).toBeInTheDocument());
    expect(screen.getByText('12 total · 3 drafts')).toBeInTheDocument();
    expect(screen.getByText('5 queued · 8 reports')).toBeInTheDocument();
    expect(screen.getByText('August product launch')).toBeInTheDocument();
    // dd-mm-yyyy, per the house date format.
    expect(screen.getByText(/20-08-2026/)).toBeInTheDocument();
  });

  it('polls for fresh values instead of only reading once', async () => {
    renderWithAuth(<DashboardPage />);
    await waitFor(() => expect(dashboardService.summary).toHaveBeenCalledTimes(1));

    await act(async () => { await vi.advanceTimersByTimeAsync(45_000); });
    await waitFor(() => expect(dashboardService.summary).toHaveBeenCalledTimes(2));
  });

  it('keeps the last good figures when a refresh fails', async () => {
    renderWithAuth(<DashboardPage />);
    await waitFor(() => expect(screen.getByText('12 total · 3 drafts')).toBeInTheDocument());

    dashboardService.summary.mockRejectedValueOnce(new Error('network down'));
    await act(async () => { await vi.advanceTimersByTimeAsync(45_000); });

    await waitFor(() => expect(screen.getByText(/Live refresh failed/)).toBeInTheDocument());
    expect(screen.getByText('12 total · 3 drafts')).toBeInTheDocument();
  });

  it('refreshes on demand', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderWithAuth(<DashboardPage />);
    await waitFor(() => expect(dashboardService.summary).toHaveBeenCalledTimes(1));

    await user.click(screen.getByRole('button', { name: /refresh/i }));
    await waitFor(() => expect(dashboardService.summary).toHaveBeenCalledTimes(2));
  });

  it('surfaces the error when the first read fails', async () => {
    dashboardService.summary.mockRejectedValue(new Error('boom'));
    renderWithAuth(<DashboardPage />);

    await waitFor(() => expect(screen.getByText('boom')).toBeInTheDocument());
  });
});
