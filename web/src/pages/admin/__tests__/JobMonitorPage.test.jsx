import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/jobService', () => ({
  jobService: { list: vi.fn(), retry: vi.fn(), cancel: vi.fn() },
}));
import { jobService } from '../../../services/jobService';
import { JobMonitorPage } from '../JobMonitorPage';

const ctx = { auth: { user: { id: 1, role: 'super_admin' }, permissions: ['*'] } };

beforeEach(() => jobService.list.mockReset());

describe('JobMonitorPage times', () => {
  it('shows Scheduled / Started / Completed in IST, whatever shape the API sends', async () => {
    jobService.list.mockResolvedValueOnce({
      total: 1,
      items: [{
        id: 7, job_type: 'reach.seo_tracker', queue: 'default', status: 'completed', attempts: 1, max_attempts: 5,
        scheduled_at: '2026-10-18 01:04:00',          // zone-less = UTC
        started_at: '2026-10-18 01:04:30+00',          // Postgres TIMESTAMPTZ
        completed_at: '2026-10-18T01:10:00.000Z',      // ISO
      }],
    });
    renderWithAuth(<JobMonitorPage />, ctx);
    await waitFor(() => expect(screen.getByText('reach.seo_tracker')).toBeInTheDocument());
    const cells = screen.getAllByText(/^18-10-2026 06:\d{2} IST$/).map((c) => c.textContent);
    expect(cells).toEqual(['18-10-2026 06:34 IST', '18-10-2026 06:34 IST', '18-10-2026 06:40 IST']);
  });
});

describe('JobMonitorPage deep links', () => {
  it('applies worker_id and request_id from the URL and shows them as removable chips', async () => {
    jobService.list.mockResolvedValue({ items: [], total: 0 });
    renderWithAuth(<JobMonitorPage />, { ...ctx, route: '/admin/jobs?worker_id=w-7&request_id=req-42' });

    await waitFor(() => expect(jobService.list).toHaveBeenCalledWith(
      expect.objectContaining({ worker_id: 'w-7', request_id: 'req-42' }),
    ));
    expect(screen.getByRole('button', { name: /clear worker filter/i })).toHaveTextContent('Worker w-7');
    fireEvent.click(screen.getByRole('button', { name: /clear request filter/i }));
    await waitFor(() => expect(jobService.list).toHaveBeenLastCalledWith(expect.not.objectContaining({ request_id: 'req-42' })));
  });

  it('a job type cell narrows the list to that type', async () => {
    jobService.list.mockResolvedValue({ total: 1, items: [{ id: 9, job_type: 'reach.social_inbox_poll', queue: 'social', status: 'failed', attempts: 1, max_attempts: 3 }] });
    renderWithAuth(<JobMonitorPage />, ctx);

    fireEvent.click(await screen.findByTitle('Only jobs of this type'));
    await waitFor(() => expect(jobService.list).toHaveBeenLastCalledWith(expect.objectContaining({ job_type: 'reach.social_inbox_poll', page: 1 })));
  });
});
