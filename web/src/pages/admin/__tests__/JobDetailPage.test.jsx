import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { Route, Routes } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/jobService', () => ({
  jobService: { list: vi.fn(), get: vi.fn(), retry: vi.fn(), cancel: vi.fn() },
}));
import { jobService } from '../../../services/jobService';
import { JobDetailPage } from '../JobDetailPage';
import { JobMonitorPage } from '../JobMonitorPage';

const admin = { auth: { user: { id: 1, role: 'super_admin' }, permissions: ['*'] } };
const viewer = { auth: { user: { id: 2, role: 'analyst' }, permissions: ['job.view'] } };

const job = {
  id: 41, job_type: 'reach.seo_tracker', queue: 'default', status: 'failed', priority: 0, attempts: 2, max_attempts: 5,
  error_message: 'HTTP 402 balance', request_id: 'req-9', worker_id: 'w-1', created_at: '2026-10-18T01:00:00Z',
  payload_summary: { keys: ['site'], hash: 'abc123' },
  history: [{ id: 1, action: 'job.retried', entity_type: 'job', entity_id: 41, old_value: { status: 'failed' }, new_value: { status: 'pending' }, created_at: '2026-10-18T02:00:00Z' }],
  siblings: [{ id: 40, job_type: 'reach.blog_publish', queue: 'blog', status: 'completed', attempts: 1, max_attempts: 5 }],
};

const renderDetail = (ctx, route = '/admin/jobs/41') => renderWithAuth(
  <Routes>
    <Route path="/admin/jobs/:id" element={<JobDetailPage />} />
    <Route path="/admin/jobs" element={<p>monitor</p>} />
  </Routes>,
  { ...ctx, route },
);

beforeEach(() => {
  vi.clearAllMocks();
  jobService.get.mockResolvedValue(job);
});

describe('JobDetailPage', () => {
  it('shows the job, its error, history and request siblings; links narrow the monitor', async () => {
    renderDetail(admin);
    expect(await screen.findByText('HTTP 402 balance')).toBeInTheDocument();
    expect(jobService.get).toHaveBeenCalledWith('41', undefined);
    expect(screen.getByRole('link', { name: 'req-9' })).toHaveAttribute('href', '/admin/jobs?request_id=req-9');
    expect(screen.getByRole('link', { name: 'w-1' })).toHaveAttribute('href', '/admin/jobs?worker_id=w-1');
    expect(screen.getByText('failed → pending')).toBeInTheDocument();
    expect(screen.getByRole('row', { name: 'Open job 40' })).toBeInTheDocument();
  });

  it('a sibling row opens that job', async () => {
    renderDetail(admin);
    fireEvent.click(await screen.findByRole('row', { name: 'Open job 40' }));
    await waitFor(() => expect(jobService.get).toHaveBeenLastCalledWith('40', undefined));
  });

  it('retry uses the job API; super admin can load the full payload', async () => {
    jobService.retry.mockResolvedValue({});
    renderDetail(admin);
    fireEvent.click(await screen.findByRole('button', { name: /Retry/ }));
    await waitFor(() => expect(jobService.retry).toHaveBeenCalledWith(41));
    fireEvent.click(screen.getByRole('button', { name: 'Show full payload' }));
    await waitFor(() => expect(jobService.get).toHaveBeenLastCalledWith('41', { include: 'payload' }));
  });

  it('without job.retry / job.cancel there are no actions and no payload button', async () => {
    renderDetail(viewer);
    await screen.findByText('HTTP 402 balance');
    expect(screen.queryByRole('button', { name: /Retry/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Cancel/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Show full payload' })).not.toBeInTheDocument();
  });
});

describe('JobMonitorPage rows', () => {
  it('a row opens the job detail; the retry button does not', async () => {
    jobService.list.mockResolvedValue({ total: 1, items: [{ ...job, siblings: undefined, history: undefined }] });
    jobService.retry.mockResolvedValue({});
    renderWithAuth(
      <Routes>
        <Route path="/admin/jobs" element={<JobMonitorPage />} />
        <Route path="/admin/jobs/:id" element={<p>job detail</p>} />
      </Routes>,
      { ...admin, route: '/admin/jobs' },
    );
    fireEvent.click(await screen.findByRole('button', { name: /Retry/ }));
    await waitFor(() => expect(jobService.retry).toHaveBeenCalledWith(41));
    expect(screen.queryByText('job detail')).not.toBeInTheDocument();

    fireEvent.click(await screen.findByRole('row', { name: 'Open job 41' }));
    expect(await screen.findByText('job detail')).toBeInTheDocument();
  });
});
