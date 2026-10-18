import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
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
