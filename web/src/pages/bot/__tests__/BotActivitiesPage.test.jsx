import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

const activities = vi.fn();
const runActivity = vi.fn();
vi.mock('../../../services/botActionsService', () => ({
  newIdempotencyKey: (p) => `${p}-fixed-key`,
  botActionsService: {
    activities: (...a) => activities(...a),
    runActivity: (...a) => runActivity(...a),
  },
}));

import { BotActivitiesPage } from '../BotActivitiesPage';

const tracker = {
  key: 'seo_tracker', name: 'SEO Tracker (SERP checks)', group: 'Search, SEO & reports', kind: 'job',
  description: 'DataForSEO live SERP checks.', schedule: 'Daily 06:00 IST (00:30 UTC)', cron_utc: '30 0 * * *',
  next_run_utc: '2026-10-19T00:30:00+00:00', next_run_ist: '2026-10-19 06:00 IST',
  cost_note: 'Paid DataForSEO SERP calls (one per keyword).', safe_to_run_now: true, not_runnable_reason: null,
  last_run: { source: 'scheduled', job_id: 41, status: 'completed', queued_at: '2026-10-18T00:30:00Z', finished_at: '2026-10-18T00:31:00Z', error: null, link: '/admin/jobs/41' },
  last_manual_run: null, link: '/seo-centre/tracker', enabled: true,
};
const worker = {
  key: 'worker_main', name: 'Queue worker', group: 'Queue workers', kind: 'worker', description: 'Drains queues.',
  schedule: 'Every minute', cron_utc: '* * * * *', next_run_ist: '2026-10-18 22:31 IST', next_run_utc: '2026-10-18T17:01:00+00:00',
  safe_to_run_now: false, not_runnable_reason: 'The cron worker already runs every minute.', last_run: null, last_manual_run: null,
};

describe('BotActivitiesPage', () => {
  beforeEach(() => {
    activities.mockReset();
    runActivity.mockReset();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });
  afterEach(() => vi.restoreAllMocks());

  it('lists activities with IST + UTC schedule, cost note, last and next run', async () => {
    activities.mockResolvedValue({ activities: [tracker, worker] });
    renderWithAuth(<BotActivitiesPage />, { auth: { user: { id: 1, role: 'super_admin' }, permissions: ['*'] } });

    const row = await screen.findByTestId('activity-seo_tracker');
    expect(within(row).getByText('Daily 06:00 IST (00:30 UTC)')).toBeInTheDocument();
    expect(within(row).getByText(/Paid DataForSEO SERP calls/)).toBeInTheDocument();
    expect(within(row).getByText('2026-10-19 06:00 IST')).toBeInTheDocument();
    expect(within(row).getByText('COMPLETED')).toBeInTheDocument();
    expect(within(row).getByRole('link', { name: 'details' })).toHaveAttribute('href', '/admin/jobs/41');

    const workerRow = screen.getByTestId('activity-worker_main');
    expect(within(workerRow).getByRole('button', { name: /Run now/ })).toBeDisabled();
    expect(within(workerRow).getByText(/already runs every minute/)).toBeInTheDocument();
  });

  it('Run now confirms the cost, sends an idempotency key and shows the queued job', async () => {
    activities.mockResolvedValue({ activities: [tracker] });
    runActivity.mockResolvedValue({ replayed: false, run: { status: 'queued', message: 'Queued reach.seo_tracker on the default queue (job #52).', job: { id: 52, status: 'pending', link: '/admin/jobs/52' } } });
    const user = userEvent.setup();
    renderWithAuth(<BotActivitiesPage />, { auth: { user: { id: 1, role: 'super_admin' }, permissions: ['bot.run'] } });

    const row = await screen.findByTestId('activity-seo_tracker');
    await user.click(within(row).getByRole('button', { name: /Run now/ }));

    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('Paid DataForSEO'));
    expect(runActivity).toHaveBeenCalledWith('seo_tracker', 'run-seo_tracker-fixed-key');
    expect(await screen.findByText(/job #52/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open result' })).toHaveAttribute('href', '/admin/jobs/52');
    await waitFor(() => expect(activities).toHaveBeenCalledTimes(2));
  });

  it('shows a refusal with its retry time and hides Run now without permission', async () => {
    activities.mockResolvedValue({ activities: [tracker] });
    const err = Object.assign(new Error('This activity was run by hand 10 s ago; wait 50 s before running it again.'), { status: 429, retryAfter: 50 });
    runActivity.mockRejectedValue(err);
    const user = userEvent.setup();
    const { unmount } = renderWithAuth(<BotActivitiesPage />, { auth: { user: { id: 1 }, permissions: ['bot.run'] } });
    await user.click(within(await screen.findByTestId('activity-seo_tracker')).getByRole('button', { name: /Run now/ }));
    expect(await screen.findByText(/try again in 50 s/)).toBeInTheDocument();
    unmount();

    renderWithAuth(<BotActivitiesPage />, { auth: { user: { id: 2 }, permissions: ['bot.view'] } });
    const row = await screen.findByTestId('activity-seo_tracker');
    expect(within(row).queryByRole('button', { name: /Run now/ })).not.toBeInTheDocument();
    expect(screen.getByText(/needs the bot.run permission/)).toBeInTheDocument();
  });
});
