import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));
vi.mock('../../../services/videoService', () => ({
  default: {
    pipeline: vi.fn(), pipelineItem: vi.fn(), retry: vi.fn(), status: vi.fn(),
    youtubeStatus: vi.fn(), youtubeConnect: vi.fn(), youtubeDisconnect: vi.fn(),
  },
}));
import api from '../../../services/api';
import videoService from '../../../services/videoService';
import VideoPipelinePage from '../VideoPipelinePage';
import VideoIdeaBacklogPage from '../VideoIdeaBacklogPage';
import VideoProjectListPage from '../VideoProjectListPage';
import VideoOperationsDashboardPage from '../VideoOperationsDashboardPage';

/**
 * The bot plan links /video?status=published&format=short; the backlog and
 * project lists keep their status in the URL; the ops job-queue card opens
 * the Job Monitor narrowed to the pipeline's jobs.
 */
const ctx = { auth: { user: { id: 1, email: 'a@test', role: 'super_admin' }, permissions: ['*'] } };

beforeEach(() => {
  vi.clearAllMocks();
  videoService.pipeline.mockResolvedValue({ items: [], total: 0 });
  videoService.status.mockResolvedValue({});
  videoService.youtubeStatus.mockResolvedValue({});
  api.get.mockResolvedValue({ data: [], total: 0 });
});

describe('video drill-down', () => {
  it('pipeline applies ?status= and ?format=', async () => {
    renderWithAuth(<VideoPipelinePage />, { ...ctx, route: '/video?status=published&format=short' });
    await waitFor(() => expect(videoService.pipeline).toHaveBeenCalledWith({ limit: 50, status: 'published', format: 'short' }));
    expect(screen.getByLabelText('Status')).toHaveValue('published');
    expect(screen.getByLabelText('Format')).toHaveValue('short');
    expect(await screen.findByText(/No videos match this filter/)).toBeInTheDocument();
  });

  it('pipeline sends no filter when the URL has none', async () => {
    renderWithAuth(<VideoPipelinePage />, ctx);
    await waitFor(() => expect(videoService.pipeline).toHaveBeenCalledWith({ limit: 50 }));
  });

  it('idea backlog applies ?status=', async () => {
    renderWithAuth(<VideoIdeaBacklogPage />, { ...ctx, route: '/video/ideas?status=ready' });
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('v1/video/ideas', { page: 1, per_page: 25, status: 'ready' }));
    expect(screen.getByLabelText('Filter by status')).toHaveValue('ready');
  });

  it('project list applies ?status=, including one with no preset option', async () => {
    renderWithAuth(<VideoProjectListPage />, { ...ctx, route: '/video/projects?status=render_failed' });
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(
      'v1/video/projects', expect.objectContaining({ status: 'render_failed' }),
    ));
    expect(screen.getByLabelText('Filter by status')).toHaveValue('render_failed');
  });

  it('ops job-queue card opens the pipeline jobs', async () => {
    api.get.mockResolvedValue({ status: 'ready' });
    renderWithAuth(<VideoOperationsDashboardPage />, ctx);
    expect(await screen.findByRole('link', { name: /Open these jobs/ })).toHaveAttribute(
      'href', '/admin/jobs?queue=video&job_type=reach.video_pipeline_step',
    );
    expect(screen.getByRole('link', { name: /Failed steps/ })).toHaveAttribute(
      'href', '/admin/jobs?queue=video&job_type=reach.video_pipeline_step&status=dead_letter%2Cfailed',
    );
  });
});
