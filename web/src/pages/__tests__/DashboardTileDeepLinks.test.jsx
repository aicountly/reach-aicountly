import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../test/renderWithAuth';

vi.mock('../../services/jobService', () => ({
  jobService: { list: vi.fn(), retry: vi.fn(), cancel: vi.fn() },
}));
vi.mock('../../services/botService', () => ({
  botService: { reports: vi.fn() },
}));

import { jobService } from '../../services/jobService';
import { botService } from '../../services/botService';
import { JobMonitorPage } from '../admin/JobMonitorPage';
import { BotReportsPage } from '../bot/BotReportsPage';

/**
 * The dashboard's KPI cards deep-link to the rows they counted. That is only
 * worth anything if the destination actually applies the filter in the URL —
 * otherwise the card silently lands on an unfiltered list and looks like it
 * counted wrong.
 */
beforeEach(() => {
  vi.clearAllMocks();
  jobService.list.mockResolvedValue({ items: [], total: 0 });
  botService.reports.mockResolvedValue({ items: [] });
});

describe('Job Monitor deep links', () => {
  it('applies the status from the URL to the query', async () => {
    renderWithAuth(<JobMonitorPage />, { route: '/admin/jobs?status=failed' });

    await waitFor(() => expect(jobService.list).toHaveBeenCalled());
    expect(jobService.list).toHaveBeenCalledWith(
      expect.objectContaining({ status: 'failed' }),
    );
  });

  it('applies the queue name from the URL', async () => {
    renderWithAuth(<JobMonitorPage />, { route: '/admin/jobs?queue=marketing_bot' });

    await waitFor(() => expect(jobService.list).toHaveBeenCalled());
    expect(jobService.list).toHaveBeenCalledWith(
      expect.objectContaining({ queue: 'marketing_bot' }),
    );
  });

  it('lists everything when no filter is given', async () => {
    renderWithAuth(<JobMonitorPage />, { route: '/admin/jobs' });

    await waitFor(() => expect(jobService.list).toHaveBeenCalled());
    expect(jobService.list).toHaveBeenCalledWith(
      expect.objectContaining({ status: '', queue: '' }),
    );
  });
});

describe('Bot reports deep links', () => {
  it('applies approval_status from the URL', async () => {
    renderWithAuth(<BotReportsPage />, { route: '/bot/reports?approval_status=pending' });

    await waitFor(() => expect(botService.reports).toHaveBeenCalled());
    expect(botService.reports).toHaveBeenCalledWith(
      expect.objectContaining({ approval_status: 'pending' }),
    );
  });

  it('offers a way back to the unfiltered log', async () => {
    renderWithAuth(<BotReportsPage />, { route: '/bot/reports?approval_status=pending' });

    await waitFor(() => expect(botService.reports).toHaveBeenCalled());
    expect(screen.getByRole('button', { name: /clear .*pending.* filter/i })).toBeInTheDocument();
  });

  it('sends no filter when the URL carries none', async () => {
    renderWithAuth(<BotReportsPage />, { route: '/bot/reports' });

    await waitFor(() => expect(botService.reports).toHaveBeenCalled());
    expect(botService.reports).toHaveBeenCalledWith({ limit: 100 });
    expect(screen.queryByRole('button', { name: /clear/i })).not.toBeInTheDocument();
  });
});
