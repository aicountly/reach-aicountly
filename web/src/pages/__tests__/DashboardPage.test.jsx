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
  campaigns: { total: 5, running: 2, dispatches_queued: 3, dispatches_failed: 0 },
  social: { total: 9, queue: 6, posted: 3 },
  leads: { total: 20, pending_push: 7, pushed: 13 },
  approvals: { queue: 2, pending: 4, total: 9 },
  bot: { mode: 'auto', reports_total: 8, reports_pending: 2 },
  jobs: {
    available: true, window_hours: 24,
    completed: 11, failed: 1, skipped: 2, queued: 5, scheduled: 1, retrying: 1, running: 3, stalled: 0,
    oldest_queued_secs: 5400,
    queues: [{ queue: 'marketing_bot', queued: 4, running: 0, completed: 0, failed: 0, oldest_queued_secs: 7200, undrained: true, filters: 'ready=1&queue=marketing_bot' }],
    failing_types: [{ job_type: 'reach.content_overdue_escalation', failed: 1, retrying: 0, last_error: 'column ci.created_by does not exist', filters: 'status=dead_letter,failed&since_hours=24&job_type=reach.content_overdue_escalation' }],
    filters: {
      completed: 'status=completed&since_hours=24', failed: 'status=dead_letter,failed&since_hours=24',
      skipped: 'status=skipped&since_hours=24', queued: 'ready=1', scheduled: 'scheduled=1',
      retrying: 'retrying=1', running: 'running=1', stalled: 'stalled=1',
    },
  },
  calendar_overdue: { count: 1, items: [{ id: 42, title: 'Stuck scheduled post', content_type: 'blog', workflow_status: 'scheduled', date: null, reason: 'no_date' }] },
  content: { total: 30, in_review: 5, scheduled: 2, published: 6, approved: 3, drafts: 9 },
  calendar_upcoming: [
    { id: 'schedule-1', content_item_id: 77, title: 'August product launch', date: '2026-08-20', item_kind: 'blog' },
    { id: 'calendar-5', content_item_id: null, title: 'Legacy webinar', date: '2026-08-22', item_kind: 'webinar' },
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
    expect(screen.getByText('5 queued · 0 stalled')).toBeInTheDocument();
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

  // The four Bot activity tiles had no `to` prop at all, so Tile rendered a
  // plain div and clicking them did nothing. Every KPI card must lead
  // somewhere, and to the rows it actually counted.
  it('makes every KPI card clickable', async () => {
    renderWithAuth(<DashboardPage />);
    await waitFor(() => expect(screen.getByText('Blog — in progress')).toBeInTheDocument());

    const labels = [
      'Blog — in progress', 'Blog — in review', 'Blog — published',
      'Campaigns — running', 'Social — queue', 'Leads — pending push',
      'Approvals — pending', 'Bot — running jobs',
      'Content — in review', 'Content — scheduled', 'Blog — needs attention',
      'Campaigns — dispatch queue',
      'Completed (24 h)', 'Failed (24 h)', 'Queued now', 'Stalled',
    ];

    for (const label of labels) {
      const tile = screen.getByText(label).closest('a');
      expect(tile, `${label} is not a link`).not.toBeNull();
      expect(tile.getAttribute('href')).toBeTruthy();
    }
  });

  it('sends the job queue cards to the rows they counted', async () => {
    renderWithAuth(<DashboardPage />);
    await waitFor(() => expect(screen.getByText('Completed (24 h)')).toBeInTheDocument());

    const href = (label) => screen.getByText(label).closest('a').getAttribute('href');

    // 24-hour windows with the API's own filter strings — not lifetime totals
    // and not ?status=failed, a status the queue never writes.
    expect(href('Completed (24 h)')).toBe('/admin/jobs?status=completed&since_hours=24');
    expect(href('Failed (24 h)')).toBe('/admin/jobs?status=dead_letter,failed&since_hours=24');
    expect(href('Queued now')).toBe('/admin/jobs?ready=1');
    expect(href('Bot — running jobs')).toBe('/admin/jobs?running=1');
    expect(screen.getByText('reach.content_overdue_escalation').closest('a').getAttribute('href'))
      .toBe('/admin/jobs?status=dead_letter,failed&since_hours=24&job_type=reach.content_overdue_escalation');
    expect(screen.getByText('column ci.created_by does not exist')).toBeInTheDocument();
    expect(screen.getByText(/oldest waiting 1h 30m/)).toBeInTheDocument();
  });

  it('flags a queue nothing drains and explains scheduled items with no date', async () => {
    renderWithAuth(<DashboardPage />);
    await waitFor(() => expect(screen.getByText('Completed (24 h)')).toBeInTheDocument());

    expect(screen.getByText(/nothing finished on it in the last hour/)).toBeInTheDocument();
    expect(screen.getByTestId('calendar-overdue')).toHaveTextContent('1 scheduled item with no future date');
    expect(screen.getByText(/Stuck scheduled post — no publish date/)).toBeInTheDocument();
    expect(screen.getByText('Content — scheduled').closest('a').getAttribute('href'))
      .toBe('/content?workflow_status=scheduled,ready_for_publication');
  });

  it('sends the queue and review tiles to the rows they counted', async () => {
    renderWithAuth(<DashboardPage />);
    await waitFor(() => expect(screen.getByText('Social — queue')).toBeInTheDocument());

    const href = (label) => screen.getByText(label).closest('a').getAttribute('href');

    // Linked straight to the calendar (not the /social/queue redirect).
    expect(href('Social — queue')).toBe('/social/calendar?status=upcoming');
    expect(href('Approvals — pending')).toBe('/approvals?area=ready_for_approval');
    expect(href('Content — in review'))
      .toBe('/content?workflow_status=validation_pending%2Creview_pending%2Cseo_review%2Cinternal_review');
    expect(href('Campaigns — dispatch queue')).toBe('/distribution/orchestration?status=queued');
    // The tile shows what the Approval Centre lists, not per-stage rows.
    expect(screen.getByText('4 stage sign-offs open')).toBeInTheDocument();
  });

  it('opens an upcoming calendar item in Content Studio', async () => {
    renderWithAuth(<DashboardPage />);
    await waitFor(() => expect(screen.getByText('August product launch')).toBeInTheDocument());

    expect(screen.getByText('August product launch').closest('a').getAttribute('href')).toBe('/content/77');
    expect(screen.getByText('Legacy webinar').closest('a')).toBeNull();
  });

  it('does not deep-link a zero tile into an empty filtered list', async () => {
    dashboardService.summary.mockResolvedValue({
      ...summary,
      jobs: { ...summary.jobs, failed: 0, stalled: 0 },
      social: { ...summary.social, queue: 0 },
      approvals: { ...summary.approvals, queue: 0 },
      campaigns: { ...summary.campaigns, dispatches_queued: 0 },
    });
    renderWithAuth(<DashboardPage />);
    await waitFor(() => expect(screen.getByText('Failed (24 h)')).toBeInTheDocument());

    const href = (label) => screen.getByText(label).closest('a').getAttribute('href');

    expect(href('Failed (24 h)')).toBe('/admin/jobs');
    expect(href('Stalled')).toBe('/admin/jobs');
    expect(href('Social — queue')).toBe('/social/calendar');
    expect(href('Approvals — pending')).toBe('/approvals');
    expect(href('Campaigns — dispatch queue')).toBe('/distribution/orchestration');
  });
});
